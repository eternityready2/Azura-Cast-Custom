<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\Song;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ClockWheelPlaybackPlannerTest extends Unit
{
    private ClockWheelPlaybackPlanner $planner;

    private Station $station;

    private Module $testsModule;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
    }

    protected function _before(): void
    {
        $this->station = $this->persistStation($this->testsModule->em);
        $this->planner = $this->makePlanner();
    }

    protected function _after(): void
    {
        $this->removeStation($this->testsModule->em, $this->station);
    }

    public function testResolveSlotMediaQueryUsesStationStorageLocation(): void
    {
        $capturedDql = null;
        $query = $this->createMock(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('getResult')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturnCallback(
            static function (string $dql) use (&$capturedDql, $query): Query {
                $capturedDql = $dql;

                return $query;
            }
        );

        $planner = $this->makePlanner($em);

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
        $media->updateMetaFields();
        $this->setEntityId($media, 42);

        $query = $this->createMock(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('getResult')->willReturn([$media]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);
        $em->method('find')->with(StationMedia::class, 42)->willReturn($media);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(StationQueue::class));

        $planner = $this->makePlanner($em);

        $wheel = new StationClockWheel($this->station);
        $slot = new StationClockWheelSlot($wheel);
        $slot->type = ClockWheelSlotTypes::Id;
        $slot->position_seconds = 0;

        $queueEntry = $this->invokeResolveSlot($planner, $slot, 300);

        self::assertInstanceOf(StationQueue::class, $queueEntry);
        self::assertSame($wheel, $queueEntry->clock_wheel);
        self::assertSame($media, $queueEntry->media);
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
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $queued = new StationQueue($station, Song::createFromText('Artist - Test'));
        $queued->timestamp_played = CarbonImmutable::parse('2026-05-26 09:50:00', 'UTC');
        $queued->duration = 600.0;
        $queued->sent_to_autodj = false;
        $queued->is_played = false;
        $queued->timestamp_cued = CarbonImmutable::parse('2026-05-26 09:49:00', 'UTC');
        $em->persist($queued);
        $em->flush();

        $planner = $this->makePlanner();

        try {
            $expected = CarbonImmutable::parse('2026-05-26 09:55:00', 'UTC');
            $seconds = $this->invokePlannedSecondsOn($planner, $station, $expected);

            // Queued item ends at 09:50 + 600s = 10:00 → 3600s into hour, capped at 3599.
            self::assertSame(self::hourSeconds() - 1, $seconds);
        } finally {
            $em->remove($queued);
            $this->removeStation($em, $station);
        }
    }

    public function testShortFormSlotUsesShortestCandidateWhenWindowIsTooSmall(): void
    {
        $long = $this->makeMedia(1, 240.0);
        $short = $this->makeMedia(2, 15.0);

        $filtered = $this->invokeFilterByDuration(
            [$long, $short],
            18.0,
            ClockWheelSlotTypes::Promo,
            strictSchedule: false,
        );

        self::assertCount(1, $filtered);
        self::assertSame(2, $filtered[0]->id);
    }

    public function testShortFormSlotReturnsEmptyUnderStrictScheduleWhenNothingFits(): void
    {
        $media = $this->makeMedia(1, 240.0);

        $filtered = $this->invokeFilterByDuration(
            [$media],
            18.0,
            ClockWheelSlotTypes::Id,
            strictSchedule: true,
        );

        self::assertSame([], $filtered);
    }

    public function testShouldEnforceCapForStrictSchedule(): void
    {
        $slot = $this->makeSlot(ClockWheelSlotTypes::Music);
        $media = $this->makeMedia(1, 180.0);

        self::assertTrue(
            $this->invokeShouldEnforcePlaybackCap(
                $slot,
                ClockWheelScheduleMode::Strict,
                $media,
                300.0,
            )
        );
    }

    public function testShouldEnforceCapForShortFormSlot(): void
    {
        $slot = $this->makeSlot(ClockWheelSlotTypes::Promo);
        $media = $this->makeMedia(1, 20.0);

        self::assertTrue(
            $this->invokeShouldEnforcePlaybackCap(
                $slot,
                ClockWheelScheduleMode::Flexible,
                $media,
                300.0,
            )
        );
    }

    public function testShouldNotEnforceCapWhenFlexibleMusicFits(): void
    {
        $slot = $this->makeSlot(ClockWheelSlotTypes::Music);
        $media = $this->makeMedia(1, 200.0);

        self::assertFalse(
            $this->invokeShouldEnforcePlaybackCap(
                $slot,
                ClockWheelScheduleMode::Flexible,
                $media,
                300.0,
            )
        );
    }

    public function testShouldEnforceCapWhenFlexibleMusicOverflows(): void
    {
        $slot = $this->makeSlot(ClockWheelSlotTypes::Music);
        $media = $this->makeMedia(1, 400.0);

        self::assertTrue(
            $this->invokeShouldEnforcePlaybackCap(
                $slot,
                ClockWheelScheduleMode::Flexible,
                $media,
                300.0,
            )
        );
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
        return $this->invokePlannedSecondsOn($this->planner, $this->station, $expectedPlayTime);
    }

    private function invokePlannedSecondsOn(
        ClockWheelPlaybackPlanner $planner,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): int {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'getPlannedSecondsIntoHour');
        $result = $method->invoke(
            $planner,
            $station,
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
        $wheel = $slot->clock_wheel;
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'resolveSlot');

        return $method->invoke(
            $planner,
            $wheel,
            $slot,
            [],
            $availableSeconds,
            ClockWheelScheduleMode::Flexible,
            $this->station,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $slot->position_seconds,
        );
    }

    private function makePlanner(?EntityManagerInterface $em = null): ClockWheelPlaybackPlanner
    {
        return new ClockWheelPlaybackPlanner(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $this->testsModule->container->get(StationQueueRepository::class),
            $this->testsModule->container->get(DuplicatePrevention::class),
            $this->createMock(ClockWheelEventLogger::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param StationMedia[] $candidates
     *
     * @return StationMedia[]
     */
    private function invokeFilterByDuration(
        array $candidates,
        float $maxDuration,
        ClockWheelSlotTypes $type,
        bool $strictSchedule,
    ): array {
        $wheel = new StationClockWheel($this->station);
        $slot = new StationClockWheelSlot($wheel);
        $slot->type = $type;

        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'filterByDuration');

        return $method->invoke($this->planner, $candidates, $maxDuration, $slot, $strictSchedule);
    }

    private function invokeShouldEnforcePlaybackCap(
        StationClockWheelSlot $slot,
        ClockWheelScheduleMode $scheduleMode,
        StationMedia $media,
        float $maxDuration,
    ): bool {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'shouldEnforcePlaybackCap');

        return (bool)$method->invoke($this->planner, $slot, $scheduleMode, $media, $maxDuration);
    }

    private function makeSlot(ClockWheelSlotTypes $type): StationClockWheelSlot
    {
        $wheel = new StationClockWheel($this->station);
        $slot = new StationClockWheelSlot($wheel);
        $slot->type = $type;

        return $slot;
    }

    private function makeMedia(int $id, float $length): StationMedia
    {
        $media = new StationMedia($this->station->media_storage_location, '/track_' . $id . '.mp3');
        $media->title = 'Track ' . $id;
        $media->artist = 'Artist';
        $media->type = 'music';
        $media->length = $length;
        $media->mtime = time();
        $media->uploaded_at = time();
        $this->setEntityId($media, $id);

        return $media;
    }

    private function persistStation(ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Planner DB Test';
        $station->short_name = 'planner_db_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->flush();

        return $station;
    }

    private function removeStation(ReloadableEntityManagerInterface $em, Station $station): void
    {
        $em->createQuery('DELETE FROM App\Entity\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $em->remove($station);
        $em->remove($station->media_storage_location);
        $em->remove($station->recordings_storage_location);
        $em->remove($station->podcasts_storage_location);
        $em->flush();
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
