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
                ['buildTopOfHourId', 2],
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

        if (!$isInterrupting) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: waiting for interrupting queue at hour boundary.');
            return;
        }

        $emergencyActive = $this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime);
        if ($emergencyActive) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: emergency schedule active.');
            return;
        }

        $timezone = $station->getTimezoneObject();
        $local = $expectedPlayTime->setTimezone($timezone);
        $secondsAfterTop = $local->getTimestamp() - $local->setTime((int)$local->format('H'), 0)->getTimestamp();
        $tolerance = $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station);
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $isDue = $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $expectedPlayTime);

        $this->logger->info('[TOPH DEBUG] Interrupt due evaluation.', [
            'seconds_after_top' => $secondsAfterTop,
            'tolerance_seconds' => $tolerance,
            'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            'is_due' => $isDue,
        ]);

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
