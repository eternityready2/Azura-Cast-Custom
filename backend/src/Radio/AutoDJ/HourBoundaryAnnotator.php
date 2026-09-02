<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\Scheduler;
use App\Utilities\Time;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Applies station-wide hour-boundary playback annotations independently of Clock Wheels.
 */
final class HourBoundaryAnnotator implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly Scheduler $scheduler,
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
     * Backstop for tracks that were selected for their queue slot BEFORE the
     * top-of-hour lookahead window opened (e.g. several tracks queued at once
     * right after a station restart, or a queue depth deep enough that a slot a
     * few minutes out doesn't get re-evaluated before it plays). Those tracks
     * never got `top_of_hour_pre_id_fade` / `hour_boundary_enforce_cap` set at
     * build time, because HourBoundaryPlanner::maxMusicDurationBeforeTopOfHour()
     * returned null at selection time -- outside the window, "let it play" was
     * the right call. But if nothing re-checks it, a long track selected just
     * outside that window can still run past the hour boundary uncapped, which
     * is what pushes the mandatory legal ID's actual airtime anywhere from
     * several minutes early to several minutes late instead of landing on a
     * consistent target second every hour.
     *
     * This fires at AnnotateNextSong time -- right before the track is handed to
     * Liquidsoap, much closer to real playback than the original BuildQueue
     * selection -- and re-runs the same cap/fade math using the ACTUAL current
     * time instead of the (potentially stale) time the track was originally
     * queued at. It's a pure safety net: if the flag-based path above already
     * capped this track, `top_of_hour_pre_id_fade` is already true and this
     * method no-ops immediately.
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

        // Clock-wheel-driven slots have their own dedicated fitting logic.
        if (null !== $queue->clock_wheel) {
            $this->logger->debug('TOH safety net: skipped (clock-wheel slot).');
            return;
        }

        // Never touch the legal ID track itself.
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

        // Compute the tightest live boundary from two sources:
        // 1. The TOH window (finish buffer + ID max seconds before :00)
        // 2. The next scheduled playlist/clock-wheel/smart-block start
        //
        // We deliberately re-run this even when hour_boundary_enforce_cap or
        // top_of_hour_pre_id_fade is already set. Those flags were computed at
        // queue-build time -- potentially an hour ahead of actual play, when
        // the projected play time was far from any boundary. By the time the
        // track actually annotates (one track ahead of airtime), the real
        // boundary may be imminent. Trusting the stale build-time flag causes
        // songs to run past scheduled items that were invisible at build time.
        $tohMaxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
            $station,
            $now,
        );
        $liveMaxDuration = $tohMaxDuration;

        $secondsToScheduled = null;

        if ($station->backend_config->top_of_hour_hard_trigger_enabled) {
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
            } catch (Throwable $e) {
                $this->logger->error(
                    'TOH safety net: scheduled-boundary lookup failed; ignoring it for this track.',
                    [
                        'exception' => $e->getMessage(),
                        'media' => $media->title,
                    ]
                );
            }
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
            // No active boundary right now -- nothing to enforce.
            return;
        }

        $mediaLength = $media->length;

        // If the build-time cap was already tighter than or equal to the live
        // boundary, it's genuinely fine -- don't re-cap unnecessarily.
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
            // Track already fits within the live boundary on its own.
            return;
        }

        $cappedSeconds = (int)floor($liveMaxDuration);
        if ($cappedSeconds < 1) {
            $this->logger->debug('TOH safety net: less than 1 second of room, not capping.');
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
     * Fades the outgoing track down over its last few seconds instead of a hard
     * cut, when it's the track selected immediately before a due top-of-hour ID.
     * Runs before applyLegalIdQuickCut (which governs the ID's own fade), and
     * only ever touches rows QueueBuilder explicitly flagged -- so it's a no-op
     * for every station that doesn't have top-of-hour ID protection enabled.
     *
     * NOTE: this used to also set `autocue_start_next` to make the ID's
     * fade-in genuinely overlap the tail of this fade-out (a real crossfade,
     * not just two independent fades). That's been pulled back out -- it
     * requires Liquidsoap to hold two sources open and mixing at once, and on
     * a night where a track also got cut into by the interrupt-fallback path
     * (a totally separate mechanism reacting to a missed boundary), the two
     * overlapping simultaneously on the same track produced looping/rewinding
     * audio corruption on air. Sequential-but-both-fading (this track fades
     * down to silence, then the ID fades up from silence) is less seamless but
     * doesn't ask Liquidsoap to mix two sources against each other. Revisit
     * only with a live-tested environment, not blind on someone's production
     * station.
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

        // Fade window can't be longer than the (already-capped) track itself.
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

        $station = $event->getStation();
        if (!$station instanceof Station) {
            return;
        }

        $isTopOfHourCap = $queue->top_of_hour_pre_id_fade
            || $queue->top_of_hour_legal_id
            || StationMediaTypes::isStationId($media->type);

        if (!$isTopOfHourCap && !$station->backend_config->top_of_hour_hard_trigger_enabled) {
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
     * Governs how the mandatory legal ID itself starts and ends. The ID's START
     * now fades in gently (matching the station's normal crossfade length)
     * instead of cutting in at full volume, so it rises up underneath the tail
     * of the outgoing track -- which is doing its own fade-out with a matching
     * `autocue_start_next` overlap point (see applyTopOfHourPreIdFade() /
     * applyLiveTopOfHourSafetyNet() above) -- rather than only becoming audible
     * after the previous track has gone completely silent. The ID's own ENDING
     * stays a clean cut (fade_out=0, start_next=null): once the ID has aired,
     * there's no reason to soften how it hands off to whatever plays next, and
     * a clean end also keeps its total airtime predictable for compliance
     * purposes. Applies to every legal-ID-typed row regardless of how it got
     * queued (normal advance path, clock-wheel substitute, or the rare
     * interrupt-fallback), since a gentle start never hurts.
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
            // Never let the fade-in eat more than half the ID itself -- a fade
            // that long would make even a full-volume-crossfade sound like the
            // ID is barely there. For your ~37s ID with a typical few-second
            // station crossfade length this never comes close to mattering.
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
