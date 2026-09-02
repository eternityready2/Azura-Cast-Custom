<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps projected queue timing aligned with pitch-preserving stretch/squeeze.
 *
 * QueueBuilder and Clock Wheel planning attach the legacy
 * `clock_wheel_stretch_ratio` field to any row that can safely be backtimed. The
 * playback annotator consumes that ratio later, but Queue::buildQueue() advances
 * its projected cursor from StationQueue::duration immediately after BuildQueue
 * dispatch. Adjust the duration here after selectors and the DMCA validator have
 * finished, so ordinary rotation, Smart Blocks, Clock Wheels and the Linear Log
 * all plan subsequent rows from the same duration Liquidsoap will actually play.
 */
final class StretchSqueezeQueueTiming implements EventSubscriberInterface
{
    private const bool DEFAULT_ENABLED = true;

    private const float DEFAULT_MAX_PERCENT = 5.0;

    public static function getSubscribedEvents(): array
    {
        return [
            // DMCA validation runs at -5 and may clear the selected row.
            BuildQueue::class => ['applyProjectedDuration', -6],
        ];
    }

    public function applyProjectedDuration(BuildQueue $event): void
    {
        $rawConfig = $event->getStation()->backend_config->toArray(true) ?? [];
        if (!(bool)($rawConfig['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_ENABLED)) {
            return;
        }

        $maxPercent = (float)(
            $rawConfig['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_MAX_PERCENT
        );
        $maxPercent = max(0.5, min(5.0, $maxPercent));
        $minimumRatio = 1.0 - ($maxPercent / 100);
        $maximumRatio = 1.0 + ($maxPercent / 100);

        foreach ($event->getNextSongs() as $queueRow) {
            if (!$queueRow instanceof StationQueue) {
                continue;
            }

            // A cap/fade already carries the exact target duration and is later
            // converted into a squeeze only when it falls inside the same safety
            // range. Do not replace that target with a looser precomputed ratio.
            if (
                $queueRow->clock_wheel_enforce_cap
                || $queueRow->hour_boundary_enforce_cap
                || $queueRow->top_of_hour_pre_id_fade
            ) {
                continue;
            }

            $ratio = $queueRow->clock_wheel_stretch_ratio;
            $media = $queueRow->media;
            if (
                null === $ratio
                || $ratio <= 0
                || $ratio < $minimumRatio
                || $ratio > $maximumRatio
                || !$media instanceof StationMedia
            ) {
                continue;
            }

            $calculatedLength = $media->getCalculatedLength();
            if ($calculatedLength <= 0) {
                continue;
            }

            $queueRow->duration = $calculatedLength / $ratio;
        }
    }
}
