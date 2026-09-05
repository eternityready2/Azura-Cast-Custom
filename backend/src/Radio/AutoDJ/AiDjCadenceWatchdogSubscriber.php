<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents configured AI DJs from developing abnormally long silent stretches.
 *
 * This subscriber only advances the existing cadence credit. AiDjQueueListener
 * remains authoritative for every playback safety check and for clip generation.
 */
final class AiDjCadenceWatchdogSubscriber implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private const int STATE_TTL_SECONDS = 12 * 3600;

    /**
     * Frequency-scaled silence ceiling. At the normal 50% setting this is 15
     * minutes; lower-frequency DJs receive proportionally larger ceilings.
     */
    private const int MAX_SILENCE_BASE_SECONDS = 450;

    private const int MIN_MAX_SILENCE_SECONDS = 900;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run before AiDjQueueListener (priority 1). This only adjusts cache state;
        // it never queues audio or competes with top-of-hour scheduling.
        return [
            BuildQueue::class => ['onBuildQueue', 2],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        $station = $event->getStation();
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $dj = $this->scheduler->findActiveDj($station->id, $now);

        if (!$dj instanceof AiDj) {
            return;
        }

        $frequency = $dj->getTalkFrequency();
        if ($frequency <= 0.0) {
            return;
        }

        $lastBreakAt = $this->getLastBreakTimestamp($station->id, $dj->getName());
        $startedKey = 'ai_dj_cadence_watch_started_' . $station->id . '_' . $dj->getId();

        if ($lastBreakAt === null || (time() - $lastBreakAt) > self::STATE_TTL_SECONDS) {
            $startedAt = (int)($this->cache->get($startedKey) ?? 0);
            if ($startedAt === 0) {
                $this->cache->set($startedKey, time(), self::STATE_TTL_SECONDS);
                return;
            }
            $lastBreakAt = $startedAt;
        } else {
            $this->cache->set($startedKey, $lastBreakAt, self::STATE_TTL_SECONDS);
        }

        $maxSilence = max(
            self::MIN_MAX_SILENCE_SECONDS,
            (int)ceil(self::MAX_SILENCE_BASE_SECONDS / $frequency),
        );
        $silenceSeconds = time() - $lastBreakAt;

        if ($silenceSeconds < $maxSilence) {
            return;
        }

        $cadenceKey = 'ai_dj_talk_cadence_' . $station->id . '_' . $dj->getId();
        $currentCredit = (float)($this->cache->get($cadenceKey) ?? 0.0);

        if ($currentCredit < 1.0) {
            $this->cache->set($cadenceKey, 1.0, self::STATE_TTL_SECONDS);
            $this->logger->info('AI DJ: Cadence watchdog advanced an overdue talk break.', [
                'dj_name' => $dj->getName(),
                'silence_seconds' => $silenceSeconds,
                'max_silence_seconds' => $maxSilence,
                'frequency' => $frequency,
            ]);
        }
    }

    private function getLastBreakTimestamp(int $stationId, string $djName): ?int
    {
        try {
            $lastBreak = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq FROM App\Entity\StationQueue sq
                    WHERE sq.station_id = :station_id
                    AND sq.media IS NULL
                    AND sq.artist = :dj_name
                    AND sq.autodj_custom_uri IS NOT NULL
                    AND sq.timestamp_played IS NOT NULL
                    ORDER BY sq.timestamp_played DESC
                DQL
            )->setParameter('station_id', $stationId)
                ->setParameter('dj_name', $djName)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (!$lastBreak instanceof StationQueue || $lastBreak->timestamp_played === null) {
                return null;
            }

            return $lastBreak->timestamp_played->getTimestamp();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Cadence watchdog lookup failed: %s', $e->getMessage()));
            return null;
        }
    }
}
