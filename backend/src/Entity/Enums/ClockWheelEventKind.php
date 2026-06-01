<?php

declare(strict_types=1);

namespace App\Entity\Enums;

/**
 * Audit row classification for clock wheel AutoDJ decisions.
 */
enum ClockWheelEventKind: string
{
    /** A track was queued for playback from a wheel slot. */
    case TrackQueued = 'track_queued';

    /** Wheel logic intentionally skipped this tick (e.g. insufficient window before next anchor). */
    case Deferred = 'deferred';

    /** Wheel could not queue; AutoDJ or another schedule source may take over. */
    case Fallback = 'fallback';
}
