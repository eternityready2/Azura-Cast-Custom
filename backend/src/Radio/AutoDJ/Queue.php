<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Cache\QueueLogCache;
use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\HourBoundaryPlanner;
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
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly QueueLogCache $queueLogCache
    ) {
    }

    /**
     * @param int|null $lookaheadMinutesOverride When given, builds forward to at least
     *        this many minutes regardless of the station's configured
     *        `autodj_queue_lookahead_minutes`. Used by the linear-log builder to project
     *        a full day ahead on demand without changing the station's live setting.
     * @param int|null $maxTracksOverride Safety cap override to match a larger horizon.
     */
    public function buildQueue(
        Station $station,
        ?int $lookaheadMinutesOverride = null,
        ?int $maxTracksOverride = null,
    ): void {
        // Early-fail if the station is disabled.
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->info('Cannot build queue: station does not support AutoDJ queue.');
            return;
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
            if ($queueRow->sent_to_autodj) {
                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $queueRow->timestamp_cued,
                    $queueRow->duration
                );

                if (0 === $queueLength) {
                    $queueLength = 1;
                }
            } else {
                if (!$this->isQueueRowStillValid($queueRow, $expectedPlayTime)) {
                    $this->em->remove($queueRow);
                    continue;
                }

                // Advance overflow detection: even if this playlist is still
                // scheduled, the track may now be too long for the time remaining
                // before a boundary (top-of-hour or another scheduled start).
                // Drop it here so the build loop below re-picks a fitting
                // replacement with full boundary awareness. This is what
                // professional radio software calls "song fitting" — detected
                // well in advance (as far as the lookahead horizon reaches),
                // not only in the final seconds before :00.
                // Only applies to unsent, unplayed rows with a playlist so that
                // station IDs, requests, and clock-wheel entries are never
                // silently dropped.
                if (
                    $queueRow->playlist !== null
                    && !$queueRow->sent_to_autodj
                    && $this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)
                ) {
                    $maxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
                        $station,
                        $expectedPlayTime,
                    );

                    if (
                        $maxDuration !== null
                        && ($queueRow->duration ?? 0.0) > $maxDuration
                        && $queueRow->clock_wheel_stretch_ratio === null
                    ) {
                        $this->logger->info(
                            'Queue: dropping pre-queued track that can no longer fit before top-of-hour boundary; will re-pick.',
                            [
                                'song_id' => $queueRow->song_id,
                                'duration' => $queueRow->duration,
                                'max_duration' => $maxDuration,
                                'expected_play_time' => $expectedPlayTime->format('H:i:s'),
                            ]
                        );
                        $this->em->remove($queueRow);
                        continue;
                    }
                }

                $queueRow->timestamp_cued = $expectedCueTime;
                $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $queueRow->duration);

                // Only append to queue length for uncued songs.
                $queueLength++;
            }

            $queueRow->timestamp_played = $expectedPlayTime;
            $this->em->persist($queueRow);

            $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $queueRow->duration);

            $lastSongId = $queueRow->song_id;
        }

        $this->em->flush();

        // Build the remainder of the queue.
        // A validator (e.g. DmcaComplianceListener) can reject a selector's pick by
        // clearing next songs; when that happens we re-dispatch a fresh BuildQueue event
        // so a selector gets another chance to choose a different track, instead of
        // silently halting queue-building and leaving the station with dead air.
        $maxAttemptsPerSlot = 10;
        $tracksBuiltThisRun = 0;

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

                if (!empty($nextSongs)) {
                    break;
                }

                $this->logger->debug(
                    'BuildQueue attempt produced no song (rejected by a validator); retrying.',
                    ['attempt' => $attempts]
                );
            }

            if (empty($nextSongs)) {
                $this->logger->warning(
                    'Could not find a compliant song for queue slot after max attempts; stopping queue build.',
                    ['attempts' => $attempts]
                );
                $this->em->flush();
                break;
            }

            foreach ($nextSongs as $queueRow) {
                $queueRow->timestamp_cued = $expectedCueTime;
                $queueRow->timestamp_played = $expectedPlayTime;
                $queueRow->updateVisibility();
                $this->em->persist($queueRow);
                $this->em->flush();

                $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

                $lastSongId = $queueRow->song_id;

                $expectedCueTime = $this->addDurationToTime(
                    $station,
                    $expectedCueTime,
                    $queueRow->duration
                );
                $expectedPlayTime = $this->addDurationToTime(
                    $station,
                    $expectedPlayTime,
                    $queueRow->duration
                );

                $queueLength++;
                $tracksBuiltThisRun++;
            }
        }
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

    private function isQueueRowStillValid(
        StationQueue $queueRow,
        DateTimeImmutable $expectedPlayTime
    ): bool {
        $playlist = $queueRow->playlist;
        if (null === $playlist) {
            return true;
        }

        return $playlist->is_enabled &&
            $this->scheduler->isPlaylistScheduledToPlayNow(
                $playlist,
                $expectedPlayTime,
                true
            );
    }
}
