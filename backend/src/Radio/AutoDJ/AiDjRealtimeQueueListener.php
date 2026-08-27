<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;

/**
 * Runs AI DJ decisions against real airtime instead of a deep queue projection.
 *
 * AI DJ clips are live Liquidsoap inserts. They must be decided by the realtime
 * Now Playing worker, not while a 24-hour linear log or long AutoDJ queue is
 * being projected. This keeps talk cadence independent from queue depth.
 */
final class AiDjRealtimeQueueListener
{
    public function __construct(
        private readonly AiDjQueueListener $delegate,
    ) {
    }

    public function run(Station $station): void
    {
        $now = Time::nowUtc()->toDateTimeImmutable();
        $liveEvent = new BuildQueue(
            $station,
            $now,
            $now,
            null,
            false,
        );

        $this->delegate->onBuildQueue($liveEvent);
    }
}
