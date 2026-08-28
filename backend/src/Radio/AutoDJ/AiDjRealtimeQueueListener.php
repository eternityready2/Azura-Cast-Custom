<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class AiDjRealtimeQueueListener
{
    use LoggerAwareTrait;

    private const int SEEN_ITEM_TTL_SECONDS = 21600;
    private const int ATTEMPT_TTL_SECONDS = 30;

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

        $this->cache->set($cacheKey, $fingerprint, self::ATTEMPT_TTL_SECONDS);

        $handled = false;
        try {
            $now = Time::nowUtc()->toDateTimeImmutable();
            $handled = $this->delegate->onBuildQueue(
                new BuildQueue($station, $now, $now, $current->song_id, false)
            );
        } catch (Throwable $e) {
            $this->logger->error('AI DJ realtime decision failed.', [
                'exception' => $e->getMessage(),
            ]);
        } finally {
            if ($handled) {
                $this->cache->set($cacheKey, $fingerprint, self::SEEN_ITEM_TTL_SECONDS);
            } else {
                $this->cache->delete($cacheKey);
            }
        }
    }
}
