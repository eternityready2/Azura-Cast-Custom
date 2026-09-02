<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
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
        $station = $event->getStation();

        foreach ($event->getNextSongs() as $queueRow) {
            if ($queueRow instanceof StationQueue) {
                $this->normalizeQueueRow($station, $queueRow);
            }
        }
    }

    /**
     * Reconcile one unsent/planned queue row with the station's current
     * Stretch/Squeeze setting. This is also used when an operator changes the
     * runtime setting so rows that have not yet been handed to Liquidsoap remain
     * consistent with the annotations they will receive later.
     */
    public function normalizeQueueRow(Station $station, StationQueue $queueRow): void
    {
        // A cap/fade already carries an explicit target duration. At annotation
        // time that target either remains a cap or is converted into a squeeze,
        // so its projected duration is already authoritative.
        if (
            $queueRow->clock_wheel_enforce_cap
            || $queueRow->hour_boundary_enforce_cap
            || $queueRow->top_of_hour_pre_id_fade
        ) {
            return;
        }

        $ratio = $queueRow->clock_wheel_stretch_ratio;
        $media = $queueRow->media;
        if (null === $ratio || !$media instanceof StationMedia) {
            return;
        }

        $calculatedLength = $media->getCalculatedLength();
        if ($calculatedLength <= 0) {
            return;
        }

        $rawConfig = $station->backend_config->toArray(true) ?? [];
        $enabled = (bool)($rawConfig['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_ENABLED);
        $maxPercent = (float)(
            $rawConfig['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_MAX_PERCENT
        );
        $maxPercent = max(0.5, min(5.0, $maxPercent));
        $minimumRatio = 1.0 - ($maxPercent / 100);
        $maximumRatio = 1.0 + ($maxPercent / 100);

        if (
            $enabled
            && $ratio > 0
            && $ratio >= $minimumRatio
            && $ratio <= $maximumRatio
        ) {
            $queueRow->duration = $calculatedLength / $ratio;
            return;
        }

        // If the feature was disabled or the configured safety limit was lowered
        // below this row's precomputed ratio, playback will no longer apply that
        // ratio. Restore the natural media duration so downstream queue timing
        // matches what Liquidsoap will actually air.
        $queueRow->duration = $calculatedLength;
    }
}
