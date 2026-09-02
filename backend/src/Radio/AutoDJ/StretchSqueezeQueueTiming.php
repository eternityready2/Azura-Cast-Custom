<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps projected queue timing aligned with pitch-preserving stretch/squeeze.
 *
 * QueueBuilder and Clock Wheel planning attach the legacy
 * `clock_wheel_stretch_ratio` field to rows that may be backtimed. This listener
 * freezes the final timing decision while the row is being planned, so later
 * runtime setting changes never make an already-queued row play for a different
 * duration than the one used to plan the rows after it.
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
     * Freeze one planned queue row to the station settings that were active when
     * the row was selected. Existing queued rows are intentionally not re-timed
     * when an operator changes the runtime setting; the new setting applies to
     * subsequently planned rows instead.
     */
    public function normalizeQueueRow(Station $station, StationQueue $queueRow): void
    {
        $media = $queueRow->media;
        if (!$media instanceof StationMedia) {
            return;
        }

        $calculatedLength = $media->getCalculatedLength();
        if ($calculatedLength <= 0) {
            return;
        }

        $isLegalId = $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute
            || StationMediaTypes::isStationId($media->type);

        $targetSeconds = $this->getTimingTarget($queueRow);

        // Legal-ID max durations are ceilings, not backtiming targets. Never slow
        // down or speed up an ID merely to fill its configured maximum duration.
        if ($isLegalId) {
            $queueRow->clock_wheel_stretch_ratio = null;
            $queueRow->duration = null !== $targetSeconds && $calculatedLength > $targetSeconds
                ? $targetSeconds
                : $calculatedLength;
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

        if (null !== $targetSeconds) {
            $ratio = $calculatedLength / $targetSeconds;

            if (
                $enabled
                && $ratio > 0
                && $ratio >= $minimumRatio
                && $ratio <= $maximumRatio
                && abs($ratio - 1.0) >= 0.0001
            ) {
                $queueRow->clock_wheel_stretch_ratio = round($ratio, 4);
                $queueRow->duration = $targetSeconds;

                // The ratio now fulfills the timing requirement by itself. Clear
                // cap/fade flags so the annotation stage cannot apply both a
                // pitch-preserving adjustment and a cue-out truncation.
                $queueRow->clock_wheel_enforce_cap = false;
                $queueRow->hour_boundary_enforce_cap = false;
                $queueRow->top_of_hour_pre_id_fade = false;
                $queueRow->top_of_hour_pre_id_fade_seconds = null;
                return;
            }

            // No safe stretch/squeeze is available. Freeze the fallback duration
            // to what the cap path will actually air: longer tracks are truncated
            // to the target; shorter tracks remain at their natural duration.
            $queueRow->clock_wheel_stretch_ratio = null;
            $queueRow->duration = min($calculatedLength, $targetSeconds);
            return;
        }

        $ratio = $queueRow->clock_wheel_stretch_ratio;
        if (
            $enabled
            && null !== $ratio
            && $ratio > 0
            && $ratio >= $minimumRatio
            && $ratio <= $maximumRatio
        ) {
            $queueRow->duration = $calculatedLength / $ratio;
            return;
        }

        // Disabled or outside the operator's configured safety limit. Clearing the
        // stored ratio freezes this row to natural playback even if the station
        // setting changes before the row is handed to Liquidsoap.
        $queueRow->clock_wheel_stretch_ratio = null;
        $queueRow->duration = $calculatedLength;
    }

    private function getTimingTarget(StationQueue $queueRow): ?float
    {
        if (
            $queueRow->clock_wheel_enforce_cap
            && null !== $queueRow->clock_wheel_max_play_seconds
            && $queueRow->clock_wheel_max_play_seconds > 0
        ) {
            return (float)$queueRow->clock_wheel_max_play_seconds;
        }

        if (
            $queueRow->hour_boundary_enforce_cap
            && null !== $queueRow->hour_boundary_max_play_seconds
            && $queueRow->hour_boundary_max_play_seconds > 0
        ) {
            return (float)$queueRow->hour_boundary_max_play_seconds;
        }

        if (
            $queueRow->top_of_hour_pre_id_fade
            && null !== $queueRow->duration
            && $queueRow->duration > 0
        ) {
            return (float)$queueRow->duration;
        }

        return null;
    }
}
