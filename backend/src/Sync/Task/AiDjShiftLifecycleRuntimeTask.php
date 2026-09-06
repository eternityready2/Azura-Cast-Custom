<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Radio\AutoDJ\AiDjShiftLifecycleListener;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Gives AI DJ shift lifecycle a wall-clock heartbeat independent of AutoDJ queue depth.
 *
 * The normal AI DJ listener is intentionally tied to BuildQueue events, which may be
 * sparse when the live queue is already filled well ahead of airtime. Shift welcomes
 * and sign-offs are clock events, so they also need a real-time path that runs even
 * when no new music row happens to be built during their narrow safe window.
 */
final class AiDjShiftLifecycleRuntimeTask extends AbstractTask
{
    private const int WELCOME_RECOVERY_SECONDS = 3600;

    public function __construct(
        private readonly AiDjShiftLifecycleListener $lifecycleListener,
        private readonly AiDjQueueListener $queueListener,
        private readonly AiDjScheduler $scheduler,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $now = new DateTimeImmutable('now', $station->getTimezoneObject());
            $event = new BuildQueue($station, $now, $now);

            // First give the existing lifecycle owner a guaranteed once-per-minute
            // opportunity to queue a pending sign-off and synchronize shift state.
            $this->lifecycleListener->onBuildQueue($event);

            $schedule = $this->scheduler->findActiveSchedule($station->id, $now);
            if (!$schedule instanceof AiDjSchedule) {
                continue;
            }

            $dj = $schedule->getAiDj();
            if (!$dj instanceof AiDj) {
                continue;
            }

            $shift = $this->scheduler->getShiftWindow($station, $schedule, $now);
            $startsAt = $shift['starts_at'];
            $endsAt = $shift['ends_at'];
            $welcomeRecoveryEndsAt = min(
                $endsAt->getTimestamp(),
                $startsAt->getTimestamp() + self::WELCOME_RECOVERY_SECONDS,
            );

            if (
                $now < $startsAt
                || $now->getTimestamp() >= $welcomeRecoveryEndsAt
                || $this->hasDurableWelcome($station, $dj, $startsAt, $endsAt)
            ) {
                continue;
            }

            // A successful welcome always writes a durable queue/history marker. If
            // that marker is still absent, do not let a stale cache flag suppress a
            // retry. Reset the transition marker so the existing listener takes its
            // normal, fully safety-checked welcome path instead of ordinary chatter.
            $this->cache->delete('ai_dj_welcomed_' . $station->id . '_' . $dj->getId());
            $this->cache->delete('ai_dj_last_active_' . $station->id);

            $this->queueListener->onBuildQueue($event);
        }
    }

    private function hasDurableWelcome(
        Station $station,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): bool {
        try {
            $utc = new DateTimeZone('UTC');
            $startsAtUtc = $startsAt->setTimezone($utc);
            $endsAtUtc = $endsAt->setTimezone($utc);

            $queueCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(q.id) FROM App\Entity\StationQueue q
                    WHERE q.station = :station
                    AND q.artist = :artist
                    AND q.title = :title
                    AND q.timestamp_cued >= :startsAt
                    AND q.timestamp_cued < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', 'AI DJ Welcome')
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            if ($queueCount > 0) {
                return true;
            }

            $historyCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(sh.id) FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.artist = :artist
                    AND sh.title = :title
                    AND sh.timestamp_start >= :startsAt
                    AND sh.timestamp_start < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', 'AI DJ Welcome')
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            return $historyCount > 0;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Runtime welcome marker lookup failed.', [
                'station_id' => $station->id,
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);

            // Fail closed here: marker lookup uncertainty must never create a
            // duplicate welcome on air. The ordinary BuildQueue path remains active.
            return true;
        }
    }
}
