<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Enums\ClockWheelSlotTypes;
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
 * Queues the station-wide legal ID through the normal AutoDJ path.
 *
 * Top-of-hour IDs intentionally never participate in interrupting BuildQueue
 * passes. A single normal-queue path avoids duplicate IDs, song resume after an
 * ID, and competing Liquidsoap fallbacks at the hour boundary.
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
                ['buildTopOfHourId', 6],
            ],
        ];
    }

    public function buildTopOfHourId(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return;
        }

        if ($this->clockWheelHandlesLegalIdThisHour($station, $expectedPlayTime)) {
            $this->logger->debug('Top-of-hour ID skipped: active clock wheel owns the legal ID this hour.');
            return;
        }

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            return;
        }

        if (!$this->hourBoundaryPlanner->isTopOfHourIdDue($station, $expectedPlayTime)) {
            return;
        }

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range,
        );

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
            $this->logger->warning('Top-of-hour ID: no playable station ID could be resolved.');
            return;
        }

        if (!$event->setNextSongs($nextSong)) {
            $this->logger->warning('Top-of-hour ID resolved but BuildQueue rejected it.', [
                'song_id' => $nextSong->song_id,
                'last_song_id' => $event->getLastPlayedSongId(),
            ]);
            return;
        }

        $this->em->flush();
        $this->logger->info('Top-of-hour ID queued through normal AutoDJ.', [
            'media_id' => $nextSong->media?->id,
            'song_id' => $nextSong->song_id,
            'duration' => $nextSong->duration,
            'target_top' => $this->hourBoundaryPlanner
                ->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime)
                ->format(DateTimeImmutable::ATOM),
        ]);
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
