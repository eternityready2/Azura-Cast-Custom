<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rejects music picks that would make a routine TOH fade inevitable.
 *
 * QueueBuilder already prefers a natural fit and bounded +/-5% stretch. This
 * validator adds the missing multi-song lookahead: a track is accepted only if
 * it can either land at the handoff itself or leave a remainder that can be
 * filled by up to two normal music tracks. Rejected picks are retried through
 * the normal playlist/duplicate/DMCA selection pipeline.
 */
final class TopOfHourMusicGuard implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private const float LANDING_TOLERANCE_SECONDS = 8.0;
    private const int MAX_CANDIDATE_SECONDS = 900;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly Scheduler $scheduler,
        private readonly ClockWheelStretchCalculator $stretchCalculator,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', -4],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        $rows = $event->getNextSongs();
        if ($rows === []) {
            return;
        }

        $station = $event->getStation();
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $station,
            $event->getExpectedPlayTime(),
        );

        if (null === $availableSeconds || $availableSeconds <= 0.0) {
            return;
        }

        foreach ($rows as $row) {
            if (!$this->isNormalMusic($row)) {
                continue;
            }

            if ($row->top_of_hour_pre_id_fade) {
                $this->reject($event, $row, $availableSeconds, 'routine TOH cue-out/fade would be required');
                return;
            }

            $effectiveSeconds = $this->getEffectiveTimelineSeconds($station, $row);
            if ($effectiveSeconds > $availableSeconds + self::LANDING_TOLERANCE_SECONDS) {
                $this->reject($event, $row, $availableSeconds, 'track would overrun the protected handoff');
                return;
            }

            $remaining = max(0.0, $availableSeconds - $effectiveSeconds);
            if ($remaining <= self::LANDING_TOLERANCE_SECONDS) {
                continue;
            }

            $candidateLengths = $this->getFutureMusicLengths(
                $station,
                $event->getExpectedPlayTime(),
            );

            // If we cannot inspect the active pool, preserve normal fail-open
            // behavior. The explicit fade/overrun checks above still apply.
            if ($candidateLengths === []) {
                continue;
            }

            if (!$this->canFillRemainder($station, $remaining, $candidateLengths)) {
                $this->reject(
                    $event,
                    $row,
                    $availableSeconds,
                    'track would strand an unfillable gap before the legal ID',
                    $remaining,
                );
                return;
            }
        }
    }

    private function reject(
        BuildQueue $event,
        StationQueue $row,
        float $availableSeconds,
        string $reason,
        ?float $remainingSeconds = null,
    ): void {
        $event->setNextSongs(null);

        $this->logger->info('Top of hour backtime: rejected music pick; AutoDJ will retry.', [
            'queue_song_id' => $row->song_id,
            'media_id' => $row->media?->id,
            'title' => $row->title,
            'available_seconds' => round($availableSeconds, 2),
            'remaining_seconds' => null === $remainingSeconds ? null : round($remainingSeconds, 2),
            'reason' => $reason,
        ]);
    }

    private function isNormalMusic(StationQueue $row): bool
    {
        if ($row->top_of_hour_legal_id || null !== $row->clock_wheel) {
            return false;
        }

        $media = $row->media;
        if (null === $media || StationMediaTypes::isStationId($media->type)) {
            return false;
        }

        return 'music' === ($media->type ?? 'music');
    }

    private function getEffectiveTimelineSeconds(Station $station, StationQueue $row): float
    {
        $duration = $row->duration ?? $row->media?->getCalculatedLength() ?? 0.0;
        $ratio = $row->clock_wheel_stretch_ratio;

        if ($duration > 0.0 && null !== $ratio && $ratio > 0.0) {
            $duration *= $ratio;
        }

        $crossfade = max(0.0, $station->backend_config->getCrossfadeDuration());
        return ($duration >= $crossfade)
            ? max(0.0, $duration - $crossfade)
            : $duration;
    }

    /**
     * @return float[]
     */
    private function getFutureMusicLengths(
        Station $station,
        \DateTimeImmutable $expectedPlayTime,
    ): array {
        $targetTop = $this->hourBoundaryPlanner->getNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );
        $cacheKey = 'toh_backtime_pool_' . $station->id . '_' . $targetTop->getTimestamp();
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_numeric'));
        }

        $lengths = [];

        try {
            foreach ($station->playlists as $playlist) {
                if (!$playlist instanceof StationPlaylist) {
                    continue;
                }
                if (!$playlist->is_enabled || PlaylistSources::Songs !== $playlist->source) {
                    continue;
                }
                if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime)) {
                    continue;
                }

                foreach ($playlist->media_items as $playlistMedia) {
                    $media = $playlistMedia->media;
                    if (StationMediaTypes::isStationId($media->type)) {
                        continue;
                    }
                    if ('music' !== ($media->type ?? 'music')) {
                        continue;
                    }

                    $length = $media->getCalculatedLength();
                    if ($length <= 0.0 || $length > self::MAX_CANDIDATE_SECONDS) {
                        continue;
                    }

                    // One-second buckets remove duplicates while retaining enough
                    // timing precision for +/-5% stretch and an 8-second landing.
                    $lengths[(int)round($length)] = $length;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Top of hour backtime: could not inspect future music pool.', [
                'exception' => $e->getMessage(),
            ]);
            return [];
        }

        ksort($lengths, SORT_NUMERIC);
        $result = array_values($lengths);
        $this->cache->set($cacheKey, $result, 900);

        return $result;
    }

    /**
     * Can the remainder be filled by one or two future songs without cutting
     * either song? The final song may use the existing pitch-preserving +/-5%
     * stretch engine to close a small timing difference.
     *
     * @param float[] $candidateLengths
     */
    private function canFillRemainder(
        Station $station,
        float $remaining,
        array $candidateLengths,
    ): bool {
        $crossfade = max(0.0, $station->backend_config->getCrossfadeDuration());

        foreach ($candidateLengths as $length) {
            if ($this->canLandTrackAtBoundary($length, $remaining, $crossfade)) {
                return true;
            }
        }

        foreach ($candidateLengths as $firstLength) {
            $firstTimelineSeconds = $this->timelineSecondsForLength($firstLength, $crossfade);
            if ($firstTimelineSeconds >= $remaining - self::LANDING_TOLERANCE_SECONDS) {
                continue;
            }

            $secondRemainder = $remaining - $firstTimelineSeconds;
            foreach ($candidateLengths as $secondLength) {
                if ($this->canLandTrackAtBoundary($secondLength, $secondRemainder, $crossfade)) {
                    return true;
                }

                if ($this->timelineSecondsForLength($secondLength, $crossfade)
                    > $secondRemainder + self::LANDING_TOLERANCE_SECONDS) {
                    // Candidate lengths are sorted ascending.
                    break;
                }
            }
        }

        return false;
    }

    private function canLandTrackAtBoundary(
        float $sourceLength,
        float $remaining,
        float $crossfade,
    ): bool {
        $timelineSeconds = $this->timelineSecondsForLength($sourceLength, $crossfade);
        if (abs($remaining - $timelineSeconds) <= self::LANDING_TOLERANCE_SECONDS) {
            return true;
        }

        $targetOutputSeconds = (int)round($remaining + min($crossfade, $sourceLength));
        return null !== $this->stretchCalculator->calculate($sourceLength, $targetOutputSeconds);
    }

    private function timelineSecondsForLength(float $length, float $crossfade): float
    {
        return ($length >= $crossfade)
            ? max(0.0, $length - $crossfade)
            : $length;
    }
}
