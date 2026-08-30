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
 */
final class BuildLinearLogMessage extends AbstractMessage
{
    public int $stationId;

    public int $hours;

    public function getQueue(): QueueNames
    {
        return QueueNames::NormalPriority;
    }
}
