<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\QueueLogCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Monolog\Handler\TestHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;

/**
 * Public methods related to the AutoDJ Queue process.
 */
final class Queue
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly StationQueueRepository $queueRepo,
        private readonly Scheduler $scheduler,
        private readonly BroadcastClockPlanner $broadcastClockPlanner,
        private readonly QueueLogCache $queueLogCache
    ) {
    }

    /**
     * @param int|null $lookaheadMinutesOverride When given, builds forward to at least
     *        this many minutes regardless of the station's configured
     *        `autodj_queue_lookahead_minutes`. Used by the linear-log builder to project
     *        a full day ahead on demand without changing the station's live setting.
     * @param int|null $maxTracksOverride Safety cap override to match a larger horizon.
     * @return list<array{started_at:int, duration:int, reason:string}>
     */
    public function buildQueue(
        Station $station,
        ?int $lookaheadMinutesOverride = null,
        ?int $maxTracksOverride = null,
        bool $isPreview = false,
    ): array {
        $previewGaps = [];
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->info('Cannot build queue: station does not support AutoDJ queue.');
            return [];
        }

        // Adjust "expectedCueTime" time from current queue.
        $expectedCueTime = Time::nowUtc();

        // Get expected play time of each item.
        $currentSong = $station->current_song;
        if (null !== $currentSong) {
            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $currentSong->timestamp_start,
                $currentSong->duration
            );

            if ($expectedPlayTime < $expectedCueTime) {
                $expectedPlayTime = $expectedCueTime;
            }
        } else {
            $expectedPlayTime = $expectedCueTime;
        }

        $maxQueueLength = max($station->backend_config->autodj_queue_length, 2);

        // Track-count queue length alone (default 3, sometimes set as low as 2)
        // does not reliably reach far enough into the future for clock wheels,
        // schedules, or top-of-hour logic to resolve tracks in advance -- how
        // far ahead in *time* that represents depends entirely on how long the
        // next few songs happen to be. When configured, this makes the queue
        // keep building until it reaches a guaranteed minimum time horizon,
        // independent of track length.
        $lookaheadMinutes = $lookaheadMinutesOverride ?? $station->backend_config->autodj_queue_lookahead_minutes;
        $lookaheadHorizon = $lookaheadMinutes > 0
            ? Time::nowUtc()->modify('+' . $lookaheadMinutes . ' minutes')
            : null;

        // Hard safety cap so a misconfigured horizon (or a station with very
        // short tracks) can't spin this into an unbounded loop.
        $maxLookaheadTracks = $maxTracksOverride ?? 500;

        $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);

        $lastSongId = null;
        $queueLength = 0;

        foreach ($upcomingQueue as $queueRow) {
            if (!$queueRow->sent_to_autodj) {
                if (!$this->isQueueRowStillValid($queueRow, $expectedPlayTime)) {
                    $this->em->remove($queueRow);
                    continue;
                }

                // Re-apply a soft anchor to rows that were planned before the
                // latest live timing correction. This is especially important
                // when the actual air clock drifts relative to projected queue
                // timestamps: the last song before a programme/news boundary can
                // still be given a graceful cue-out before it is handed to Liquidsoap.
                $this->applyBroadcastClockCapToQueuedRow($station, $queueRow, $expectedPlayTime);
            }

            // Only use the five-second safety floor for genuinely missing/bad
            // durations. A deliberate wall-clock/clock-wheel cap may validly be
            // shorter than five seconds and must remain exact.
            $effectiveDuration = $queueRow->duration ?? 0.0;
            if (
                $effectiveDuration < 5.0
                && !$queueRow->hour_boundary_enforce_cap
                && !$queueRow->clock_wheel_enforce_cap
            ) {
                $naturalDuration = $queueRow->media?->getCalculatedLength() ?? 0.0;
                if ($naturalDuration >= 5.0) {
                    $queueRow->duration = $naturalDuration;
                    $effectiveDuration = $naturalDuration;
                } else {
                    $effectiveDuration = 5.0;
                }
            }

            if ($queueRow->sent_to_autodj) {
                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $queueRow->timestamp_cued,
                    $effectiveDuration
                );

                if (0 === $queueLength) {
                    $queueLength = 1;
                }
            } else {
                $queueRow->timestamp_cued = $expectedCueTime;
                $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $effectiveDuration);

                // Only append to queue length for uncued songs.
                $queueLength++;
            }

            $queueRow->timestamp_played = $expectedPlayTime;
            $this->em->persist($queueRow);

            $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $effectiveDuration);

            $lastSongId = $queueRow->song_id;
        }

        $this->em->flush();

        // Build the remainder of the queue.
        // A validator (e.g. DmcaComplianceListener) can reject a selector's pick by
        // clearing next songs; when that happens we re-dispatch a fresh BuildQueue event
        // so a selector gets another chance to choose a different track, instead of
        // silently halting queue-building and leaving the station with dead air.
        $maxAttemptsPerSlot = $isPreview ? 25 : (null !== $lookaheadMinutesOverride ? 50 : 10);
        $tracksBuiltThisRun = 0;
        $consecutivePreviewGapSeconds = 0;
        $maxPreviewGapSeconds = (max(
            60,
            $station->backend_config->duplicate_prevention_time_range,
            $station->backend_config->dmca_window_minutes ?? 180,
        ) + 60) * 60;

        while (
            $queueLength < $maxQueueLength
            || ($lookaheadHorizon !== null
                && $expectedPlayTime < $lookaheadHorizon
                && $tracksBuiltThisRun < $maxLookaheadTracks)
        ) {
            $nextSongs = [];
            $attempts = 0;

            while ($attempts < $maxAttemptsPerSlot) {
                $attempts++;

                $this->logger->debug(
                    'Adding to station queue.',
                    [
                        'now' => (string)$expectedPlayTime,
                        'attempt' => $attempts,
                    ]
                );

                // Push another test handler specifically for this one queue task.
                $testHandler = new TestHandler(LogLevel::DEBUG, true);
                $this->logger->pushHandler($testHandler);

                $event = new BuildQueue(
                    $station,
                    $expectedCueTime,
                    $expectedPlayTime,
                    $lastSongId
                );

                try {
                    $this->dispatcher->dispatch($event);
                } finally {
                    $this->logger->popHandler();
                }

                $nextSongs = $event->getNextSongs();

                // Hard backstop against the exact same ordinary song playing
                // twice in a row. Mandatory broadcast IDs are exempt: a station
                // with a one-ID library must still identify every hour, and a
                // Clock Wheel legal-ID substitute is likewise not ordinary music.
                // Only enforce this when a retry is actually possible so a
                // single-song music playlist cannot deadlock the queue.
                if (
                    !empty($nextSongs)
                    && $lastSongId !== null
                    && $attempts < $maxAttemptsPerSlot
                    && count($nextSongs) === 1
                    && !$this->isMandatoryBoundaryContent($nextSongs[0])
                    && $nextSongs[0]->song_id === $lastSongId
                ) {
                    $this->logger->debug(
                        'BuildQueue picked the same song as the immediately preceding slot; retrying.',
                        ['song_id' => $lastSongId, 'attempt' => $attempts]
                    );
                    $nextSongs = [];
                    continue;
                }

                if (!empty($nextSongs)) {
                    break;
                }

                $this->logger->debug(
                    'BuildQueue attempt produced no song (rejected by a validator); retrying.',
                    ['attempt' => $attempts]
                );
            }

            if (empty($nextSongs)) {
                if ($isPreview) {
                    $gapSeconds = 300;
                    $previewGaps[] = [
                        'started_at' => $expectedPlayTime->getTimestamp(),
                        'duration' => $gapSeconds,
                        'reason' => 'No eligible AutoDJ item was available for this projected slot.',
                    ];
                    $consecutivePreviewGapSeconds += $gapSeconds;

                    $this->logger->warning(
                        'Linear Log preview found no eligible item; advancing the projection cursor.',
                        [
                            'attempts' => $attempts,
                            'expected_play_time' => $expectedPlayTime->format(DateTimeInterface::ATOM),
                            'dry_seconds' => $consecutivePreviewGapSeconds,
                        ]
                    );

                    $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $gapSeconds);
                    $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $gapSeconds);

                    if ($consecutivePreviewGapSeconds >= $maxPreviewGapSeconds) {
                        $this->logger->warning(
                            'Linear Log preview stopped after the station remained dry beyond its compliance window.',
                            ['dry_seconds' => $consecutivePreviewGapSeconds]
                        );
                        $this->em->flush();
                        break;
                    }

                    continue;
                }

                $this->logger->warning(
                    'Could not find a compliant song for queue slot after max attempts; stopping queue build.',
                    ['attempts' => $attempts]
                );
                $this->em->flush();
                break;
            }

            $consecutivePreviewGapSeconds = 0;

            foreach ($nextSongs as $queueRow) {
                // Guard against a corrupt or not-yet-analyzed media duration while
                // preserving intentional sub-five-second wall-clock caps.
                $effectiveDuration = $queueRow->duration ?? 0.0;
                if (
                    $effectiveDuration < 5.0
                    && !$queueRow->hour_boundary_enforce_cap
                    && !$queueRow->clock_wheel_enforce_cap
                ) {
                    $naturalDuration = $queueRow->media?->getCalculatedLength() ?? 0.0;
                    if ($naturalDuration >= 5.0) {
                        $this->logger->warning(
                            'Queue: restoring natural media duration instead of collapsing the projected timeline.',
                            [
                                'song_id' => $queueRow->song_id,
                                'media_id' => $queueRow->media?->id,
                                'stored_duration' => $queueRow->duration,
                                'natural_duration' => $naturalDuration,
                            ]
                        );
                        $queueRow->duration = $naturalDuration;
                        $effectiveDuration = $naturalDuration;
                    } else {
                        $this->logger->warning(
                            'Queue: song has an implausibly short or missing duration; using a floor value to prevent queue timestamp collapse.',
                            [
                                'song_id' => $queueRow->song_id,
                                'media_id' => $queueRow->media?->id,
                                'duration' => $queueRow->duration,
                            ]
                        );
                        $effectiveDuration = 5.0;
                    }
                }

                $queueRow->timestamp_cued = $expectedCueTime;
                $queueRow->timestamp_played = $expectedPlayTime;
                $queueRow->updateVisibility();
                $this->em->persist($queueRow);
                $this->em->flush();

                if (!$isPreview) {
                    $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());
                }

                $lastSongId = $queueRow->song_id;

                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $expectedCueTime,
                    $effectiveDuration
                );
                $expectedPlayTime = $this->addDurationToTime(
                    $station,
                    $expectedPlayTime,
                    $effectiveDuration
                );

                $queueLength++;
                $tracksBuiltThisRun++;
            }
        }

        return $previewGaps;
    }

    /**
     * @param Station $station
     * @return StationQueue[]|null
     */
    public function getInterruptingQueue(Station $station): ?array
    {
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->notice('Cannot build queue: station does not support AutoDJ queue.');
            return null;
        }

        $tzObject = $station->getTimezoneObject();
        $expectedPlayTime = CarbonImmutable::now($tzObject);

        $this->logger->debug(
            'Fetching interrupting queue.',
            [
                'now' => (string)$expectedPlayTime,
            ]
        );

        // Push another test handler specifically for this one queue task.
        $testHandler = new TestHandler(LogLevel::DEBUG, true);
        $this->logger->pushHandler($testHandler);

        $event = new BuildQueue(
            $station,
            $expectedPlayTime,
            $expectedPlayTime,
            null,
            true
        );

        try {
            $this->dispatcher->dispatch($event);
        } finally {
            $this->logger->popHandler();
        }

        $nextSongs = $event->getNextSongs();

        if (empty($nextSongs)) {
            $this->em->flush();
            return null;
        }

        foreach ($nextSongs as $queueRow) {
            $queueRow->is_played = true;
            $queueRow->timestamp_cued = $expectedPlayTime;
            $queueRow->timestamp_played = $expectedPlayTime;
            $queueRow->updateVisibility();

            $this->em->persist($queueRow);
            $this->em->flush();

            $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $expectedPlayTime,
                $queueRow->duration
            );
        }

        return $nextSongs;
    }

    private function addDurationToTime(
        Station $station,
        DateTimeInterface $now,
        ?float $duration
    ): CarbonImmutable {
        $duration ??= 1;

        $startNext = $station->backend_config->getCrossfadeDuration();

        $now = CarbonImmutable::instance($now)->addSeconds($duration);
        return ($duration >= $startNext)
            ? $now->subMilliseconds((int)($startNext * 1000))
            : $now;
    }

    private function isMandatoryBoundaryContent(StationQueue $queueRow): bool
    {
        return $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute;
    }

    private function applyBroadcastClockCapToQueuedRow(
        Station $station,
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime,
    ): void {
        if ($this->isMandatoryBoundaryContent($queueRow)) {
            return;
        }

        $media = $queueRow->media;
        if (!$media instanceof StationMedia) {
            return;
        }

        // Hour-boundary caps are projections for one particular expected start.
        // If the queue is recalculated (clock correction, restart, previous track
        // changed, etc.), restore the row to its non-hour-boundary duration first.
        // Otherwise a former 1-5 second cap becomes permanent and every later row
        // appears only a few seconds apart in Upcoming Queue.
        if ($queueRow->hour_boundary_enforce_cap) {
            $naturalDuration = $media->getCalculatedLength();

            if (
                $queueRow->clock_wheel_enforce_cap
                && null !== $queueRow->clock_wheel_max_play_seconds
                && $queueRow->clock_wheel_max_play_seconds > 0
            ) {
                $queueRow->duration = min(
                    $naturalDuration,
                    (float)$queueRow->clock_wheel_max_play_seconds,
                );
            } elseif (
                null !== $queueRow->clock_wheel_stretch_ratio
                && $queueRow->clock_wheel_stretch_ratio > 0.0
            ) {
                $queueRow->duration = $naturalDuration / $queueRow->clock_wheel_stretch_ratio;
            } else {
                $queueRow->duration = $naturalDuration;
            }
        }

        $queueRow->hour_boundary_enforce_cap = false;
        $queueRow->hour_boundary_max_play_seconds = null;

        $maxDuration = $this->broadcastClockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $station,
            $expectedPlayTime,
        );
        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));
        $queueRow->hour_boundary_max_play_seconds = $targetSeconds;

        if ($media->getCalculatedLength() <= $targetSeconds) {
            return;
        }

        $queueRow->hour_boundary_enforce_cap = true;
        $queueRow->duration = null === $queueRow->duration
            ? (float)$targetSeconds
            : min($queueRow->duration, (float)$targetSeconds);
    }

    private function isQueueRowStillValid(
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime
    ): bool {
        // Mandatory boundary content must never be invalidated by a programme
        // ownership check. Its own planner decides whether the hour belongs to
        // the station-wide ID or a Clock Wheel legal-ID substitute.
        if ($this->isMandatoryBoundaryContent($queueRow)) {
            return true;
        }

        if (
            null !== $queueRow->request
            && $this->broadcastClockPlanner->areRequestsBlockedBySchedule(
                $queueRow->station,
                $expectedPlayTime,
            )
        ) {
            return false;
        }

        if (
            null !== $queueRow->clock_wheel
            && $this->broadcastClockPlanner->isProgramWindowActive(
                $queueRow->station,
                $expectedPlayTime,
            )
        ) {
            return false;
        }

        $playlist = $queueRow->playlist;
        if (null === $playlist) {
            return true;
        }

        if (
            !$playlist->is_enabled
            || !$this->scheduler->isPlaylistScheduledToPlayNow(
                $playlist,
                $expectedPlayTime,
                true
            )
        ) {
            return false;
        }

        return !$this->broadcastClockPlanner->isPlaylistPreemptedByProgram(
            $playlist,
            $expectedPlayTime,
        );
    }
}
