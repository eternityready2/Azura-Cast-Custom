<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PodcastSources;
use App\Entity\PodcastEpisode;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Service\StationDiagnostics;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Reconciles successful runtime actions that are persisted outside the custom
 * diagnostics log into the same station-scoped evidence stream used by Logs.
 */
final class StationDiagnosticsRuntimeReconcileTask extends AbstractTask
{
    private const int LOOKBACK_SECONDS = 15 * 60;
    private const int CURSOR_TTL_SECONDS = 7 * 86400;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '*/5 * * * *';
    }

    public function run(bool $force = false): void
    {
        $now = time();

        foreach ($this->iterateStations() as $station) {
            $cursorKey = 'station_diagnostics_runtime_cursor_' . $station->id;
            $since = $force
                ? $now - self::LOOKBACK_SECONDS
                : (int)($this->cache->get($cursorKey, $now - self::LOOKBACK_SECONDS));
            $since = max($now - 86400, min($since, $now));

            try {
                $this->reconcileAiDj($station, $since, $now);
                $this->reconcileRssImports($station, $since, $now);
                $this->cache->set($cursorKey, $now, self::CURSOR_TTL_SECONDS);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Station diagnostics runtime reconciliation failed.',
                    [
                        'station_id' => $station->id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    private function reconcileAiDj(Station $station, int $since, int $until): void
    {
        $start = $this->utcDate($since);
        $end = $this->utcDate($until);

        /** @var list<StationQueue> $rows */
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT q
                FROM App\Entity\StationQueue q
                WHERE q.station = :station
                  AND q.timestamp_cued > :start
                  AND q.timestamp_cued <= :end
                  AND q.autodj_custom_uri LIKE :pattern
                ORDER BY q.timestamp_cued ASC
            DQL
        )
            ->setParameter('station', $station)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('pattern', '%/ai_dj/%')
            ->getResult();

        foreach ($rows as $queue) {
            $this->diagnostics->info(
                $station,
                'ai dj',
                'AI DJ audio clip queued successfully.',
                [
                    'queue_id' => $queue->id,
                    'dj_name' => $queue->artist,
                    'clip_type' => $queue->title,
                    'clip_file' => null !== $queue->autodj_custom_uri
                        ? basename($queue->autodj_custom_uri)
                        : null,
                    'queued_at' => $queue->timestamp_cued->getTimestamp(),
                ]
            );
        }
    }

    private function reconcileRssImports(Station $station, int $since, int $until): void
    {
        /** @var list<PodcastEpisode> $episodes */
        $episodes = $this->em->createQuery(
            <<<'DQL'
                SELECT e
                FROM App\Entity\PodcastEpisode e
                JOIN e.podcast p
                WHERE p.storage_location = :storage
                  AND p.source = :source
                  AND e.created_at > :start
                  AND e.created_at <= :end
                ORDER BY e.created_at ASC
            DQL
        )
            ->setParameter('storage', $station->podcasts_storage_location)
            ->setParameter('source', PodcastSources::Import->value)
            ->setParameter('start', $since)
            ->setParameter('end', $until)
            ->getResult();

        foreach ($episodes as $episode) {
            if (null === $episode->media && null === $episode->playlist_media) {
                continue;
            }

            $podcast = $episode->podcast;
            $this->diagnostics->info(
                $station,
                'rss podcast',
                'RSS podcast episode import completed.',
                [
                    'podcast_id' => $podcast->id,
                    'podcast_title' => $podcast->title,
                    'episode_id' => $episode->id,
                    'episode_title' => $episode->title,
                    'created_at' => $episode->created_at,
                ]
            );
        }
    }

    private function utcDate(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
