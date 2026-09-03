<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\SmartBlock\SmartBlockSyncer;
use App\Service\StationDiagnostics;
use Throwable;

/**
 * Keeps every Smart Block playlist's membership automatically in sync with its filter
 * criteria (genre, BPM/other custom fields, duration, etc.), on the same recurring
 * schedule as folder-based playlists ({@see CheckFolderPlaylistsTask}).
 *
 * A Smart Block is otherwise a completely normal `source = songs` playlist -- this task
 * only ever touches which StationPlaylistMedia rows exist for it (via
 * {@see SmartBlockSyncer}). Everything else (scheduling, weight, rotation goal,
 * duplicate avoidance, AutoDJ selection order) is unchanged and handled entirely by the
 * existing playlist/AutoDJ code.
 */
final class CheckSmartBlockPlaylistsTask extends AbstractTask
{
    public function __construct(
        private readonly SmartBlockSyncer $syncer,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '*/5 * * * *';
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->syncSmartBlocks($station);
        }
    }

    public function syncSmartBlocks(Station $station): void
    {
        $this->logger->info(
            'Processing Smart Block playlists for station...',
            [
                'station' => $station->name,
            ]
        );

        foreach ($station->playlists as $playlist) {
            if (PlaylistSources::Songs !== $playlist->source || !$playlist->is_smart_block) {
                continue;
            }

            // Static blocks are generated once (on save, or via an explicit "Generate
            // Now") and then left alone -- like Airtime Pro, they behave as a normal,
            // hand-editable tracklist until someone deliberately regenerates them.
            if (SmartBlockType::Dynamic !== $playlist->smart_block_type) {
                continue;
            }

            try {
                $this->em->wrapInTransaction(
                    fn() => $this->syncPlaylist($playlist)
                );
            } catch (Throwable $e) {
                $this->diagnostics->error(
                    $station,
                    'Smart Blocks',
                    'Dynamic Smart Block synchronization failed.',
                    [
                        'playlist' => $playlist->name,
                        'error' => $e->getMessage(),
                    ]
                );
                throw $e;
            }
        }
    }

    private function syncPlaylist(StationPlaylist $playlist): void
    {
        $result = $this->syncer->sync($playlist);

        if ($result['added'] > 0 || $result['removed'] > 0) {
            $this->logger->debug(
                sprintf(
                    '%d media added, %d media removed from Smart Block.',
                    $result['added'],
                    $result['removed']
                ),
                [
                    'playlist' => $playlist->name,
                ]
            );
        } else {
            $this->logger->debug(
                'No changes detected in Smart Block.',
                [
                    'playlist' => $playlist->name,
                ]
            );
        }
    }
}
