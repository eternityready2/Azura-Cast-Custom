<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Exception\ValidationException;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use ReflectionProperty;

/**
 * Step 2: conflict checker coverage for weekly/monthly recurrence, overnight, and boundaries.
 */
final class ScheduleConflictCheckerTest extends Unit
{
    private ScheduleConflictChecker $checker;

    private Scheduler $scheduler;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $testsModule->container->get(Scheduler::class);

        $this->station = new Station();
        $this->station->name = 'Conflict Test Station';
        $this->station->short_name = 'conflict_test';
        $this->station->timezone = 'UTC';
    }

    public function testWeeklySchedulesOnDifferentDaysDoNotConflict(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->weeklyItem(900, 1700, $range, [1]),
            $this->weeklyItem(900, 1700, $range, [2]),
        ]);

        self::assertTrue(true);
    }

    public function testWeeklyOverlappingTimesInBatchAreRejected(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('overlap');

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->weeklyItem(900, 1200, $range, [1, 2, 3, 4, 5]),
            $this->weeklyItem(1100, 1700, $range, [1, 2, 3, 4, 5]),
        ]);
    }

    public function testAdjacentBoundariesDoNotConflict(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->weeklyItem(900, 1000, $range, [1, 2, 3, 4, 5, 6, 7]),
            $this->weeklyItem(1000, 1100, $range, [1, 2, 3, 4, 5, 6, 7]),
        ]);

        self::assertTrue(true);
    }

    public function testOvernightSchedulesOverlap(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->expectException(ValidationException::class);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->weeklyItem(2200, 600, $range, [1, 2, 3, 4, 5, 6, 7]),
            $this->weeklyItem(2300, 700, $range, [1, 2, 3, 4, 5, 6, 7]),
        ]);
    }

    public function testMonthlyDatePatternOverlap(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->expectException(ValidationException::class);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->monthlyDateItem(900, 1200, $range, 15),
            $this->monthlyDateItem(1100, 1700, $range, 15),
        ]);
    }

    public function testMonthlyDatePatternNonOverlappingTimesDoNotConflict(): void
    {
        $wheel = $this->makeClockWheel(1);
        $range = $this->dateRangeForWindow();

        $this->checker = $this->makeChecker([]);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->monthlyDateItem(900, 1000, $range, 15),
            $this->monthlyDateItem(1100, 1200, $range, 15),
        ]);

        self::assertTrue(true);
    }

    public function testPlayOnceSameStartAndEndTimeOverlaps(): void
    {
        $wheel = $this->makeClockWheel(1);
        $day = CarbonImmutable::now('UTC')->addDays(3);
        $date = $day->format('Y-m-d');
        $dayOfWeek = $day->dayOfWeekIso;

        $this->checker = $this->makeChecker([]);

        $this->expectException(ValidationException::class);

        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->playOnceItem(1000, 1000, $date, $dayOfWeek),
            $this->playOnceItem(1000, 1000, $date, $dayOfWeek),
        ]);
    }

    public function testExistingClockWheelScheduleBlocksNewWheel(): void
    {
        $wheelA = $this->makeClockWheel(1);
        $wheelB = $this->makeClockWheel(2);
        $range = $this->dateRangeForWindow();

        $existing = new StationSchedule($wheelA);
        $existing->start_time = 900;
        $existing->end_time = 1700;
        $existing->start_date = $range['start_date'];
        $existing->end_date = $range['end_date'];
        $existing->days = [1, 2, 3, 4, 5];
        $existing->recurrence_type = null;

        $this->checker = $this->makeChecker([$existing]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('conflict');

        $this->checker->assertBatchHasNoConflicts(
            $wheelB->station,
            $wheelB,
            [$this->weeklyItem(1000, 1800, $range, [1, 2, 3, 4, 5])]
        );
    }

    public function testExistingPlaylistBlocksClockWheel(): void
    {
        $playlist = $this->makePlaylist(10);
        $wheel = $this->makeClockWheel(2);
        $range = $this->dateRangeForWindow();

        $existing = new StationSchedule($playlist);
        $existing->start_time = 900;
        $existing->end_time = 1700;
        $existing->start_date = $range['start_date'];
        $existing->end_date = $range['end_date'];
        $existing->days = [1, 2, 3, 4, 5];

        $this->checker = $this->makeChecker([$existing]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('playlist');

        $this->checker->assertBatchHasNoConflicts(
            $wheel->station,
            $wheel,
            [$this->weeklyItem(1000, 1800, $range, [1, 2, 3, 4, 5])]
        );
    }

    public function testBiweeklySchedulesOnAlternatingWeeksMayNotConflict(): void
    {
        $wheel = $this->makeClockWheel(1);
        $anchor = CarbonImmutable::now('UTC')->startOf('week'); // Monday
        $range = [
            'start_date' => $anchor->format('Y-m-d'),
            'end_date' => $anchor->addWeeks(8)->format('Y-m-d'),
        ];

        $this->checker = $this->makeChecker([]);

        // Same weekday/time but anchors one week apart with 2-week interval → staggered occurrences.
        $this->checker->assertBatchHasNoConflicts($wheel->station, $wheel, [
            $this->biweeklyItem(900, 1000, $range, [1], $anchor->format('Y-m-d')),
            $this->biweeklyItem(
                900,
                1000,
                $range,
                [1],
                $anchor->addWeek()->format('Y-m-d')
            ),
        ]);

        self::assertTrue(true);
    }

    /**
     * @param StationSchedule[] $existing
     */
    private function makeChecker(array $existing): ScheduleConflictChecker
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('execute')->willReturn($existing);

        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        return new ScheduleConflictChecker($em, $this->scheduler);
    }

    private function makeClockWheel(int $id): StationClockWheel
    {
        $wheel = new StationClockWheel($this->station);
        $this->setEntityId($wheel, $id);

        return $wheel;
    }

    private function makePlaylist(int $id): StationPlaylist
    {
        $playlist = new StationPlaylist($this->station);
        $playlist->name = 'Test Playlist ' . $id;
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $this->setEntityId($playlist, $id);

        return $playlist;
    }

    /**
     * @return array{start_date: string, end_date: string}
     */
    private function dateRangeForWindow(): array
    {
        $start = CarbonImmutable::now('UTC')->startOf('day');

        return [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->addDays(60)->format('Y-m-d'),
        ];
    }

    /**
     * @param array{start_date: string, end_date: string} $range
     * @param int[] $days
     *
     * @return array<string, mixed>
     */
    private function weeklyItem(int $startTime, int $endTime, array $range, array $days): array
    {
        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_date' => $range['start_date'],
            'end_date' => $range['end_date'],
            'days' => $days,
            'loop_once' => false,
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_end_type' => 'never',
        ];
    }

    /**
     * @param array{start_date: string, end_date: string} $range
     *
     * @return array<string, mixed>
     */
    private function monthlyDateItem(int $startTime, int $endTime, array $range, int $dayOfMonth): array
    {
        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_date' => $range['start_date'],
            'end_date' => $range['end_date'],
            'days' => [],
            'loop_once' => false,
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'recurrence_monthly_pattern' => 'date',
            'recurrence_monthly_day' => $dayOfMonth,
            'recurrence_end_type' => 'never',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function playOnceItem(int $startTime, int $endTime, string $date, int $dayOfWeek): array
    {
        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_date' => $date,
            'end_date' => $date,
            'days' => [$dayOfWeek],
            'loop_once' => false,
            'recurrence_type' => null,
            'recurrence_interval' => 1,
            'recurrence_end_type' => 'never',
        ];
    }

    /**
     * @param array{start_date: string, end_date: string} $range
     * @param int[] $days
     *
     * @return array<string, mixed>
     */
    private function biweeklyItem(
        int $startTime,
        int $endTime,
        array $range,
        array $days,
        string $startDate,
    ): array {
        return [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'start_date' => $startDate,
            'end_date' => $range['end_date'],
            'days' => $days,
            'loop_once' => false,
            'recurrence_type' => 'biweekly',
            'recurrence_interval' => 2,
            'recurrence_end_type' => 'never',
        ];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
