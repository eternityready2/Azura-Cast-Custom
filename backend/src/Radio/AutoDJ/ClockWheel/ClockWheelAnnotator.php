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
     *
     * A precomputed ratio handles tracks that naturally fit and need stretching.
     * When a planner instead produced a small cap for a slightly-too-long track,
     * convert that cap into a squeeze when the required adjustment is within the
     * station maximum; otherwise leave the existing cap/fade fallback untouched.
     */
    public function applyClockWheelStretch(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        $media = $event->getMedia();
        if (!$queue instanceof StationQueue || !$media instanceof StationMedia) {
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

        // A cap/fade target is always the tighter timing requirement. This matters
        // when a row also carries a precomputed TOH stretch ratio but a nearer
        // scheduled boundary subsequently imposed a shorter cap.
        $replacementTargetSeconds = match (true) {
            $queue->clock_wheel_enforce_cap
                && null !== $queue->clock_wheel_max_play_seconds
                && $queue->clock_wheel_max_play_seconds > 0
                => (float)$queue->clock_wheel_max_play_seconds,
            $queue->hour_boundary_enforce_cap
                && null !== $queue->hour_boundary_max_play_seconds
                && $queue->hour_boundary_max_play_seconds > 0
                => (float)$queue->hour_boundary_max_play_seconds,
            $queue->top_of_hour_pre_id_fade
                && null !== $queue->duration
                && $queue->duration > 0
                => (float)$queue->duration,
            default => null,
        };

        $ratio = null !== $replacementTargetSeconds
            ? $media->length / $replacementTargetSeconds
            : $queue->clock_wheel_stretch_ratio;

        if (null === $ratio) {
            return;
        }

        $minimumRatio = 1.0 - $maxDelta;
        $maximumRatio = 1.0 + $maxDelta;
        if ($ratio < $minimumRatio || $ratio > $maximumRatio) {
            return;
        }

        if (abs($ratio - 1.0) < 0.0001) {
            return;
        }

        $ratio = round($ratio, 4);

        if (null !== $replacementTargetSeconds) {
            // The timing miss is small enough to solve without truncating audio.
            // Clear every cap/fade flag so the later annotators do not also cut the
            // same track after Liquidsoap has been told to squeeze it to the target.
            $queue->clock_wheel_stretch_ratio = $ratio;
            $queue->clock_wheel_enforce_cap = false;
            $queue->hour_boundary_enforce_cap = false;
            $queue->top_of_hour_pre_id_fade = false;
            $queue->top_of_hour_pre_id_fade_seconds = null;
            $queue->duration = $replacementTargetSeconds;
        }

        $event->addAnnotations([
            'liq_stretch_ratio' => $ratio,
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
