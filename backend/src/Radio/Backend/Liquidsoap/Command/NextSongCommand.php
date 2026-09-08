<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Entity\Station;
use App\Radio\AutoDJ\Annotations;
use App\Radio\AutoDJ\AutoDjRetirementService;
use App\Radio\AutoDJ\Queue;

final class NextSongCommand extends AbstractCommand
{
    public function __construct(
        private readonly Annotations $annotations,
        private readonly AutoDjRetirementService $retirement,
        private readonly Queue $queue,
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): array {
        $newRetirement = false;

        // Retirement is a transport-authority operation and may only be activated
        // by the authenticated Liquidsoap AutoDJ, never by an ordinary API caller.
        // It is intentionally one-shot: subsequent fetches carrying the same
        // exclusion must not reset fresh sent_to_autodj rows or rebuild them into
        // duplicate requests.
        if ($asAutoDj) {
            $excludedSongId = trim((string)($payload['exclude_song_id'] ?? ''));
            if ('' !== $excludedSongId) {
                $newRetirement = $this->retirement->activate($station, $excludedSongId);
            }
        }

        // Reconcile/rebuild once at the instant a new retirement transaction is
        // established. Normal request.dynamic prefetches after that consume each
        // fresh queue row exactly once through Annotations::postAnnotation().
        if ($newRetirement) {
            $this->queue->buildQueue($station);
        }

        return [
            'uri' => $this->annotations->annotateNextSong($station, $asAutoDj),
        ];
    }
}
