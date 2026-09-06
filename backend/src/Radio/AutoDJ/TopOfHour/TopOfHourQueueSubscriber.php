<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\Scheduler;
use App\Utilities\Time;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The sole station-wide producer of automatic Top-of-Hour Station ID rows.
 *
 * It participates only in normal AutoDJ queue construction. There is no second
 * interrupting queue, wall-clock skip, ducking path or after-the-fact retry that
 * can race this producer.
 *
 * Selection deliberately does not persist the row. Lower-priority BuildQueue
 * validators must be able to reject a candidate without leaving an orphan row
 * that falsely claims the hour. Queue::buildQueue() persists the final accepted
 * selection after every validator and queue-level guard has completed.
 */
final class TopOfHourQueueSubscriber implements EventSubscriberInterface
{
    /** Whole-second queue projection may land just before a millisecond target. */
    private const int HARD_TARGET_EARLY_TOLERANCE_MS = 999;

    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly EntityManagerInterface $em,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['queueStationId', 6],
        ];
    }

    public function queueStationId(BuildQueue $event): void
    {
        if ($event->isInterrupting() || [] !== $event->getNextSongs()) {
            return;
        }

        $station = $event->getStation();
        if (!$this->clock->isEnabled($station)) {
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();
        $plan = $this->clock->plan($station, $expectedPlayTime);
        if (null === $plan) {
            return;
        }

        $selectionOpensAt = $plan->isHard()
            ? $plan->targetStartAt->modify('-' . self::HARD_TARGET_EARLY_TOLERANCE_MS . ' milliseconds')
            : $plan->targetStartAt;

        if ($expectedPlayTime < $selectionOpensAt) {
            return;
        }

        // Once the boundary itself has arrived, a late automatic ID would delay
        // the new hour. Protect the rigid :00 item instead of inserting a stale
        // station ID after the boundary.
        if ($expectedPlayTime >= $plan->boundaryAt) {
            return;
        }

        // In HARD TOH the ID must fit completely before the rigid boundary. A
        // projection that has already moved beyond the calculated backtime may
        // not insert a late ID that would push the :00 owner off clock. The
        // narrow early tolerance above exists only because the shared queue
        // projection is whole-second based while ID durations can be fractional.
        if ($plan->isHard() && $expectedPlayTime > $plan->targetStartAt) {
            $this->logger->warning('Top-of-hour HARD TOH target was missed; preserving rigid :00 priority.', [
                'station_id' => $station->id,
                'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
                'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
                'boundary_at' => $plan->boundaryAt->format(DateTimeImmutable::ATOM),
            ]);
            return;
        }

        // An already-active wheel or a wheel explicitly beginning at the target
        // boundary may own its own position-zero ID. In either case the
        // station-wide producer yields so two IDs cannot stack around :00.
        if (
            $this->clockWheelHandlesStationId($station, $expectedPlayTime)
            || $this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)
        ) {
            return;
        }

        if ($this->hasBoundaryRow($station, $plan->boundaryAt)) {
            return;
        }

        $queueEntry = StationQueue::fromMedia($station, $plan->media);
        $queueEntry->top_of_hour_legal_id = true;
        $queueEntry->top_of_hour_boundary_at = $plan->boundaryAt;
        $queueEntry->duration = $plan->durationSeconds;

        // Mandatory IDs use the array setter so BuildQueue's ordinary music
        // consecutive-song check does not reject a station with a one-ID library.
        // Queue::buildQueue() has a matching mandatory-content exemption before
        // it performs its final same-song retry guard.
        if (!$event->setNextSongs([$queueEntry])) {
            return;
        }

        $this->logger->debug('Top-of-hour station ID selected for queue validation.', [
            'station_id' => $station->id,
            'media_id' => $plan->media->id,
            'mode' => $plan->mode->value,
            'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
            'projected_start_at' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'boundary_at' => $plan->boundaryAt->format(DateTimeImmutable::ATOM),
            'duration_seconds' => $plan->durationSeconds,
        ]);
    }

    private function hasBoundaryRow(Station $station, DateTimeImmutable $boundary): bool
    {
        $count = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(q.id) FROM App\Entity\StationQueue q
                WHERE q.station = :station
                AND q.top_of_hour_legal_id = true
                AND q.top_of_hour_boundary_at = :boundary
            DQL
        )->setParameters([
            'station' => $station,
            // StationQueue normalizes this field to UTC on write. Normalize the
            // query parameter the same way so non-UTC stations get the same
            // exactly-once behavior as UTC stations.
            'boundary' => Time::toUtcCarbonImmutable($boundary),
        ])->getSingleScalarResult();

        return $count > 0;
    }

    private function clockWheelHandlesStationId(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $activeSchedule = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);
        $wheel = $activeSchedule?->clock_wheel;
        if (null === $wheel || !$wheel->is_active) {
            return false;
        }

        foreach ($wheel->slots as $slot) {
            if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($slot->type, $slot->position_seconds)) {
                return true;
            }
        }

        return false;
    }

    private function findActiveClockWheelSchedule(
        Station $station,
        DateTimeImmutable $when,
    ): ?StationSchedule {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if (null === $schedule->clock_wheel) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $when)) {
                return $schedule;
            }
        }

        return null;
    }
}
