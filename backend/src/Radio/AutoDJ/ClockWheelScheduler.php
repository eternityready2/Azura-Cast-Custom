<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
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
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
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

        $activeEvent = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);

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
     * Find a StationSchedule that links to an active clock wheel for the station at the given time.
     *
     * Uses the same date/recurrence/overnight rules as playlist scheduling.
     */
    private function findActiveClockWheelSchedule(Station $station, DateTimeImmutable $now): ?StationSchedule
    {
        $tz = $station->getTimezoneObject();

        foreach ($this->scheduleRepo->getAllScheduledItemsForStation($station) as $schedule) {
            if ($schedule->clock_wheel === null) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
                return $schedule;
            }
        }

        return null;
    }
}
