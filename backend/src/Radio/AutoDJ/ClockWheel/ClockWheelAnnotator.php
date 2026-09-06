<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies timing-related playback annotations produced by AutoDJ planning.
 *
 * The stretch ratio field predates station-wide playout controls and retains its
 * clock-wheel-prefixed database name for compatibility, but it is now used by
 * ordinary rotation playlists and Smart Blocks as well as Clock Wheels.
 */
final class ClockWheelAnnotator implements EventSubscriberInterface
{
    private const float RATIO_ALIGNMENT_TOLERANCE = 0.000075;

    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly ClockWheelEventLogger $eventLogger,
    ) {
    }

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
     * Apply the stretch/squeeze decision frozen into the queue row when AutoDJ
     * planned it. Runtime setting changes deliberately affect newly planned rows
     * only, preventing an already-queued row from changing duration after later
     * timestamps and protected boundaries were calculated from its old duration.
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

        $isLegalId = $queue->top_of_hour_legal_id
            || $queue->clock_wheel_legal_id_substitute
            || StationMediaTypes::isStationId($media->type);
        if ($isLegalId) {
            return;
        }

        // A row that still has a cap requirement was intentionally left on the
        // fallback path during queue planning. Never stack stretch metadata on
        // top of that cap at annotation time.
        if (
            $queue->clock_wheel_enforce_cap
            || $queue->hour_boundary_enforce_cap
        ) {
            return;
        }

        $ratio = $queue->clock_wheel_stretch_ratio;
        if (null === $ratio || $ratio <= 0 || abs($ratio - 1.0) < 0.0001) {
            return;
        }

        $naturalDuration = $media->getCalculatedLength();
        $projectedDuration = $queue->duration;
        if (null === $projectedDuration || $projectedDuration <= 0 || $naturalDuration <= 0) {
            $queue->clock_wheel_stretch_ratio = null;
            return;
        }

        // Older queued rows can contain a planner ratio that predates active
        // Liquidsoap serialization while their stored duration is still natural.
        // Only activate a ratio when the persisted projected duration proves that
        // this row was normalized for the same ratio during queue planning.
        $durationRatio = $naturalDuration / $projectedDuration;
        if (abs($durationRatio - $ratio) > self::RATIO_ALIGNMENT_TOLERANCE) {
            $queue->clock_wheel_stretch_ratio = null;
            $queue->duration = $naturalDuration;
            $event->addAnnotations([
                'duration' => $naturalDuration,
            ]);
            return;
        }

        $event->addAnnotations([
            'liq_stretch_ratio' => round($ratio, 4),
            'duration' => $projectedDuration,
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
     * Give Station ID / legal-ID rows a clean, predictable ending and a gentle
     * entrance. This applies equally to Clock Wheel IDs and the rebuilt
     * station-wide Top-of-Hour ID path.
     *
     * The station-wide compliance event is created here, after Queue::buildQueue
     * has accepted and persisted the row. This avoids orphan audit records from
     * rejected BuildQueue candidates and makes expected_play_at equal the actual
     * projected ID start rather than the following :00 boundary.
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
            || $queue->clock_wheel_legal_id_substitute
            || ($media instanceof StationMedia && StationMediaTypes::isStationId($media->type));

        if (!$isLegalId) {
            return;
        }

        if (
            $queue->top_of_hour_legal_id
            && $media instanceof StationMedia
            && isset($queue->id)
            && null === $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued(
                $event->getStation(),
                $queue->id,
            )
        ) {
            $expectedPlayAt = $queue->timestamp_played
                ?? $queue->top_of_hour_boundary_at;

            if (null !== $expectedPlayAt) {
                $this->eventLogger->recordTopOfHourLegalIdQueued(
                    $event->getStation(),
                    $media,
                    $expectedPlayAt,
                    $queue,
                );
            }
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
