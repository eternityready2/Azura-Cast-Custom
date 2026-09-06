<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies soft broadcast-clock targets during queue planning and then tightens
 * them once more immediately before a normal AutoDJ row is handed to Liquidsoap.
 */
final class BroadcastClockQueueTimingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BroadcastClockPlanner $clockPlanner,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['applyClockTarget', -4],
            AnnotateNextSong::class => ['applyRuntimeClockTarget', 13],
        ];
    }

    public function applyClockTarget(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        $maxDuration = $this->clockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $event->getStation(),
            $event->getExpectedPlayTime(),
        );

        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));

        foreach ($event->getNextSongs() as $queueRow) {
            if (!$queueRow instanceof StationQueue) {
                continue;
            }

            $media = $queueRow->media;
            if ($this->isProtectedContent($queueRow, $media)) {
                continue;
            }

            // This field is consumed by StretchSqueezeQueueTiming. A short
            // timing difference is handled pitch-preservingly; a large overrun
            // keeps the normal cue-out/fade path so Liquidsoap fades rather than
            // abruptly terminating the audio.
            $queueRow->hour_boundary_max_play_seconds = $targetSeconds;

            if ($media instanceof StationMedia) {
                $queueRow->hour_boundary_enforce_cap = $media->getCalculatedLength() > $targetSeconds;
                continue;
            }

            // Non-media queue rows cannot receive AutoCue cue points. Bound
            // their projected duration so later queue slots recover to the
            // station clock rather than propagating the overrun. Runtime source
            // switching remains responsible for actually ending a remote source.
            if (null !== $queueRow->duration && $queueRow->duration > $targetSeconds) {
                $queueRow->duration = (float)$targetSeconds;
            }
        }
    }

    /**
     * Recheck a normal media row against the near-live clock before Liquidsoap
     * receives it. Queue projections can move after they were first built; this
     * only tightens an existing plan and never lengthens a row or changes which
     * track was selected.
     */
    public function applyRuntimeClockTarget(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queueRow = $event->getQueue();
        $media = $event->getMedia();
        if (!$queueRow instanceof StationQueue || !$media instanceof StationMedia) {
            return;
        }

        // Direct/interrupting content is already delivered through a separate
        // playout path and must not be shortened by ordinary schedule recovery.
        if ($queueRow->is_played || $queueRow->playlist?->backendInterruptOtherSongs()) {
            return;
        }

        if ($this->isProtectedContent($queueRow, $media)) {
            return;
        }

        $maxDuration = $this->clockPlanner->maxContentDurationBeforeNextSoftAnchor(
            $event->getStation(),
            $this->resolveLikelyStart($event->getStation()),
        );
        if (null === $maxDuration || $maxDuration <= 0) {
            return;
        }

        $targetSeconds = max(1, (int)floor($maxDuration));
        $existingTarget = $queueRow->hour_boundary_max_play_seconds;
        if (null !== $existingTarget && $existingTarget > 0) {
            $targetSeconds = min($targetSeconds, $existingTarget);
        }

        if ($media->getCalculatedLength() <= $targetSeconds) {
            return;
        }

        $queueRow->hour_boundary_max_play_seconds = $targetSeconds;
        $queueRow->hour_boundary_enforce_cap = true;
        $queueRow->clock_wheel_stretch_ratio = null;
        $queueRow->duration = null === $queueRow->duration
            ? (float)$targetSeconds
            : min($queueRow->duration, (float)$targetSeconds);
    }

    private function resolveLikelyStart(Station $station): DateTimeImmutable
    {
        $now = Time::nowUtc();
        $currentSong = $station->current_song;
        if (null === $currentSong) {
            return $now;
        }

        $duration = max(0.0, (float)$currentSong->duration);
        $end = CarbonImmutable::instance($currentSong->timestamp_start)
            ->addMilliseconds((int)round($duration * 1000));

        $crossfade = max(0.0, $station->backend_config->getCrossfadeDuration());
        if ($duration >= $crossfade && $crossfade > 0) {
            $end = $end->subMilliseconds((int)round($crossfade * 1000));
        }

        return $end->greaterThan($now)
            ? $end->toDateTimeImmutable()
            : $now;
    }

    private function isProtectedContent(
        StationQueue $queueRow,
        ?StationMedia $media,
    ): bool {
        return $queueRow->top_of_hour_legal_id
            || $queueRow->clock_wheel_legal_id_substitute
            || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));
    }
}
