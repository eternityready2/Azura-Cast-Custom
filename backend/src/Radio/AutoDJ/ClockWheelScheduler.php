<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Intercepts the AutoDJ queue building process to inject Clock Wheel playback.
 *
 * When a clock wheel schedule is active and no other calendar item takes priority,
 * resolves the next song from timed format-clock anchors.
 */
final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly ClockWheelPlaybackPlanner $planner,
        private readonly ScheduleConflictChecker $conflictChecker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 3 — runs after requests (5) but before normal QueueBuilder (0)
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
        ];
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if ($this->conflictChecker->hasNonClockWheelScheduleActive($station, $expectedPlayTime)) {
            $this->logger->debug(
                'Clock Wheel skipped: another scheduled playlist or streamer is active.'
            );
            return;
        }

        $activeEvent = $this->findActiveClockWheelSchedule($station->id, $expectedPlayTime);

        if (null === $activeEvent || null === $activeEvent->clock_wheel) {
            return;
        }

        $wheel = $activeEvent->clock_wheel;

        if (!$wheel->is_active) {
            return;
        }

        $this->logger->info(
            sprintf('Clock Wheel "%s" is active. Overriding normal AutoDJ queue.', $wheel->name),
            ['clock_wheel_id' => $wheel->id, 'schedule_id' => $activeEvent->id]
        );

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->planner->resolveNextQueueEntry($wheel, $recentHistory, $expectedPlayTime);

        if (null !== $nextSong) {
            $set = $event->setNextSongs($nextSong);

            if ($set) {
                $this->em->flush();
                $this->logger->info(
                    'Clock Wheel resolved next song.',
                    ['next_song' => (string)$event]
                );
            }
        } else {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel "%s" could not resolve a playable track. Falling through to normal AutoDJ.',
                    $wheel->name
                )
            );
        }
    }

    /**
     * Find a StationSchedule that links to an active Clock Wheel for the given station and time.
     */
    private function findActiveClockWheelSchedule(int $stationId, DateTimeImmutable $now): ?StationSchedule
    {
        $timeCode = (int)$now->format('G') * 100 + (int)$now->format('i');
        $weekday = (int)$now->format('N');

        /** @var StationSchedule[] $schedules */
        $schedules = $this->em->createQuery(
            'SELECT s, w FROM App\Entity\StationSchedule s
             JOIN s.clock_wheel w
             WHERE w.station = :stationId
             AND w.is_active = true
             AND s.start_time <= :timeCode
             AND s.end_time > :timeCode'
        )
            ->setParameter('stationId', $stationId)
            ->setParameter('timeCode', $timeCode)
            ->getResult();

        foreach ($schedules as $schedule) {
            $days = $schedule->days;
            if ($days === [] || in_array($weekday, $days, true)) {
                return $schedule;
            }
        }

        return null;
    }
}
