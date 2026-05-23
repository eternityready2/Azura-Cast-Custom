<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\ScheduleConflictChecker;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;
use Doctrine\ORM\AbstractQuery;
use Mockery;
use Monolog\Logger;

class ScheduleConflictCheckerTest extends Unit
{
    private ScheduleConflictChecker $checker;

    private Station $station;

    protected function _before(): void
    {
        /** @var Station $station */
        $station = Mockery::mock(Station::class);
        $station->shouldReceive('getTimezoneObject')->andReturn(new DateTimeZone('UTC'));
        $this->station = $station;

        $this->checker = new ScheduleConflictChecker();
        $this->checker->setLogger(new Logger('test'));
    }

    public function testNoConflictsWhenNoOtherSchedulesExist(): void
    {
        $proposed = $this->buildPlaylistSchedule(1000, 1200, [1, 2, 3, 4, 5]);
        $this->stubLoadedSchedules([]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertSame([], $conflicts);
    }

    public function testNoConflictsWhenWindowsDoNotOverlap(): void
    {
        $proposed = $this->buildPlaylistSchedule(800,  1000, [1]);
        $existing = $this->buildPlaylistSchedule(1100, 1300, [1]);

        $this->stubLoadedSchedules([$existing]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertSame([], $conflicts);
    }

    public function testDetectsOverlapOnSharedDay(): void
    {
        $proposed = $this->buildPlaylistSchedule(900,  1100, [1, 2]);
        $existing = $this->buildPlaylistSchedule(1000, 1200, [2, 3]);

        $this->stubLoadedSchedules([$existing]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertCount(1, $conflicts);
        self::assertSame($existing, $conflicts[0]);
    }

    public function testNoConflictWhenDaysAreDisjoint(): void
    {
        $proposed = $this->buildPlaylistSchedule(900,  1100, [1, 2]);
        $existing = $this->buildPlaylistSchedule(1000, 1200, [3, 4]);

        $this->stubLoadedSchedules([$existing]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertSame([], $conflicts);
    }

    public function testIgnoresSelfWhenProposedAlreadyHasId(): void
    {
        $proposed = $this->buildPlaylistSchedule(900, 1100, [1]);
        $this->setId($proposed, 42);

        $duplicateOfSelf = $this->buildPlaylistSchedule(900, 1100, [1]);
        $this->setId($duplicateOfSelf, 42);

        $this->stubLoadedSchedules([$duplicateOfSelf]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertSame([], $conflicts);
    }

    public function testExcludesSchedulesBelongingToProvidedPlaylistRelation(): void
    {
        $playlist = $this->buildPlaylist();
        $this->setId($playlist, 7);

        $proposed = $this->buildScheduleForPlaylist($playlist, 900, 1100, [1]);
        $sibling  = $this->buildScheduleForPlaylist($playlist, 1000, 1200, [1]);

        $this->stubLoadedSchedules([$sibling]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, $playlist, $this->fixedNow());

        self::assertSame(
            [],
            $conflicts,
            'Sibling schedules on the same playlist must be ignored so editing an existing playlist does not flag itself.'
        );
    }

    public function testReportsOverlapOnEveryWeekdayWhenDaysListIsEmpty(): void
    {
        $proposed = $this->buildPlaylistSchedule(900, 1100, []);
        $existing = $this->buildPlaylistSchedule(1000, 1200, [4]);

        $this->stubLoadedSchedules([$existing]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertCount(1, $conflicts);
    }

    public function testOvernightWindowOverlapsNextDayMorning(): void
    {
        $proposed = $this->buildPlaylistSchedule(2200, 200, [5]);
        $existing = $this->buildPlaylistSchedule(100,  300, [6]);

        $this->stubLoadedSchedules([$existing]);

        $conflicts = $this->checker->findConflicts($this->station, $proposed, null, $this->fixedNow());

        self::assertCount(
            1,
            $conflicts,
            'Friday 22:00-02:00 must conflict with Saturday 01:00-03:00 because the overnight window crosses midnight.'
        );
    }

    public function testIsNonWheelScheduleActiveAtReturnsTrueWhenPlaylistRunning(): void
    {
        $playlistSchedule = $this->buildPlaylistSchedule(900, 1100, [4]);

        $this->stubLoadedSchedules([$playlistSchedule]);

        $thursdayAtTen = CarbonImmutable::create(2026, 5, 21, 10, 0, 0, new DateTimeZone('UTC'));

        self::assertTrue(
            $this->checker->isNonWheelScheduleActiveAt($this->station, $thursdayAtTen)
        );
    }

    public function testIsNonWheelScheduleActiveAtReturnsFalseWhenOnlyClockWheelSchedule(): void
    {
        $wheel = new StationClockWheel($this->station);
        $wheelSchedule = new StationSchedule($wheel);
        $wheelSchedule->start_time = 900;
        $wheelSchedule->end_time   = 1100;
        $wheelSchedule->days       = [4];

        $this->stubLoadedSchedules([$wheelSchedule]);

        $thursdayAtTen = CarbonImmutable::create(2026, 5, 21, 10, 0, 0, new DateTimeZone('UTC'));

        self::assertFalse(
            $this->checker->isNonWheelScheduleActiveAt($this->station, $thursdayAtTen)
        );
    }

    /**
     * @param StationSchedule[] $schedules
     */
    private function stubLoadedSchedules(array $schedules): void
    {
        $query = Mockery::mock(AbstractQuery::class);
        $query->shouldReceive('setParameter')->andReturnSelf();
        $query->shouldReceive('getResult')->andReturn($schedules);

        $em = Mockery::mock(ReloadableEntityManagerInterface::class);
        $em->shouldReceive('createQuery')->andReturn($query);

        $this->checker->setEntityManager($em);
    }

    /**
     * @param int[] $days
     */
    private function buildPlaylistSchedule(int $startTime, int $endTime, array $days): StationSchedule
    {
        $playlist = $this->buildPlaylist();
        return $this->buildScheduleForPlaylist($playlist, $startTime, $endTime, $days);
    }

    /**
     * @param int[] $days
     */
    private function buildScheduleForPlaylist(
        StationPlaylist $playlist,
        int $startTime,
        int $endTime,
        array $days
    ): StationSchedule {
        $schedule = new StationSchedule($playlist);
        $schedule->start_time = $startTime;
        $schedule->end_time   = $endTime;
        $schedule->days       = $days;
        return $schedule;
    }

    private function buildPlaylist(): StationPlaylist
    {
        return new StationPlaylist($this->station);
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    private function fixedNow(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 5, 22, 9, 0, 0, new DateTimeZone('UTC'));
    }
}
