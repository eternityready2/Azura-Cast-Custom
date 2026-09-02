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

        // A precomputed ratio can represent another protected anchor, most often
        // the station-wide top-of-hour target. If a separate scheduled-playlist
        // target was retained on the row, use whichever anchor occurs first.
        // Leave ratio-only rows on the normal ratio path below so disabling or
        // lowering the station limit restores natural playback instead of creating
        // a cap that the planner never requested.
        $precomputedRatio = $queueRow->clock_wheel_stretch_ratio;
        if (
            !$isLegalId
            && null !== $targetSeconds
            && null !== $precomputedRatio
            && $precomputedRatio > 0
        ) {
            $precomputedTargetSeconds = $calculatedLength / $precomputedRatio;
            if ($precomputedTargetSeconds > 0) {
                $targetSeconds = min($targetSeconds, $precomputedTargetSeconds);
            }
        }

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
            $ratio = round($ratio, 4);
            if (abs($ratio - 1.0) >= 0.0001) {
                // Liquidsoap receives four decimal places. Freeze both the stored
                // ratio and projected duration to that same precision so queue
                // timing cannot differ from actual playout by rounding drift.
                $queueRow->clock_wheel_stretch_ratio = $ratio;
                $queueRow->duration = $calculatedLength / $ratio;
                return;
            }
        }

        // Disabled, outside the operator's safety limit, or effectively 1.0 after
        // serialization precision. Freeze the row to natural playback.
        $queueRow->clock_wheel_stretch_ratio = null;
        $queueRow->duration = $calculatedLength;
    }

    private function getTimingTarget(StationQueue $queueRow): ?float
    {
        $targets = [];

        if (
            $queueRow->clock_wheel_enforce_cap
            && null !== $queueRow->clock_wheel_max_play_seconds
            && $queueRow->clock_wheel_max_play_seconds > 0
        ) {
            $targets[] = (float)$queueRow->clock_wheel_max_play_seconds;
        }

        // QueueBuilder stores the next scheduled-playlist boundary even when the
        // selected track is shorter than it. `hour_boundary_enforce_cap` remains
        // false in that case, but the retained value is still an exact backtiming
        // target for a safe stretch operation.
        if (
            null !== $queueRow->hour_boundary_max_play_seconds
            && $queueRow->hour_boundary_max_play_seconds > 0
        ) {
            $targets[] = (float)$queueRow->hour_boundary_max_play_seconds;
        }

        if (
            $queueRow->top_of_hour_pre_id_fade
            && null !== $queueRow->duration
            && $queueRow->duration > 0
        ) {
            $targets[] = (float)$queueRow->duration;
        }

        // More than one independent protection can apply to the same row. The
        // earliest boundary must always win; using a fixed precedence here can
        // otherwise let a later scheduled target hide a tighter top-of-hour target.
        return [] === $targets ? null : min($targets);
    }
}
