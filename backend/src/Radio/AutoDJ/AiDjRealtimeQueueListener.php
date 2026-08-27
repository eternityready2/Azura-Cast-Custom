<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Psr\SimpleCache\CacheInterface;

/**
 * Runs one AI DJ decision per actual on-air item, using real wall-clock time.
 */
final class AiDjRealtimeQueueListener
{
    private const int SEEN_ITEM_TTL_SECONDS = 21600;

    public function __construct(
        private readonly AiDjQueueListener $delegate,
        private readonly CacheInterface $cache,
    ) {
    }

    public function run(Station $station): void
    {
        $current = $station->current_song;
        if (null === $current || null === $current->timestamp_start) {
            return;
        }

        $fingerprint = $current->song_id . ':' . $current->timestamp_start->getTimestamp();
        $cacheKey = 'ai_dj_realtime_item_' . $station->id;

        if ($this->cache->get($cacheKey) === $fingerprint) {
            return;
        }

        // Claim this item before generation so parallel Now Playing workers cannot
        // roll talk_frequency twice for the same song/program/liner.
        $this->cache->set($cacheKey, $fingerprint, self::SEEN_ITEM_TTL_SECONDS);

        $now = Time::nowUtc()->toDateTimeImmutable();
        $this->delegate->onBuildQueue(
            new BuildQueue($station, $now, $now, $current->song_id, false)
        );
    }
}
