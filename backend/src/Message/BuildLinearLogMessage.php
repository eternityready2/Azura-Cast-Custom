<?php

declare(strict_types=1);

namespace App\Message;

use App\MessageQueue\QueueNames;

/**
 * Triggers a linear-log build for a single station, off the HTTP request thread.
 *
 * Building up to 48 hours of projected AutoDJ queue can take long enough (especially
 * the first time, or on a station with many playlists/validators) to exceed a normal
 * PHP request/worker timeout. Running it inline in the API request handler meant the
 * process could be killed mid-build, leaving the log at whatever partial horizon had
 * been reached (1 hour, 18 hours, etc.) instead of the requested 24-48. Dispatching it
 * here lets it run to completion in the background worker instead.
 *
 * Extends AbstractUniqueMessage rather than AbstractMessage: a person impatiently
 * hitting "Build and Refresh" more than once (a real, observed behavior -- builds can
 * take several minutes, especially cold) would otherwise queue up multiple builds for
 * the same station that could run concurrently. Queue::buildQueue() reads the
 * station's existing queue tail and continues from there; two overlapping runs against
 * the same station could race on that read and produce duplicate or inconsistent
 * queue rows. The unique-message lock (see HandleUniqueMiddleware) means a second
 * build request for a station that's already building gets cleanly rejected instead
 * of racing the first one.
 */
final class BuildLinearLogMessage extends AbstractUniqueMessage
{
    public int $stationId;

    public int $hours;

    public function getQueue(): QueueNames
    {
        return QueueNames::NormalPriority;
    }

    // Scoped to the station only, deliberately NOT including $hours: a build request
    // for 48 hours and a build request for 24 hours on the SAME station must still be
    // treated as the same in-flight operation and block each other -- both mutate the
    // same station's queue, so it's the station that needs to be serialized, not the
    // specific parameters of the request.
    public function getIdentifier(): string
    {
        return 'BuildLinearLogMessage_station_' . $this->stationId;
    }

    // Default (60s, from AbstractUniqueMessage) is too short: a full 24-48 hour build,
    // especially a cold one needing fresh AutoCue analysis on every track, has been
    // observed taking several minutes in practice. Following the same pattern as
    // BackupMessage (another long-running background task) of using a generous safety
    // cap well beyond any realistic duration, rather than the short default meant for
    // quick operations.
    public function getTtl(): float
    {
        return 3600;
    }
}
