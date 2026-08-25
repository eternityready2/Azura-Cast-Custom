<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;
use App\Utilities\Time;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies station-wide hour-boundary playback annotations independently of Clock Wheels.
 */
final class HourBoundaryAnnotator implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly Scheduler $scheduler,
        private readonly ClockWheelStretchCalculator $stretchCalculator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnnotateNextSong::class => [
                ['applyHourBoundaryCap', 11],
                ['applyTopOfHourPreIdFade', 10],
                ['applyLiveTopOfHourSafetyNet', 10],
                ['applyLegalIdQuickCut', 9],
            ],
        ];
    }

    /**
     * Backstop for tracks that were selected for their queue slot before the
     * top-of-hour lookahead window opened. Re-evaluates the boundary against
     * real time immediately before the request is handed to Liquidsoap.
     */
    public function applyLiveTopOfHourSafetyNet(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue) {
            return;
        }

        if (null !== $queue->clock_wheel) {
            $this->logger->debug('TOH safety net: skipped (clock-wheel slot).');
            return;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        $isLegalId = $queue->top_of_hour_legal_id
            || StationMediaTypes::isStationId($media->type);
        if ($isLegalId) {
            return;
        }

        $station = $event->getStation();
        if (!$station instanceof Station) {
            return;
        }

        $now = Time::nowUtc()->toDateTimeImmutable();
        $tohMaxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
            $station,
            $now,
        );
        $liveMaxDuration = $tohMaxDuration;
        $secondsToScheduled = null;

        try {
            $secondsToScheduled = $this->scheduler->secondsUntilNextScheduledStart(
                $station,
                $now,
            );

            if (null !== $secondsToScheduled && $secondsToScheduled > 0) {
                $scheduledMax = (float)$secondsToScheduled;
                $liveMaxDuration = (null === $liveMaxDuration)
                    ? $scheduledMax
                    : min($liveMaxDuration, $scheduledMax);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'TOH safety net: secondsUntilNextScheduledStart() threw, scheduled boundary ignored for this track.',
                [
                    'exception' => $e->getMessage(),
                    'media' => $media->title,
                ]
            );
        }

        $this->logger->debug(
            'TOH safety net: boundary check.',
            [
                'media' => $media->title,
                'media_length' => $media->length,
                'toh_max_duration' => $tohMaxDuration,
                'seconds_to_scheduled' => $secondsToScheduled,
                'live_max_duration' => $liveMaxDuration,
                'existing_cap' => $queue->hour_boundary_max_play_seconds,
                'build_time_flag_set' => $queue->top_of_hour_pre_id_fade || $queue->hour_boundary_enforce_cap,
            ]
        );

        if (null === $liveMaxDuration) {
            return;
        }

        $mediaLength = $media->length;
        $existingCap = $queue->hour_boundary_max_play_seconds;
        if (
            ($queue->top_of_hour_pre_id_fade || $queue->hour_boundary_enforce_cap)
            && null !== $existingCap
            && (float)$existingCap <= $liveMaxDuration
        ) {
            $this->logger->debug('TOH safety net: build-time cap already tight enough, not re-capping.');
            return;
        }

        if ($mediaLength <= $liveMaxDuration) {
            return;
        }

        $cappedSeconds = (int)floor($liveMaxDuration);
        if ($cappedSeconds < 1) {
            $this->logger->debug('TOH safety net: less than 1 second of room, not capping.');
            return;
        }

        if ($this->applySafeStretch($event, $queue, $media, $cappedSeconds)) {
            $this->logger->info(
                'TOH safety net: using bounded stretch instead of cue-out.',
                [
                    'media' => $media->title,
                    'media_length' => $mediaLength,
                    'target_seconds' => $cappedSeconds,
                    'stretch_ratio' => $queue->clock_wheel_stretch_ratio,
                ]
            );
            return;
        }

        $fadeOutSeconds = min(
            $station->backend_config->getCrossfadeDuration(),
            (float)$cappedSeconds
        );

        $existing = $event->getAnnotations();
        $cueIn = isset($existing['autocue_cue_in']) ? (float)$existing['autocue_cue_in'] : 0.0;
        $cueOut = $cueIn + (float)$cappedSeconds;

        $event->addAnnotations([
            'autocue_cue_out' => $cueOut,
            'autocue_fade_out' => max(0.0, min($fadeOutSeconds, $cueOut - $cueIn)),
            'duration' => $cueOut,
        ]);

        $queue->duration = $cueOut;
        $queue->top_of_hour_pre_id_fade = true;
        $queue->top_of_hour_pre_id_fade_seconds = (int)round(max(0.0, $fadeOutSeconds));
        $queue->hour_boundary_enforce_cap = true;
        $queue->hour_boundary_max_play_seconds = $cappedSeconds;
    }

    /**
     * Fades the outgoing track over its final seconds when QueueBuilder had to
     * use a cue-out for the top-of-hour boundary.
     */
    public function applyTopOfHourPreIdFade(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue || !$queue->top_of_hour_pre_id_fade) {
            return;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        $existing = $event->getAnnotations();
        $cueIn = isset($existing['autocue_cue_in']) ? (float)$existing['autocue_cue_in'] : 0.0;

        $cueOut = $queue->duration ?? min($media->length, $cueIn + 1.0);
        $fadeOutSeconds = (float)($queue->top_of_hour_pre_id_fade_seconds ?? 0);
        $fadeOutSeconds = max(0.0, min($fadeOutSeconds, $cueOut - $cueIn));

        $event->addAnnotations([
            'autocue_cue_out' => $cueOut,
            'autocue_fade_out' => $fadeOutSeconds,
            'duration' => $cueOut,
        ]);
    }

    public function applyHourBoundaryCap(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue || !$queue->hour_boundary_enforce_cap) {
            return;
        }

        $maxSeconds = $queue->hour_boundary_max_play_seconds;
        if (null === $maxSeconds || $maxSeconds <= 0) {
            return;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        if ($media->length > $maxSeconds && $this->applySafeStretch($event, $queue, $media, $maxSeconds)) {
            $this->logger->info(
                'Hour boundary: using bounded stretch instead of cue-out.',
                [
                    'media' => $media->title,
                    'media_length' => $media->length,
                    'target_seconds' => $maxSeconds,
                    'stretch_ratio' => $queue->clock_wheel_stretch_ratio,
                ]
            );
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
     * Uses Liquidsoap's pitch-preserving stretch operator for a near-fit before
     * falling back to a destructive cue-out. The queue duration is changed to
     * the target so later planning reflects the actual stretched airtime.
     */
    private function applySafeStretch(
        AnnotateNextSong $event,
        StationQueue $queue,
        StationMedia $media,
        int $targetSeconds,
    ): bool {
        $ratio = $this->stretchCalculator->calculate($media->length, $targetSeconds);
        if (null === $ratio) {
            return false;
        }

        $event->addAnnotations([
            'liq_stretch_ratio' => $ratio,
            'duration' => (float)$targetSeconds,
        ]);

        $queue->clock_wheel_stretch_ratio = $ratio;
        $queue->duration = (float)$targetSeconds;
        $queue->hour_boundary_enforce_cap = false;
        $queue->hour_boundary_max_play_seconds = null;
        $queue->top_of_hour_pre_id_fade = false;
        $queue->top_of_hour_pre_id_fade_seconds = null;

        return true;
    }

    /**
     * Gives the legal ID a gentle start while keeping its ending deterministic.
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
        $isLegalId = $queue->top_of_hour_legal_id
            || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));

        if (!$isLegalId) {
            return;
        }

        $fadeInSeconds = 0.0;
        $station = $event->getStation();
        if ($station instanceof Station && $media instanceof StationMedia) {
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
