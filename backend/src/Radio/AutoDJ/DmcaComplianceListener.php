<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * DMCA Digital Performance Compliance Listener
 *
 * Enforces SoundExchange / DMCA statutory license rules for internet radio.
 * All limits are configurable via Station Settings → Broadcasting.
 *
 * Rules enforced:
 *   1. No more than N plays of the same song in any rolling 3-hour window.
 *   2. No more than N consecutive plays of the same song.
 *   3. No more than N songs from the same album in any rolling 3-hour window.
 *   4a. No more than N songs by the same artist in any rolling 3-hour window.
 *   4b. No more than N consecutive songs by the same artist.
 *
 * Fail-safe: if history cannot be read, the song is allowed — never blocks
 * playback due to its own errors. All rejections are logged with full detail.
 *
 * Priority -5: runs AFTER selectors (QueueBuilder / ClockWheel / etc.) pick a
 * song. Selectors must not stopPropagation on success so this listener can
 * clear a violating pick via setNextSongs(null); the next AutoDJ BuildQueue
 * attempt then retries with a different track.
 */
final class DmcaComplianceListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    // Default DMCA statutory limits — overridden by station settings if configured.
    public const int DEFAULT_WINDOW_MINUTES               = 180;
    public const int DEFAULT_MAX_SONG_PLAYS               = 3;
    public const int DEFAULT_MAX_CONSECUTIVE_SONG_PLAYS   = 2;
    public const int DEFAULT_MAX_ALBUM_PLAYS              = 3;
    public const int DEFAULT_MAX_ARTIST_PLAYS             = 4;
    public const int DEFAULT_MAX_CONSECUTIVE_ARTIST_PLAYS = 3;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly LinearLogPreviewContext $previewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', -5],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station = $event->getStation();

        // Check if DMCA compliance is enabled for this station.
        if (!$station->backend_config->dmca_compliance_enabled) {
            return;
        }

        $nextSongs = $event->getNextSongs();

        if (empty($nextSongs)) {
            return;
        }

        foreach ($nextSongs as $queueEntry) {
            if (!$this->isCompliant(
                $queueEntry,
                $station,
                $event->getExpectedPlayTime(),
                $this->previewContext->isActive(),
            )) {
                $event->setNextSongs(null); // clear selection so AutoDJ retries with a different track

                $this->logger->warning(
                    'DMCA Compliance: Rejected song — BuildQueue will retry with a different track.',
                    [
                        'station' => $station->name,
                        'song_id' => $queueEntry->song_id,
                        'title'   => $queueEntry->title,
                        'artist'  => $queueEntry->artist,
                        'album'   => $queueEntry->album,
                    ]
                );

                return;
            }
        }
    }

    private function isCompliant(
        StationQueue $entry,
        Station $station,
        \DateTimeImmutable $expectedPlayTime,
        bool $isPreview,
    ): bool {

         if ($entry->media?->type !== 'music') { return true; }
        $config = $station->backend_config;

        $windowMinutes           = $config->dmca_window_minutes           ?? self::DEFAULT_WINDOW_MINUTES;
        $maxSongPlays            = $config->dmca_max_song_plays            ?? self::DEFAULT_MAX_SONG_PLAYS;
        $maxConsecutiveSong      = $config->dmca_max_consecutive_song      ?? self::DEFAULT_MAX_CONSECUTIVE_SONG_PLAYS;
        $maxAlbumPlays           = $config->dmca_max_album_plays           ?? self::DEFAULT_MAX_ALBUM_PLAYS;
        $maxArtistPlays          = $config->dmca_max_artist_plays          ?? self::DEFAULT_MAX_ARTIST_PLAYS;
        $maxConsecutiveArtist    = $config->dmca_max_consecutive_artist    ?? self::DEFAULT_MAX_CONSECUTIVE_ARTIST_PLAYS;

        try {
            $history = $isPreview
                ? $this->queueRepo->getProjectedMusicHistoryByTimeRange(
                    $station,
                    $expectedPlayTime,
                    $windowMinutes
                )
                : $this->queueRepo->getPlayedMusicHistoryByTimeRange(
                    $station,
                    $expectedPlayTime,
                    $windowMinutes
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'DMCA Compliance: Could not read play history — allowing song as fail-safe.',
                ['error' => $e->getMessage()]
            );
            return true;
        }

        $songId = $entry->song_id;
        $album  = $entry->album ?? null;
        $artist = $entry->artist ?? null;

        // Rule 1: Max plays of same song in rolling window.
        $songPlays = 0;
        foreach ($history as $row) {
            if ($row['song_id'] === $songId) {
                $songPlays++;
            }
        }

        if ($songPlays >= $maxSongPlays) {
            $this->logger->info('DMCA Compliance: Rejected — song play limit reached.', [
                'title' => $entry->title, 'artist' => $artist,
                'plays' => $songPlays, 'limit' => $maxSongPlays,
            ]);
            return false;
        }

        // Rule 2: Max consecutive plays of same song.
        $consecutiveSong = 0;
        foreach ($history as $row) {
            if ($row['song_id'] === $songId) {
                $consecutiveSong++;
            } else {
                break;
            }
        }

        if ($consecutiveSong >= $maxConsecutiveSong) {
            $this->logger->info('DMCA Compliance: Rejected — consecutive song play limit reached.', [
                'title' => $entry->title, 'artist' => $artist,
                'consecutive' => $consecutiveSong, 'limit' => $maxConsecutiveSong,
            ]);
            return false;
        }

        // Rule 3: Max plays from same album in rolling window.
        if (!empty($album)) {
            $albumPlays = 0;
            foreach ($history as $row) {
                $rowAlbum = $row['album'] ?? null;
                if (!empty($rowAlbum) && strtolower($rowAlbum) === strtolower($album)) {
                    $albumPlays++;
                }
            }

            if ($albumPlays >= $maxAlbumPlays) {
                $this->logger->info('DMCA Compliance: Rejected — album play limit reached.', [
                    'title' => $entry->title, 'album' => $album,
                    'album_plays' => $albumPlays, 'limit' => $maxAlbumPlays,
                ]);
                return false;
            }
        } else {
            $this->logger->debug('DMCA Compliance: Rule 3 (album) skipped — no album tag on this track.', [
                'title' => $entry->title, 'artist' => $artist,
            ]);
        }

        // Rule 4a: Max plays by same artist in rolling window.
        if (!empty($artist)) {
            $artistPlays = 0;
            foreach ($history as $row) {
                $rowArtist = $row['artist'] ?? null;
                if (!empty($rowArtist) && strtolower($rowArtist) === strtolower($artist)) {
                    $artistPlays++;
                }
            }

            if ($artistPlays >= $maxArtistPlays) {
                $this->logger->info('DMCA Compliance: Rejected — artist play limit reached.', [
                    'title' => $entry->title, 'artist' => $artist,
                    'artist_plays' => $artistPlays, 'limit' => $maxArtistPlays,
                ]);
                return false;
            }

            // Rule 4b: Max consecutive plays by same artist.
            $consecutiveArtist = 0;
            foreach ($history as $row) {
                $rowArtist = $row['artist'] ?? null;
                if (!empty($rowArtist) && strtolower($rowArtist) === strtolower($artist)) {
                    $consecutiveArtist++;
                } else {
                    break;
                }
            }

            if ($consecutiveArtist >= $maxConsecutiveArtist) {
                $this->logger->info('DMCA Compliance: Rejected — consecutive artist play limit reached.', [
                    'title' => $entry->title, 'artist' => $artist,
                    'consecutive' => $consecutiveArtist, 'limit' => $maxConsecutiveArtist,
                ]);
                return false;
            }
        } else {
            $this->logger->debug('DMCA Compliance: Rules 4a/4b (artist) skipped — no artist tag on this track.', [
                'title' => $entry->title,
            ]);
        }

        return true;
    }
}
