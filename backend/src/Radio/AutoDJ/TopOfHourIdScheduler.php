<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\Schedule\ScheduleConflictChecker;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plans one legal-ID queue row per boundary and hands that row to the dedicated
 * real-time queue when the protected window arrives.
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
        if ($event->getNextSongs() !== []) {
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
            $this->logger->debug('Top-of-hour ID skipped: emergency schedule active.');
            return;
        }

        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
            $station,
            $expectedPlayTime,
        );
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );

        if ($event->isInterrupting()) {
            $planned = $this->queueRepo->findUnplayedTopOfHourLegalIdBetween(
                $station,
                $windowStart,
                $targetTop,
            );

            if ($planned instanceof StationQueue) {
                if (!$this->hourBoundaryPlanner->canLegalIdFinishBeforeTop(
                    $station,
                    $expectedPlayTime,
                    $planned->duration,
                )) {
                    $this->logger->warning(
                        'Top-of-hour ID planned row is too late to finish before the boundary.',
                        [
                            'queue_id' => $planned->id,
                            'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                        ]
                    );
                    return;
                }

                if ($event->setNextSongs($planned)) {
                    $this->logger->info('Top-of-hour ID selected from planned queue row.', [
                        'queue_id' => $planned->id,
                        'media_id' => $planned->media?->id,
                        'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                    ]);
                }
                return;
            }
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

        if (!$nextSong instanceof StationQueue) {
            $this->eventLogger->recordTopOfHourFallback(
                $station,
                $targetTop,
                ClockWheelFallbackReason::NoMediaCandidates,
            );
            $this->em->flush();
            $this->logger->warning('Top-of-hour ID: no mandatory legal-ID media could be resolved.');
            return;
        }

        if (!$this->hourBoundaryPlanner->canLegalIdFinishBeforeTop(
            $station,
            $expectedPlayTime,
            $nextSong->duration,
        )) {
            $this->logger->warning('Top-of-hour ID skipped because it can no longer finish before :00.', [
                'media_id' => $nextSong->media?->id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            ]);
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
        $this->logger->info(
            $event->isInterrupting()
                ? 'Top-of-hour ID created as real-time fallback.'
                : 'Top-of-hour ID planned in the AutoDJ queue.',
            [
                'queue_id' => $nextSong->id,
                'media_id' => $nextSong->media?->id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
            ]
        );
    }
}
