<?php

declare(strict_types=1);

namespace App\Media;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;

final class GenrePlaylistService
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly GenrePlaylistPlanner $planner,
        private readonly GenrePlaylistFactory $playlistFactory,
        private readonly StationPlaylistRepository $playlistRepo,
        private readonly StationPlaylistMediaRepository $playlistMediaRepo,
        private readonly BatchUtilities $batchUtilities
    ) {
    }

    /**
     * @param iterable<StationMedia> $media
     * @return array<string, mixed>
     */
    public function preview(Station $station, iterable $media): array
    {
        $mediaRecords = $this->collectMedia($media);
        $station = $this->em->refetch($station);

        return $this->planner->plan(
            $mediaRecords,
            $this->describePlaylists($this->playlistRepo->getAllForStation($station))
        );
    }

    /**
     * @param iterable<StationMedia> $media
     * @return array<string, mixed>
     */
    public function execute(Station $station, iterable $media): array
    {
        $mediaRecords = $this->collectMedia($media);
        $station = $this->em->refetch($station);

        $plan = $this->planner->plan(
            $mediaRecords,
            $this->describePlaylists($this->playlistRepo->getAllForStation($station))
        );

        $created = 0;
        $reused = 0;
        $added = 0;
        $alreadyPresent = 0;
        $affectedPlaylistIds = [];

        foreach ($plan['entries'] as &$entry) {
            if ('conflict' === $entry['status']) {
                continue;
            }

            if ('create' === $entry['status']) {
                $playlist = $this->playlistFactory->create($station, $entry['name']);

                $this->em->persist($playlist);
                $this->em->flush();

                $entry['playlist_id'] = $playlist->id;
                ++$created;
            } else {
                ++$reused;
            }

            $playlistId = $entry['playlist_id'];
            if (null === $playlistId) {
                continue;
            }

            foreach ($entry['media_ids'] as $mediaId) {
                $mediaEntity = $this->em->find(StationMedia::class, $mediaId);
                $playlistEntity = $this->em->find(StationPlaylist::class, $playlistId);

                if (!$mediaEntity instanceof StationMedia || !$playlistEntity instanceof StationPlaylist) {
                    continue;
                }

                if ($this->playlistMediaRepo->addMediaToPlaylistIfMissing($mediaEntity, $playlistEntity)) {
                    ++$added;
                    $affectedPlaylistIds[$playlistId] = $playlistId;
                } else {
                    ++$alreadyPresent;
                }
            }
        }
        unset($entry);

        $this->em->flush();
        $this->batchUtilities->writePlaylistChanges($affectedPlaylistIds);

        $conflicted = count(
            array_filter($plan['entries'], static fn(array $entry): bool => 'conflict' === $entry['status'])
        );

        return [
            ...$plan,
            'summary' => [
                'created' => $created,
                'reused' => $reused,
                'added' => $added,
                'already_present' => $alreadyPresent,
                'skipped' => $plan['skipped_count'],
                'conflicted' => $conflicted,
            ],
            'affected_playlist_ids' => array_values($affectedPlaylistIds),
        ];
    }

    /**
     * @param iterable<StationMedia> $media
     * @return array<int, array{id: int, path: string, genre: ?string}>
     */
    private function collectMedia(iterable $media): array
    {
        $records = [];

        foreach ($media as $item) {
            $records[$item->id] = [
                'id' => $item->id,
                'path' => $item->path,
                'genre' => $item->genre,
            ];
        }

        return array_values($records);
    }

    /**
     * @param StationPlaylist[] $playlists
     * @return array<int, array{id: int, name: string, source: PlaylistSources}>
     */
    private function describePlaylists(array $playlists): array
    {
        return array_map(
            static fn(StationPlaylist $playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'source' => $playlist->source,
            ],
            $playlists
        );
    }
}
