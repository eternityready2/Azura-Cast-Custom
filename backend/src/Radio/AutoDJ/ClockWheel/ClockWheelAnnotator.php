<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies clock-wheel playback caps via AutoDJ annotations (cue_out) when the planner
 * could not guarantee fit by track selection alone.
 */
final class ClockWheelAnnotator implements EventSubscriberInterface
{
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
