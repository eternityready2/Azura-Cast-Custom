<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Message;

final class LinearLogBuilder
{
    public function __construct(
        private readonly Queue $queue,
        private readonly StationRepository $stationRepo,
    ) {
    }

    /**
     * Message queue handler entry point, registered in config/messagequeue.php.
     * Runs the build in the background worker so it isn't bound by an HTTP
     * request/worker timeout.
     */
    public function __invoke(Message\AbstractMessage $message): void
    {
        if (!$message instanceof Message\BuildLinearLogMessage) {
            return;
        }

        $station = $this->stationRepo->find($message->stationId);
        if (!$station instanceof Station || !$station->supportsAutoDjQueue()) {
            return;
        }

        $this->build($station, $message->hours);
    }

    public function build(Station $station, ?int $hoursOverride = null): void
    {
        $hours = max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours));
        $lookaheadMinutes = $hours * 60;
        $maxTracks = max(1000, $lookaheadMinutes * 2);

        // isPreview: true -- this is a projection for the report, run far ahead of real
        // time. It must never trigger listeners with real-time-only side effects (most
        // notably the AI DJ, which otherwise generates real audio and enqueues it
        // directly into the live Liquidsoap requests queue for a slot that's hours away,
        // and whose cooldown/"already welcomed"/"already queued" guards are all
        // real-clock-based and get exhausted instantly by a multi-hour batch like this).
        $this->queue->buildQueue($station, $lookaheadMinutes, $maxTracks, isPreview: true);
    }
}
