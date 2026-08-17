<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;

/**
 * Thin wrapper around Queue::buildQueue() that projects a station's log out
 * to its configured (or an explicitly requested) linear-log horizon, reusing
 * the exact same selection pipeline as live AutoDJ queue building.
 */
final class LinearLogBuilder
{
    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public function build(Station $station, ?int $hoursOverride = null): void
    {
        $hours = $hoursOverride ?? $station->backend_config->linear_log_hours;
        $hours = max(1, $hours);
        $lookaheadMinutes = $hours * 60;

        // Generous per-run cap so even a station with very short clock-wheel
        // slots (IDs, sweepers) can reach the full horizon before the track
        // count limit does.
        $maxTracks = max(1000, $lookaheadMinutes * 2);

        $this->queue->buildQueue($station, $lookaheadMinutes, $maxTracks);
    }
}
