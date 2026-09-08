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
        // Retirement is a transport-authority operation and may only be activated
        // by the authenticated Liquidsoap AutoDJ, never by an ordinary API caller.
        if ($asAutoDj) {
            $excludedSongId = trim((string)($payload['exclude_song_id'] ?? ''));
            $resetQueueIds = array_values(array_unique(array_filter(
                array_map(
                    'intval',
                    preg_split('/\s*,\s*/', (string)($payload['reset_sq_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                ),
                static fn (int $id): bool => $id > 0,
            )));

            if ('' !== $excludedSongId) {
                $this->retirement->activate($station, $excludedSongId, $resetQueueIds);
            } else {
                // Between Liquidsoap's on_track callback and PHP feedback there is
                // a short window where the local payload may stop carrying the
                // exclusion while the backend quarantine is still authoritative.
                // Reconcile again here so any row inserted concurrently during that
                // window cannot survive as a stale/sent queue entry.
                $activeExcludedSongId = $this->retirement->getExcludedSongId($station);
                if (null !== $activeExcludedSongId) {
                    $this->retirement->activate($station, $activeExcludedSongId);
                }
            }
        }

        // Rebuild immediately after retirement reconciliation. The plugin-owned
        // BuildQueue guard rejects the quarantined song on every selector attempt,
        // including final retries and backend-merge batches.
        if (null !== $this->retirement->getExcludedSongId($station)) {
            $this->queue->buildQueue($station);
        }

        return [
            'uri' => $this->annotations->annotateNextSong($station, $asAutoDj),
        ];
    }
}
