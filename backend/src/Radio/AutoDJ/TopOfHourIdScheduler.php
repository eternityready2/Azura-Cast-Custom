<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Repository\StationQueueRepository;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues the mandatory legal ID into the real-time top-of-hour queue.
 */
final class TopOfHourIdScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly HourBoundaryLegalIdResolver $legalIdResolver,
        private readonly StationQueueRepository $queueRepo,
        private readonly TopOfHourOwnershipResolver $ownershipResolver,
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
        if (!$event->isInterrupting() || $event->getNextSongs() !== []) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return;
        }

        if ($this->ownershipResolver->clockWheelHandlesLegalId($station, $expectedPlayTime)) {
            $this->logger->debug('Top-of-hour ID skipped: active clock wheel owns the legal-ID boundary.');
            return;
        }

        if ($this->conflictChecker->hasEmergencyScheduleActive($station, $expectedPlayTime)) {
            $this->logger->info('[TOPH DEBUG] Skipping TOPH: emergency schedule active.');
            return;
        }

        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
            $station,
            $expectedPlayTime,
        );
        $isDue = $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $expectedPlayTime);

        $this->logger->info('[TOPH DEBUG] Real-time end-of-hour ID evaluation.', [
            'station_id' => $station->id,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'expected_play_time_local' => $expectedPlayTime
                ->setTimezone($station->getTimezoneObject())
                ->format(DateTimeImmutable::ATOM),
            'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            'is_due' => $isDue,
        ]);

        if (!$isDue) {
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

        if ($nextSong === null) {
            $this->eventLogger->recordTopOfHourFallback(
                $station,
                $targetTop,
                ClockWheelFallbackReason::NoMediaCandidates,
            );
            $this->em->flush();
            $this->logger->warning('[TOPH DEBUG] Top-of-hour ID: could not resolve mandatory legal_id track.');
            return;
        }

        if (
            !$this->hourBoundaryPlanner->canLegalIdFinishBeforeTop(
                $station,
                $expectedPlayTime,
                $nextSong->duration,
            )
        ) {
            $this->logger->warning('[TOPH DEBUG] Legal ID skipped because it can no longer finish before :00.', [
                'media_id' => $nextSong->media?->id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            ]);
            return;
        }

        if (!$event->setNextSongs($nextSong)) {
            $this->logger->warning('[TOPH DEBUG] Legal ID resolved but BuildQueue rejected it.', [
                'song_id' => $nextSong->song_id,
                'last_song_id' => $event->getLastPlayedSongId(),
            ]);
            return;
        }

        $this->em->flush();
        $this->logger->info('[TOPH DEBUG] Top-of-hour ID selected for dedicated queue.', [
            'media_id' => $nextSong->media?->id,
            'song_id' => $nextSong->song_id,
            'duration' => $nextSong->duration,
            'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
        ]);
    }
}
