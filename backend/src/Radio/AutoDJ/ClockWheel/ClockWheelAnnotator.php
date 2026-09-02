<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies timing-related playback annotations produced by AutoDJ planning.
 *
 * The stretch ratio field predates station-wide playout controls and retains its
 * clock-wheel-prefixed database name for compatibility, but QueueBuilder also writes
 * it for ordinary rotation playlists (including Smart Block prepared playlists).
 */
final class ClockWheelAnnotator implements EventSubscriberInterface
{
    private const bool DEFAULT_STRETCH_SQUEEZE_ENABLED = true;

    private const float DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT = 5.0;

    public static function getSubscribedEvents(): array
    {
        return [
            AnnotateNextSong::class => [
                ['applyClockWheelStretch', 12],
                ['applyClockWheelCap', 11],
                ['applyLegalIdQuickCut', 9],
            ],
        ];
    }

    /**
     * Apply pitch-preserving stretch/squeeze metadata to any AutoDJ queue row that
     * was backtimed by the planner. This is intentionally source-agnostic: standard
     * rotation playlists, Smart Blocks and Clock Wheels all use the same annotation.
     */
    public function applyClockWheelStretch(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue) {
            return;
        }

        $ratio = $queue->clock_wheel_stretch_ratio;
        if (null === $ratio) {
            return;
        }

        $rawConfig = $event->getStation()->backend_config->toArray(true) ?? [];
        $enabled = (bool)(
            $rawConfig['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_STRETCH_SQUEEZE_ENABLED
        );
        if (!$enabled) {
            return;
        }

        $maxPercent = (float)(
            $rawConfig['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT
        );
        $maxPercent = max(0.5, min(5.0, $maxPercent));
        $maxDelta = $maxPercent / 100;
        $ratio = max(1.0 - $maxDelta, min(1.0 + $maxDelta, $ratio));

        if (abs($ratio - 1.0) < 0.0001) {
            return;
        }

        $event->addAnnotations([
            'liq_stretch_ratio' => round($ratio, 4),
        ]);
    }

    public function applyClockWheelCap(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue) {
            return;
        }

        if (null === $queue->clock_wheel || !$queue->clock_wheel_enforce_cap) {
            if (!$queue->hour_boundary_enforce_cap) {
                return;
            }

            $maxSeconds = $queue->hour_boundary_max_play_seconds;
        } else {
            $maxSeconds = $queue->clock_wheel_max_play_seconds;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        if (null === $maxSeconds || $maxSeconds <= 0) {
            return;
        }

        $cueIn = 0.0;
        $existing = $event->getAnnotations();
        if (isset($existing['autocue_cue_in'])) {
            $cueIn = (float)$existing['autocue_cue_in'];
        }

        $mediaLength = $media->length;
        $cueOut = min($mediaLength, (float)$maxSeconds);
        if ($cueOut <= $cueIn) {
            $cueOut = min($mediaLength, $cueIn + 1.0);
        }

        $event->addAnnotations([
            'autocue_cue_out' => $cueOut,
            'duration' => $cueOut,
        ]);

        $queue->duration = $cueOut;
    }

    /**
     * Mirrors HourBoundaryAnnotator::applyLegalIdQuickCut() -- see that method's
     * docblock for the reasoning. Kept in sync so a legal ID delivered via a
     * Clock Wheel slot gets the same gentle fade-in as one delivered via the
     * station-wide top-of-hour path, instead of the wheel path alone still
     * hard-cutting in.
     */
    public function applyLegalIdQuickCut(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue) {
            return;
        }

        $media = $event->getMedia();
        $isLegalId = ($queue->top_of_hour_legal_id ?? false)
            || ($queue->clock_wheel_legal_id_substitute ?? false)
            || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));

        if (!$isLegalId) {
            return;
        }

        $fadeInSeconds = 0.0;
        $station = $event->getStation();
        if ($media instanceof StationMedia) {
            $fadeInSeconds = min(
                $station->backend_config->getCrossfadeDuration(),
                $media->length / 2
            );
            $fadeInSeconds = max(0.0, $fadeInSeconds);
        }

        $event->addAnnotations([
            'autocue_fade_in' => $fadeInSeconds,
            'autocue_fade_out' => 0.0,
            'autocue_start_next' => null,
        ]);
    }
}
