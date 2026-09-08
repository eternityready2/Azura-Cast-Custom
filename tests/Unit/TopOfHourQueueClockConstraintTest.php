<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourMode;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourQueueClockConstraint;
use ReflectionClass;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourQueueClockConstraint.php';

final class TopOfHourQueueClockConstraintTest extends Unit
{
    public function testOpenHourCutsLiveIncidentSongAndResumesAfterIdOccupancy(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $naturalEnd = $start->addSeconds(275); // 23:01:15
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $naturalEnd->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertTrue($event->hasConstraint());
        self::assertSame(
            '2026-09-07 22:59:21.000000',
            $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '2026-09-07 22:59:58.825000',
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame('top_of_hour_station_id', $event->getReason());
        self::assertNotSame(
            $naturalEnd->format('Y-m-d H:i:s.u'),
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testHardHourKeepsOrdinaryMusicOutUntilExactBoundary(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(275)->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::HardToh),
        );

        self::assertTrue($event->hasConstraint());
        self::assertSame(
            '2026-09-07 22:59:21.000000',
            $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            '2026-09-07 23:00:00.000000',
            $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
        );
    }

    public function testPlanDoesNotTouchSongThatEndsBeforeTopOfHourTarget(): void
    {
        $station = $this->makeStation();
        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            CarbonImmutable::parse('2026-09-07 22:58:50', 'UTC')->toDateTimeImmutable(),
        );

        TopOfHourQueueClockConstraint::applyPlan(
            $event,
            $this->makePlan(TopOfHourMode::SoftEtm),
        );

        self::assertFalse($event->hasConstraint());
        self::assertNull($event->getInterruptAt());
        self::assertNull($event->getResumeAt());
    }

    public function testDisabledTopOfHourLeavesOrdinaryTimelineUntouched(): void
    {
        $station = $this->makeStation();
        $config = $station->backend_config;
        $config->top_of_hour_id_enabled = false;
        $station->backend_config = $config;

        $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
        $event = new ResolveQueueClockConstraint(
            $station,
            $start->toDateTimeImmutable(),
            $start->addSeconds(275)->toDateTimeImmutable(),
        );

        // Disabled resolution returns before StationIdSelector is ever touched,
        // so a constructor-less clock is sufficient to verify isolation without
        // bringing a database into this deterministic unit regression.
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();
        (new TopOfHourQueueClockConstraint($clock))->resolve($event);

        self::assertFalse($event->hasConstraint());
        self::assertNull($event->getInterruptAt());
        self::assertNull($event->getResumeAt());
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'TOH Queue Constraint Test';
        $station->short_name = 'toh_queue_constraint_test';
        $station->timezone = 'UTC';

        return $station;
    }

    private function makePlan(TopOfHourMode $mode): TopOfHourPlan
    {
        // applyPlan() only consumes clock fields from TopOfHourPlan. Use a
        // constructor-less StationMedia sentinel so this regression stays a pure
        // clock-math test and never needs a persisted storage-location entity.
        $media = (new ReflectionClass(StationMedia::class))->newInstanceWithoutConstructor();

        return new TopOfHourPlan(
            mode: $mode,
            boundaryAt: CarbonImmutable::parse('2026-09-07 23:00:00', 'UTC')->toDateTimeImmutable(),
            targetStartAt: CarbonImmutable::parse('2026-09-07 22:59:21', 'UTC')->toDateTimeImmutable(),
            media: $media,
            durationSeconds: 37.825,
        );
    }
}
