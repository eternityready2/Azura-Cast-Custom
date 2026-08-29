<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;

final class LinearLogBuilder
{
    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public function build(Station $station, ?int $hoursOverride = null): void
    {
        $hours = max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours));
        $lookaheadMinutes = $hours * 60;
        $maxTracks = max(1000, $lookaheadMinutes * 2);

        $this->queue->buildQueue($station, $lookaheadMinutes, $maxTracks);
    }
}
