<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Station;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues mandatory legal_id at :00 when station-wide top-of-hour protection is enabled.
 */
final class TopOfHourIdScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly HourBoundaryLegalIdResolver $legalIdResolver,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationScheduleRepository $scheduleRepo,
        private readonly Scheduler $scheduler,
        private readonly ScheduleConflictChecker $conflictChecker,
        private readonly ClockWheelEventLogger $eventLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                // Legal IDs are mandatory. Run before request selection (priority 5).
                // Active clock wheels with their own legal_id slot are detected below
                // and still retain control for that hour.
                ['buildTopOfHourId', 6],
            ],
        ];
    }

    public function buildTopOfHourId(BuildQueue $event): void
    {
        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();
        $nextSongs = $event->getNextSongs();
        $isInterrupting = $event->isInterrupting();

        $this->logger->info('[TOPH DEBUG] BuildQueue received by TopOfHourIdScheduler.', [
            'station_id' => $station->id,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'expected_play_time_local' => $expectedPlayTime
                ->setTimezone($station->getTimezoneObject())
                ->format(DateTimeImmutable::ATOM),
            'existing_next_songs' => count($nextSongs),
            'interrupting' => $isInterrupting,
        ]);

        if (!empty($nextSongs)) {
            $this->logger->info('[TOPH DEBUG] Skipping before TOPH evaluation.', [
                'reason' => 'next_songs_already_selected',
                'existing_next_songs' => count($nextSongs),
            ]);

            return;
        }

        $protectionEnabled = $this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station);
        $this->logger->info('[TOPH DEBUG] Protection status.', [
            'enabled' => $protectionEnabled,
        ]);

        if (!$protectionEnabled) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: protection disabled.');
            return;
        }

        if ($this->clockWheelHandlesLegalIdThisHour($station, $expectedPlayTime)) {
            $this->logger->debug('Top-of-hour ID skipped: active clock wheel has legal_id at :00.');

            return;
        }

        $emergencyActive = $this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime);
        if ($emergencyActive) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: emergency schedule active.');
            return;
        }

        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);

        // Two distinct triggers, not mutually exclusive with priority:
        //
        // 1. Normal advance queuing (NOT interrupting): fires up to
        //    `finish_buffer + id_max_seconds` seconds before :00, using the
        //    same lookahead math HourBoundaryPlanner already exposes. This is
        //    the path that actually gets the ID placed into the ordinary
        //    AutoDJ queue ahead of time -- previously this method returned
        //    immediately whenever `$isInterrupting` was false, which meant
        //    this path never ran and isTopOfHourIdDue() was effectively dead
        //    code. Compliance depended entirely on trigger #2 below landing
        //    inside a narrow tolerance window on a once-a-minute cron tick,
        //    which is why on-time rate was so low.
        //
        // 2. Interrupting fallback: a tight window right at/after :00, only
        //    used as a safety net if #1 didn't already get the ID queued
        //    (e.g. queue was empty, station just came online).
        if ($isInterrupting) {
            $isDue = $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $expectedPlayTime);

            $this->logger->info('[TOPH DEBUG] Interrupt-fallback due evaluation.', [
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                'is_due' => $isDue,
            ]);
        } else {
            $isDue = $this->hourBoundaryPlanner->isTopOfHourIdDue($station, $expectedPlayTime);

            $this->logger->info('[TOPH DEBUG] Advance-queuing due evaluation.', [
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                'is_due' => $isDue,
            ]);
        }

        if (!$isDue) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: ID is not due.');
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $this->logger->info('[TOPH DEBUG] Resolving mandatory legal ID.', [
            'recent_history_count' => count($recentHistory),
        ]);

        $nextSong = $this->legalIdResolver->resolveMandatoryLegalId(
            $station,
            $recentHistory,
            $expectedPlayTime,
        );

        if (null === $nextSong) {
            $expectedAt = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
                $station,
                $expectedPlayTime,
            );
            $this->eventLogger->recordTopOfHourFallback(
                $station,
                $expectedAt,
                ClockWheelFallbackReason::NoMediaCandidates,
            );
            $this->em->flush();
            $this->logger->warning('[TOPH DEBUG] Top-of-hour ID: could not resolve mandatory legal_id track.');

            return;
        }

        if ($event->setNextSongs($nextSong)) {
            $this->em->flush();
            $this->logger->info('[TOPH DEBUG] Top-of-hour ID resolved and selected.', [
                'media_id' => $nextSong->media?->id,
                'song_id' => $nextSong->song_id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            ]);
        } else {
            $this->logger->warning('[TOPH DEBUG] Legal ID resolved but BuildQueue rejected it.', [
                'song_id' => $nextSong->song_id,
                'last_song_id' => $event->getLastPlayedSongId(),
            ]);
        }
    }

    private function clockWheelHandlesLegalIdThisHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $activeEvent = $this->findActiveClockWheelSchedule($station, $expectedPlayTime);
        if (null === $activeEvent?->clock_wheel) {
            return false;
        }

        $wheel = $activeEvent->clock_wheel;
        if (!$wheel->is_active) {
            return false;
        }

        foreach ($wheel->slots as $slot) {
            if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($slot->type, $slot->position_seconds)) {
                return true;
            }
        }

        return false;
    }

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
