<?php

declare(strict_types=1);

namespace App\Sync\NowPlaying\Task;

use App\Entity\Station;
use App\Radio\AutoDJ;

final readonly class BuildQueueTask implements NowPlayingTaskInterface
{
    public function __construct(
        private AutoDJ\Queue $queue,
        private AutoDJ\AiDjRealtimeQueueListener $aiDjRealtimeQueueListener,
    ) {
    }

    public function run(Station $station): void
    {
        // AI DJ is a live insertion concern, not a projected queue concern.
        // Give it one realtime opportunity on every Now Playing cycle even when
        // the linear log has already filled the normal music queue many hours
        // ahead. Its own cooldown/queue guards prevent duplicate chatter.
        $this->aiDjRealtimeQueueListener->run($station);

        $this->queue->buildQueue($station);
    }
}
