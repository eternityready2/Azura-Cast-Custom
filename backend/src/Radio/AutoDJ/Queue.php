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
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->info('Cannot build queue: station does not support AutoDJ queue.');
            return;
        }

        $expectedCueTime = Time::nowUtc();
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
        $lookaheadMinutes = $lookaheadMinutesOverride
            ?? $station->backend_config->autodj_queue_lookahead_minutes;
        $lookaheadHorizon = $lookaheadMinutes > 0
            ? Time::nowUtc()->modify('+' . $lookaheadMinutes . ' minutes')
            : null;
        $maxLookaheadTracks = $maxTracksOverride ?? 500;

        $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);
        $lastSongId = null;
        $queueLength = 0;

        foreach ($upcomingQueue as $queueRow) {
            $effectiveDuration = $this->getEffectiveQueueDuration($queueRow);

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
                if (!$this->isQueueRowStillValid($queueRow, $expectedPlayTime)) {
                    $this->em->remove($queueRow);
                    continue;
                }

                $queueRow->timestamp_cued = $expectedCueTime;
                $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $effectiveDuration);
                $queueLength++;
            }

            $queueRow->timestamp_played = $expectedPlayTime;
            $this->em->persist($queueRow);

            $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $effectiveDuration);
            $lastSongId = $queueRow->song_id;
        }

        $this->em->flush();

        $maxAttemptsPerSlot = null !== $lookaheadMinutesOverride ? 50 : 10;
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

                if (
                    !empty($nextSongs)
                    && $lastSongId !== null
                    && $attempts < $maxAttemptsPerSlot
                    && count($nextSongs) === 1
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
                $this->logger->warning(
                    'Could not find a compliant song for queue slot after max attempts; stopping queue build.',
                    ['attempts' => $attempts]
                );
                $this->em->flush();
                break;
            }

            foreach ($nextSongs as $queueRow) {
                $effectiveDuration = $this->getEffectiveQueueDuration($queueRow);

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
    }

    /** @return StationQueue[]|null */
    public function getInterruptingQueue(Station $station): ?array
    {
        if (!$station->supportsAutoDjQueue()) {
            $this->logger->notice('Cannot build queue: station does not support AutoDJ queue.');
            return null;
        }

        $tzObject = $station->getTimezoneObject();
        $expectedPlayTime = CarbonImmutable::now($tzObject);

        $this->logger->debug(
            'Fetching interrupting queue.',
            ['now' => (string)$expectedPlayTime]
        );

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
            // A planned TOH row remains unplayed until the dedicated Liquidsoap
            // queue accepts it. This lets the next real-time tick retry safely if
            // enqueueing fails instead of silently consuming the compliance item.
            if (!$queueRow->top_of_hour_legal_id) {
                $queueRow->is_played = true;
            }

            $queueRow->timestamp_cued = $expectedPlayTime;
            $queueRow->timestamp_played = $expectedPlayTime;
            $queueRow->updateVisibility();

            $this->em->persist($queueRow);
            $this->em->flush();

            $this->queueLogCache->setLog($queueRow, $testHandler->getRecords());

            $expectedPlayTime = $this->addDurationToTime(
                $station,
                $expectedPlayTime,
                $this->getEffectiveQueueDuration($queueRow)
            );
        }

        return $nextSongs;
    }

    /**
     * Liquidsoap stretch ratio is output duration divided by source duration.
     */
    private function getEffectiveQueueDuration(StationQueue $queueRow): float
    {
        $duration = $queueRow->duration ?? 0.0;
        $stretchRatio = $queueRow->clock_wheel_stretch_ratio;

        if ($duration > 0.0 && null !== $stretchRatio && $stretchRatio > 0.0) {
            $duration *= $stretchRatio;
        }

        if ($duration < 5.0) {
            $this->logger->warning(
                'Queue: song has an implausibly short or missing duration; using a floor value.',
                [
                    'song_id' => $queueRow->song_id,
                    'media_id' => $queueRow->media?->id,
                    'duration' => $queueRow->duration,
                    'stretch_ratio' => $stretchRatio,
                ]
            );
            return 5.0;
        }

        return $duration;
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

        return $playlist->is_enabled
            && $this->scheduler->isPlaylistScheduledToPlayNow(
                $playlist,
                $expectedPlayTime,
                true
            );
    }
}
