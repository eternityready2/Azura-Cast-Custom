<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\Song;
use App\Entity\StationQueue;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\DuplicatePrevention;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ClockWheelPlaybackPlannerTest extends Unit
{
    private ClockWheelPlaybackPlanner $planner;

    private Station $station;

    /** @var StationQueueRepository&MockObject */
    private StationQueueRepository $queueRepo;

    protected function _before(): void
    {
        $this->station = new Station();
        $this->station->name = 'Planner Test';
        $this->station->short_name = 'planner_test';
        $this->station->timezone = 'UTC';

        $this->queueRepo = $this->createMock(StationQueueRepository::class);

        $this->planner = new ClockWheelPlaybackPlanner(
            $this->createMock(EntityManagerInterface::class),
            $this->queueRepo,
            $this->createMock(DuplicatePrevention::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testPlannedSecondsUsesMinuteAndSecondNotHourOfDay(): void
    {
        $expected = CarbonImmutable::parse('2026-05-26 10:30:15', 'UTC');
        $seconds = $this->invokePlannedSeconds($expected);

        // 30:15 into the hour — not 10*3600 + 30*60 (seconds since midnight).
        self::assertSame(30 * 60 + 15, $seconds);
    }

    public function testPlannedSecondsAdvancesPastQueuedItemsInSameHour(): void
    {
        $expected = CarbonImmutable::parse('2026-05-26 09:55:00', 'UTC');

        $queued = new StationQueue($this->station, Song::createFromText('Artist - Test'));
        $queued->timestamp_played = CarbonImmutable::parse('2026-05-26 09:50:00', 'UTC');
        $queued->duration = 600.0;

        $this->queueRepo->method('getUnplayedQueue')->willReturn([$queued]);

        $seconds = $this->invokePlannedSeconds($expected);

        // Queued item ends at 09:50 + 600s = 10:00 → 3600s into hour, capped at 3599.
        self::assertSame(self::hourSeconds() - 1, $seconds);
    }

    public function testActiveSlotSelectionUsesPositionWithinHour(): void
    {
        $wheel = new StationClockWheel($this->station);
        $slotTop = new StationClockWheelSlot($wheel);
        $slotTop->position_seconds = 0;
        $slotTop->type = ClockWheelSlotTypes::Id;

        $slotMid = new StationClockWheelSlot($wheel);
        $slotMid->position_seconds = 20 * 60;
        $slotMid->type = ClockWheelSlotTypes::Ad;

        $slots = [$slotTop, $slotMid];
        $expected = CarbonImmutable::parse('2026-05-26 10:25:00', 'UTC');
        $seconds = $this->invokePlannedSeconds($expected);

        $activeIndex = $this->invokeActiveSlotIndex($slots, $seconds);

        self::assertSame(1, $activeIndex);
        self::assertSame(20 * 60, $slots[$activeIndex]->position_seconds);
    }

    private function invokePlannedSeconds(DateTimeImmutable $expectedPlayTime): int
    {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'getPlannedSecondsIntoHour');
        $result = $method->invoke(
            $this->planner,
            $this->station,
            $expectedPlayTime,
            new DateTimeZone('UTC')
        );

        return (int)$result;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function invokeActiveSlotIndex(array $slots, int $secondsIntoHour): int
    {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'getActiveSlotIndex');

        return (int)$method->invoke($this->planner, $slots, $secondsIntoHour);
    }

    private static function hourSeconds(): int
    {
        return 3600;
    }
}
