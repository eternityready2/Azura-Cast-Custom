<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the ordinary AutoDJ projection consume the same station clock event
 * that the plugin enforces on air.
 *
 * The core queue remains policy-neutral: it only exposes a generic clock
 * constraint event. This plugin supplies the TOH interruption and occupancy.
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

        $plan = $this->clock->plan($station, $event->getExpectedPlayAt());
        if (!$plan instanceof TopOfHourPlan) {
            return;
        }

        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            return;
        }

        $target = CarbonImmutable::instance($plan->targetStartAt);
        $start = CarbonImmutable::instance($event->getExpectedPlayAt());
        $projectedEnd = CarbonImmutable::instance($event->getProjectedEndAt());

        if ($target <= $start || $target > $projectedEnd) {
            return;
        }

        if ($plan->isHard()) {
            // A rigid programme owns :00. The ordinary AutoDJ timeline may not
            // place music in the gap between a naturally short ID and :00.
            $resumeAt = CarbonImmutable::instance($plan->boundaryAt);
        } else {
            // Open hour: the ID occupies real wall-clock time even though it is
            // staged in the plugin's dedicated Liquidsoap queue rather than the
            // ordinary AutoDJ queue.
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
