<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the near-term ordinary AutoDJ projection consume the same station clock
 * event that the plugin enforces on air without rewriting ordinary queued-media
 * durations or AutoCue cue-out values.
 *
 * The authoritative runtime cut is performed by Liquidsoap. This listener only
 * adjusts the projection cursor when the song that is actually on air crosses
 * the next TOH target. Future unsaved/upcoming rows are left natural so a stale
 * forecast cannot manufacture 2- or 3-second music rows around :59.
 */
final class TopOfHourQueueClockConstraint implements EventSubscriberInterface
{
    public function __construct(
        private readonly TopOfHourClock $clock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ResolveQueueClockConstraint::class => 'resolve',
        ];
    }

    public function resolve(ResolveQueueClockConstraint $event): void
    {
        $station = $event->getStation();
        if (!$this->clock->isEnabled($station)) {
            return;
        }

        // Only the initial current-song projection has no StationQueue row.
        // Applying this constraint to ordinary future rows causes Queue.php to
        // persist a hard duration cap into the media row, which can survive later
        // timing recalculations and collapse Upcoming Programming into tiny slots.
        if (null !== $event->getQueueRow()) {
            return;
        }

        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());

        $boundary = CarbonImmutable::instance($this->clock->getNextBoundary($station, $start));
        $candidateTarget = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->clock->getIdStartSecond($station));

        if ($candidateTarget <= $start || $candidateTarget > $projectedEnd) {
            return;
        }

        $plan = $this->clock->plan($station, $event->getExpectedPlayAt());
        if (!$plan instanceof TopOfHourPlan) {
            return;
        }

        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            return;
        }

        self::applyPlan($event, $plan);
    }

    /**
     * Apply an already-resolved TOH plan to the current on-air projection.
     */
    public static function applyPlan(
        ResolveQueueClockConstraint $event,
        TopOfHourPlan $plan,
    ): void {
        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());
        $target = CarbonImmutable::instance($plan->targetStartAt);

        if ($target <= $start || $target > $projectedEnd) {
            return;
        }

        if ($plan->isHard()) {
            // A rigid programme owns :00. Ordinary AutoDJ projection resumes at
            // the boundary; the rigid runtime remains the actual on-air owner.
            $resumeAt = CarbonImmutable::instance($plan->boundaryAt);
        } else {
            // Open hour: the ID occupies real wall-clock time even though it lives
            // in the plugin-owned Liquidsoap lane rather than the ordinary queue.
            $resumeAt = $target->addMilliseconds(
                (int)round($plan->durationSeconds * 1000)
            );
        }

        $event->constrain(
            $target->toDateTimeImmutable(),
            $resumeAt->toDateTimeImmutable(),
            'top_of_hour_station_id',
        );
    }
}
