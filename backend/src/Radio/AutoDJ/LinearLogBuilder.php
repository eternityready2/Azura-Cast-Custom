<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;

final class LinearLogBuilder
{
    public const int MIN_HOURS = 24;

    public const int MAX_HOURS = 48;

    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public static function normalizeHours(int $hours): int
    {
        return max(self::MIN_HOURS, min(self::MAX_HOURS, $hours));
    }

    public function build(Station $station, ?int $hoursOverride = null): void
    {
        $hours = self::normalizeHours(
            $hoursOverride ?? $station->backend_config->linear_log_hours
        );
        $lookaheadMinutes = $hours * 60;
        $maxTracks = max(1000, $lookaheadMinutes * 2);

        $this->queue->buildQueue($station, $lookaheadMinutes, $maxTracks);
    }
}
