<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

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
            AnnotateNextSong::class => ['applyClockWheelCap', 11],
        ];
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
            return;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        $maxSeconds = $queue->clock_wheel_max_play_seconds;
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
}
