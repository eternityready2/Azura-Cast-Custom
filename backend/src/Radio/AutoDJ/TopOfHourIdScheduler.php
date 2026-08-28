<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
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
        private readonly Scheduler $scheduler,
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

        $protectedNextHour = $this->hasProtectedStartAtNextTop($station, $expectedPlayTime);

        if (
            $event->isInterrupting()
            && !$protectedNextHour
            && !$this->isOpenHourTriggerWindow($station, $expectedPlayTime)
        ) {
            $this->logger->debug(
                'Top-of-hour ID deferred: next hour is open and the natural-handoff window has not arrived.'
            );
            return;
        }

        if ($event->isInterrupting()) {
            $planned = $this->queueRepo->findPendingTopOfHourLegalIdBetween(
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
                    $this->logger->info('Top-of-hour ID selected from pending planned queue row.', [
                        'queue_id' => $planned->id,
                        'media_id' => $planned->media?->id,
                        'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                        'protected_next_hour' => $protectedNextHour,
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

        // Planning is not delivery. Keep the row unsent until the dedicated
        // real-time task successfully hands this exact request to Liquidsoap.
        $nextSong->sent_to_autodj = false;
        $nextSong->timestamp_cued = $expectedPlayTime;
        $nextSong->timestamp_played = $expectedPlayTime;

        $this->em->flush();
        $this->logger->info(
            $event->isInterrupting()
                ? 'Top-of-hour ID created for immediate dedicated delivery.'
                : 'Top-of-hour ID planned for dedicated delivery.',
            [
                'queue_id' => $nextSong->id,
                'media_id' => $nextSong->media?->id,
                'duration' => $nextSong->duration,
                'target_top' => $targetTop->format(DateTimeImmutable::ATOM),
                'protected_next_hour' => $protectedNextHour,
            ]
        );
    }

    private function isOpenHourTriggerWindow(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $secondsUntilTop = $this->hourBoundaryPlanner->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );

        return $secondsUntilTop > 0
            && $secondsUntilTop <= $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station);
    }

    private function hasProtectedStartAtNextTop(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $secondsUntilTop = $this->hourBoundaryPlanner->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );

        if ($secondsUntilTop <= 0) {
            return false;
        }

        $secondsUntilScheduled = $this->scheduler->secondsUntilNextScheduledStart(
            $station,
            $expectedPlayTime,
        );

        if ($secondsUntilScheduled === null) {
            return false;
        }

        return abs($secondsUntilScheduled - $secondsUntilTop) <= 2;
    }
}
