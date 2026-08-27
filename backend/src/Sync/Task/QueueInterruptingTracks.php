<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\Queue;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use Monolog\LogRecord;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final class QueueInterruptingTracks extends AbstractTask
{
    private const int SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS;

    public function __construct(
        private readonly Queue $queue,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Scheduler $scheduler,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $this->queueForStation($station);
            } finally {
                $this->logger->popProcessor();
            }
        }
    }

    private function queueForStation(Station $station): void
    {
        if (!$station->supportsAutoDjQueue()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        $this->expireMissedTopOfHourRows($station);
        $this->observeScheduledBoundary($station);

        $now = Time::nowUtc()->toDateTimeImmutable();
        $hasTopOfHour = $this->hasTopOfHourToDeliver($station, $now);
        $hasInterruptingPlaylist = $hasTopOfHour;
        $tz = $station->getTimezoneObject();

        foreach ($station->playlists as $playlist) {
            if (
                $playlist->isPlayable(true)
                || $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz)
            ) {
                $hasInterruptingPlaylist = true;
                break;
            }
        }

        if (!$hasInterruptingPlaylist && !empty($this->sponsorGuarantee->getPlaylistsBehindPace($station))) {
            $hasInterruptingPlaylist = true;
        }

        if (!$hasInterruptingPlaylist) {
            return;
        }

        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
            $this->logger->debug(
                'Top-of-hour queue is active; deferring interrupting queue work for this tick.'
            );
            return;
        }

        $interruptingEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting);
        $songsToPlay = $this->queue->getInterruptingQueue($station);

        if (empty($songsToPlay)) {
            return;
        }

        foreach ($songsToPlay as $sq) {
            $event = AnnotateNextSong::fromStationQueue($sq, true);
            $this->eventDispatcher->dispatch($event);

            $track = $event->buildAnnotations();
            $queueName = $sq->top_of_hour_legal_id
                ? LiquidsoapQueues::TopOfHour
                : LiquidsoapQueues::Interrupting;

            $isEmpty = match ($queueName) {
                LiquidsoapQueues::TopOfHour => $backend->isQueueEmpty(
                    $station,
                    LiquidsoapQueues::TopOfHour
                ),
                LiquidsoapQueues::Interrupting => $interruptingEmpty
                    && $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting),
                default => false,
            };

            if (!$isEmpty) {
                $this->logger->info('Skipping enqueue; target queue is not empty.', [
                    'queue' => $queueName->value,
                ]);

                if (LiquidsoapQueues::Interrupting === $queueName) {
                    $this->discardUndeliveredInterruptingRow($sq);
                }
                continue;
            }

            if (LiquidsoapQueues::TopOfHour === $queueName) {
                $this->enqueueTopOfHour($backend, $station, $sq, $track);
                continue;
            }

            // Annotation marks normal rows as sent before the external enqueue.
            // Reset that optimistic bit first; only a successful Liquidsoap
            // enqueue may satisfy strict-start one-shot protection.
            $sq->sent_to_autodj = false;
            $this->em->persist($sq);
            $this->em->flush();

            try {
                $this->logger->debug('Submitting request to AutoDJ.', [
                    'track' => $track,
                    'queue' => $queueName->value,
                ]);
                $response = $backend->enqueue($station, $queueName, $track);
                $this->logger->debug('AutoDJ request response', ['response' => $response]);

                $requestId = trim((string)($response[0] ?? ''));
                if ($requestId === '' || !ctype_digit($requestId)) {
                    throw new RuntimeException(
                        'Liquidsoap did not return a request ID for the interrupting enqueue.'
                    );
                }

                $sq->sent_to_autodj = true;
                $this->em->persist($sq);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('Interrupting enqueue failed; row remains retryable.', [
                    'queue_id' => $sq->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->discardUndeliveredInterruptingRow($sq);
            }
        }
    }

    private function discardUndeliveredInterruptingRow(StationQueue $sq): void
    {
        try {
            $this->em->remove($sq);
            $this->em->flush();
        } catch (\Throwable $e) {
            // The durable success bit was cleared before delivery. Even if cleanup
            // itself fails, strict-start catch-up will not treat this row as served.
            $sq->sent_to_autodj = false;
            $this->em->persist($sq);
            $this->em->flush();
            $this->logger->warning('Could not remove undelivered interrupting row.', [
                'queue_id' => $sq->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function enqueueTopOfHour(
        Liquidsoap $backend,
        Station $station,
        StationQueue $sq,
        string $track,
    ): void {
        if ($sq->sent_to_autodj) {
            $this->logger->debug('Top-of-hour row already sent; refusing duplicate enqueue.', [
                'queue_id' => $sq->id,
            ]);
            return;
        }

        $now = Time::nowUtc()->toDateTimeImmutable();
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $now);
        $boundary = $targetTop->getTimestamp();
        $claimed = false;
        $acceptedByLiquidsoap = false;

        if ($station->backend_config->top_of_hour_hard_trigger_enabled) {
            try {
                $claimResponse = $backend->command(
                    $station,
                    'top_of_hour.claim ' . $boundary,
                );
                $claimStatus = strtolower(trim((string)($claimResponse[0] ?? '')));

                if ('claimed' !== $claimStatus) {
                    $this->logger->info(
                        'Top-of-hour boundary already owned; PHP enqueue skipped.',
                        [
                            'queue_id' => $sq->id,
                            'boundary' => $boundary,
                            'claim_status' => $claimStatus,
                        ]
                    );
                    return;
                }

                $claimed = true;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Top-of-hour ownership claim failed; dedicated enqueue skipped so the wall-clock fallback remains sole owner.',
                    [
                        'queue_id' => $sq->id,
                        'boundary' => $boundary,
                        'exception' => $e->getMessage(),
                    ]
                );
                return;
            }
        }

        try {
            $this->logger->debug('Submitting top-of-hour request to AutoDJ.', [
                'track' => $track,
                'queue_id' => $sq->id,
                'boundary' => $boundary,
            ]);
            $response = $backend->enqueue($station, LiquidsoapQueues::TopOfHour, $track);
            $requestId = trim((string)($response[0] ?? ''));

            if ($requestId === '' || !ctype_digit($requestId)) {
                throw new RuntimeException('Liquidsoap did not return a request ID for the TOH enqueue.');
            }

            // Once Liquidsoap accepts a request ID, this boundary remains owned
            // even if the following database flush fails. Releasing at that point
            // would allow the wall-clock path to queue a second copy.
            $acceptedByLiquidsoap = true;

            if ($claimed) {
                try {
                    $commitResponse = $backend->command(
                        $station,
                        'top_of_hour.commit ' . $requestId,
                    );
                    $commitStatus = strtolower(trim((string)($commitResponse[0] ?? '')));

                    if ('committed' !== $commitStatus) {
                        $this->logger->warning(
                            'Top-of-hour ownership commit returned an unexpected status.',
                            [
                                'queue_id' => $sq->id,
                                'request_id' => $requestId,
                                'boundary' => $boundary,
                                'commit_status' => $commitStatus,
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    // The request is already inside Liquidsoap. Keep the claim;
                    // releasing it here could make the fallback enqueue a duplicate.
                    $this->logger->warning(
                        'Top-of-hour request accepted but ownership commit failed; keeping boundary claimed.',
                        [
                            'queue_id' => $sq->id,
                            'request_id' => $requestId,
                            'boundary' => $boundary,
                            'exception' => $e->getMessage(),
                        ]
                    );
                }
            }

            $sq->sent_to_autodj = true;
            $this->em->persist($sq);
            $this->em->flush();

            $this->logger->info('Top-of-hour request handed to Liquidsoap.', [
                'queue_id' => $sq->id,
                'request_id' => $requestId,
                'boundary' => $boundary,
            ]);
        } catch (\Throwable $e) {
            if ($claimed && !$acceptedByLiquidsoap) {
                try {
                    $backend->command($station, 'top_of_hour.release ' . $boundary);
                } catch (\Throwable) {
                    // If Liquidsoap is unavailable, its in-memory claim disappears on restart.
                }
            }

            $this->logger->error('Top-of-hour enqueue failed.', [
                'queue_id' => $sq->id,
                'boundary' => $boundary,
                'accepted_by_liquidsoap' => $acceptedByLiquidsoap,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function hasTopOfHourToDeliver(Station $station, \DateTimeImmutable $now): bool
    {
        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        if (!$this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $now)) {
            return false;
        }

        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $now);
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );

        if ($this->queueRepo->findPendingTopOfHourLegalIdBetween(
            $station,
            $windowStart,
            $targetTop,
        ) instanceof StationQueue) {
            return true;
        }

        return $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $now);
    }

    private function expireMissedTopOfHourRows(Station $station): void
    {
        $hourStart = CarbonImmutable::now($station->getTimezoneObject())
            ->startOfHour()
            ->toDateTimeImmutable();

        $expired = $this->queueRepo->deleteUnplayedTopOfHourLegalIdsBefore($station, $hourStart);
        if ($expired > 0) {
            $this->logger->warning(
                'Removed missed top-of-hour planning rows after their boundary passed.',
                ['count' => $expired]
            );
        }
    }

    /**
     * Observe approaching scheduled boundaries without hard-skipping the current item.
     */
    private function observeScheduledBoundary(Station $station): void
    {
        $now = Time::nowUtc();

        try {
            $secondsToScheduled = $this->scheduler->secondsUntilNextScheduledStart($station, $now);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scheduled boundary observation: lookup failed.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (
            null === $secondsToScheduled
            || $secondsToScheduled > self::SCHEDULED_START_GRACE_SECONDS
        ) {
            return;
        }

        $currentSong = $station->current_song;
        if (null === $currentSong || null === $currentSong->timestamp_start) {
            return;
        }

        $currentSongDuration = $currentSong->duration ?? 0.0;
        $currentSongEndsAt = $currentSong->timestamp_start
            ->modify('+' . (int)round($currentSongDuration) . ' seconds')
            ->getTimestamp();
        $scheduledBoundaryAt = $now->getTimestamp() + $secondsToScheduled;

        if ($currentSongEndsAt <= $scheduledBoundaryAt + self::SCHEDULED_START_GRACE_SECONDS) {
            return;
        }

        $this->logger->warning(
            'Scheduled boundary at risk: current item projects beyond the strict-start catch-up window; no hard skip will be issued.',
            [
                'current_song' => $currentSong->title,
                'seconds_to_scheduled' => $secondsToScheduled,
                'current_song_would_end_at' => $currentSongEndsAt,
                'scheduled_boundary_at' => $scheduledBoundaryAt,
                'maximum_grace_seconds' => self::SCHEDULED_START_GRACE_SECONDS,
            ]
        );
    }
}
