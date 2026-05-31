<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\Song;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\DuplicatePrevention;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
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
        $this->station->createMediaStorageLocation();

        $this->queueRepo = $this->createMock(StationQueueRepository::class);
        $this->planner = $this->makePlanner();
    }

    public function testResolveSlotMediaQueryUsesStationStorageLocation(): void
    {
        $capturedDql = null;
        $query = $this->createMock(AbstractQuery::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('getResult')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturnCallback(
            static function (string $dql) use (&$capturedDql, $query): Query {
                $capturedDql = $dql;

                return $query;
            }
        );

        $planner = new ClockWheelPlaybackPlanner(
            $em,
            $this->queueRepo,
            $this->createMock(DuplicatePrevention::class),
            $this->createMock(LoggerInterface::class),
        );

        $wheel = new StationClockWheel($this->station);
        $slot = new StationClockWheelSlot($wheel);
        $slot->type = ClockWheelSlotTypes::Id;
        $slot->position_seconds = 0;

        $this->invokeResolveSlot($planner, $slot, 300);

        self::assertNotNull($capturedDql);
        self::assertStringContainsString('m.storage_location = :storageLocation', $capturedDql);
        self::assertStringNotContainsString('sl.stations', $capturedDql);
    }

    public function testResolveSlotReturnsQueueEntryWhenMediaMatchesType(): void
    {
        $storageLocation = $this->station->media_storage_location;
        $media = new StationMedia($storageLocation, '/test/id_sweep.mp3');
        $media->title = 'Station ID';
        $media->artist = 'Test';
        $media->type = 'id';
        $media->length = 20.0;
        $media->mtime = time();
        $media->uploaded_at = time();
        $this->setEntityId($media, 42);

        $query = $this->createMock(AbstractQuery::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('getResult')->willReturn([$media]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);
        $em->method('find')->with(StationMedia::class, 42)->willReturn($media);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(StationQueue::class));

        $duplicatePrevention = $this->createMock(DuplicatePrevention::class);
        $duplicatePrevention->method('preventDuplicates')->willReturnCallback(
            static function (array $eligibleTracks): ?StationPlaylistQueue {
                return $eligibleTracks[0] ?? null;
            }
        );

        $planner = new ClockWheelPlaybackPlanner(
            $em,
            $this->queueRepo,
            $duplicatePrevention,
            $this->createMock(LoggerInterface::class),
        );

        $wheel = new StationClockWheel($this->station);
        $slot = new StationClockWheelSlot($wheel);
        $slot->type = ClockWheelSlotTypes::Id;
        $slot->position_seconds = 0;

        $queueEntry = $this->invokeResolveSlot($planner, $slot, 300);

        self::assertInstanceOf(StationQueue::class, $queueEntry);
        self::assertSame($wheel, $queueEntry->clock_wheel);
        self::assertSame(42, $queueEntry->media_id);
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

    private function invokeResolveSlot(
        ClockWheelPlaybackPlanner $planner,
        StationClockWheelSlot $slot,
        int $availableSeconds,
    ): ?StationQueue {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'resolveSlot');

        return $method->invoke(
            $planner,
            $slot,
            [],
            $availableSeconds,
            ClockWheelScheduleMode::Flexible,
            $this->station,
        );
    }

    private function makePlanner(): ClockWheelPlaybackPlanner
    {
        return new ClockWheelPlaybackPlanner(
            $this->createMock(EntityManagerInterface::class),
            $this->queueRepo,
            $this->createMock(DuplicatePrevention::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    private static function hourSeconds(): int
    {
        return 3600;
    }
}
