<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\SmartBlockType;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistFolder;
use App\Entity\StationPlaylistMedia;
use App\Radio\SmartBlock\SmartBlockSyncer;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @extends Repository<StationPlaylistMedia>
 */
final class StationPlaylistMediaRepository extends Repository
{
    protected string $entityClass = StationPlaylistMedia::class;

    /**
     * How often (in seconds) a Dynamic Smart Block is allowed to be inline-resynced
     * from {@see self::getQueue()} -- see {@see self::maybeResyncDynamicSmartBlock()}.
     */
    private const int SMART_BLOCK_INLINE_RESYNC_THROTTLE_SECONDS = 60;

    /** @var array<int, int> Playlist ID => unix timestamp of last inline resync. */
    private array $smartBlockLastInlineSyncAt = [];

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly SmartBlockSyncer $smartBlockSyncer,
    ) {
    }

    /**
     * @param StationMedia $media
     * @param array<int, int> $playlists Playlists with weight as value (id => weight)
     * @return array<int, int> Affected playlist IDs (id => id)
     */
    public function setPlaylistsForMedia(
        StationMedia $media,
        Station $station,
        array $playlists
    ): array {
        $toDelete = [];

        foreach ($this->getPlaylistsForMedia($media, $station) as $playlistId) {
            if (isset($playlists[$playlistId])) {
                unset($playlists[$playlistId]);
            } else {
                $toDelete[$playlistId] = $playlistId;
            }
        }

        if (0 !== count($toDelete)) {
            $this->em->createQuery(
                <<<'DQL'
                DELETE FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = :media
                AND IDENTITY(spm.playlist) IN (:playlistIds)
                AND spm.folder IS NULL
                DQL
            )->setParameter('media', $media)
                ->setParameter('playlistIds', $toDelete)
                ->execute();
        }

        $added = [];

        foreach ($playlists as $playlistId => $weight) {
            $playlist = $this->em->find(StationPlaylist::class, $playlistId);
            if (!($playlist instanceof StationPlaylist)) {
                continue;
            }

            if (0 === $weight) {
                $weight = $this->getHighestSongWeight($playlist) + 1;
            }
            if (PlaylistOrders::Sequential !== $playlist->order) {
                $weight = random_int(1, $weight);
            }

            $record = new StationPlaylistMedia($playlist, $media);
            $record->weight = $weight;
            $this->em->persist($record);

            $added[$playlistId] = $playlistId;
        }

        $this->em->flush();

        return $toDelete + $added;
    }

    /**
     * @return array<array-key, int>
     */
    public function getPlaylistsForMedia(
        StationMedia $media,
        ?Station $station = null
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('sp.id')
            ->from(StationPlaylistMedia::class, 'spm')
            ->leftJoin('spm.playlist', 'sp')
            ->where('spm.media = :media')
            ->setParameter('media', $media);

        if ($station !== null) {
            $qb = $qb->andWhere('sp.station = :station')
                ->setParameter('station', $station);
        }

        $playlistIds = $qb->getQuery()->getSingleColumnResult();
        return array_combine($playlistIds, $playlistIds);
    }

    /**
     * Add the specified media to the specified playlist.
     * Must flush the EntityManager after using.
     *
     * @return int The weight assigned to the newly added record.
     */
    public function addMediaToPlaylist(
        StationMedia $media,
        StationPlaylist $playlist,
        int $weight = 0,
        ?StationPlaylistFolder $folder = null,
    ): int {
        if (PlaylistSources::Songs !== $playlist->source) {
            throw new RuntimeException('This playlist is not meant to contain songs!');
        }

        // Only update existing record for random-order playlists.
        $isNonSequential = PlaylistOrders::Sequential !== $playlist->order;

        $record = ($isNonSequential)
            ? $this->repository->findOneBy(
                [
                    'media' => $media,
                    'playlist' => $playlist,
                ]
            ) : null;

        if ($record instanceof StationPlaylistMedia) {
            $changesMade = false;

            if (0 !== $weight) {
                $record->weight = $weight;
                $changesMade = true;
            }

            if ($record->folder !== $folder) {
                $record->folder = $folder;
                $changesMade = true;
            }

            if ($changesMade) {
                $this->em->persist($record);
            }
        } else {
            if (0 === $weight) {
                $weight = $this->getHighestSongWeight($playlist) + 1;
            }
            if ($isNonSequential) {
                $weight = random_int(1, $weight);
            }

            $record = new StationPlaylistMedia($playlist, $media, $folder);
            $record->weight = $weight;
            $this->em->persist($record);
        }

        return $weight;
    }

    /**
     * Add a direct media membership only when one does not already exist.
     *
     * Unlike addMediaToPlaylist(), this is idempotent for sequential playlists.
     * Folder-derived memberships do not count as direct memberships.
     */
    public function addMediaToPlaylistIfMissing(
        StationMedia $media,
        StationPlaylist $playlist
    ): bool {
        if (PlaylistSources::Songs !== $playlist->source) {
            throw new RuntimeException('This playlist is not meant to contain songs!');
        }

        $existingRecord = $this->repository->findOneBy([
            'media' => $media,
            'playlist' => $playlist,
            'folder' => null,
        ]);

        if ($existingRecord instanceof StationPlaylistMedia) {
            return false;
        }

        $weight = $this->getHighestSongWeight($playlist) + 1;
        if (PlaylistOrders::Sequential !== $playlist->order) {
            $weight = random_int(1, $weight);
        }

        $record = new StationPlaylistMedia($playlist, $media);
        $record->weight = $weight;
        $this->em->persist($record);

        return true;
    }

    public function getHighestSongWeight(StationPlaylist $playlist): int
    {
        try {
            $highestWeight = $this->em->createQuery(
                <<<'DQL'
                    SELECT MAX(e.weight)
                    FROM App\Entity\StationPlaylistMedia e
                    WHERE e.playlist = :playlist
                DQL
            )->setParameter('playlist', $playlist)
                ->getSingleScalarResult();
        } catch (NoResultException) {
            $highestWeight = 1;
        }

        return (int)$highestWeight;
    }

    /**
     * Remove all playlist associations from the specified media object.
     *
     * @param StationMedia $media
     * @param Station|null $station
     *
     * @return array<int, int> Affected Playlist records (id => id)
     */
    public function clearPlaylistsFromMedia(
        StationMedia $media,
        ?Station $station = null
    ): array {
        $affectedPlaylists = [];

        $playlists = $media->playlists;
        if (null !== $station) {
            $playlists = $playlists->filter(
                function (StationPlaylistMedia $spm) use ($station) {
                    return $spm->playlist->station->id === $station->id;
                }
            );
        }

        foreach ($playlists as $spmRow) {
            $playlist = $spmRow->playlist;
            $affectedPlaylists[$playlist->id] = $playlist->id;

            $this->queueRepo->clearForMediaAndPlaylist($media, $playlist);

            $this->em->remove($spmRow);
        }

        return $affectedPlaylists;
    }

    public function emptyPlaylist(
        StationPlaylist $playlist
    ): void {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.playlist = :playlist
            DQL
        )->setParameter('playlist', $playlist)
            ->execute();

        $this->queueRepo->clearForPlaylist($playlist);
    }

    /**
     * Set the order of the media, specified as
     * [
     *    media_id => new_weight,
     *    ...
     * ]
     *
     * @param StationPlaylist $playlist
     * @param array $mapping
     */
    public function setMediaOrder(StationPlaylist $playlist, array $mapping): void
    {
        $updateQuery = $this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationPlaylistMedia e
                SET e.weight = :weight
                WHERE e.playlist = :playlist
                AND e.id = :id
            DQL
        )->setParameter('playlist', $playlist);

        $this->em->wrapInTransaction(
            function () use ($updateQuery, $mapping): void {
                foreach ($mapping as $id => $weight) {
                    $updateQuery->setParameter('id', $id)
                        ->setParameter('weight', $weight)
                        ->execute();
                }
            }
        );
    }

    public function resetQueue(
        StationPlaylist $playlist,
        ?CarbonImmutable $now = null
    ): void {
        if (PlaylistSources::Songs !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must contain songs.');
        }

        if (PlaylistOrders::Sequential === $playlist->order) {
            $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationPlaylistMedia spm
                    SET spm.is_queued = 1
                    WHERE spm.playlist = :playlist
                DQL
            )->setParameter('playlist', $playlist)
                ->execute();
        } elseif (PlaylistOrders::Shuffle === $playlist->order || PlaylistOrders::SmartShuffle === $playlist->order) {
            $this->em->wrapInTransaction(
                function () use ($playlist): void {
                    $allSpmRecordsQuery = $this->em->createQuery(
                        <<<'DQL'
                            SELECT spm.id
                            FROM App\Entity\StationPlaylistMedia spm
                            WHERE spm.playlist = :playlist
                            ORDER BY RAND()
                        DQL
                    )->setParameter('playlist', $playlist);

                    $updateSpmWeightQuery = $this->em->createQuery(
                        <<<'DQL'
                            UPDATE App\Entity\StationPlaylistMedia spm
                            SET spm.weight=:weight, spm.is_queued=1
                            WHERE spm.id = :id
                        DQL
                    );

                    $allSpmRecords = $allSpmRecordsQuery->toIterable([], $allSpmRecordsQuery::HYDRATE_SCALAR);
                    $weight = 1;

                    foreach ($allSpmRecords as $spmId) {
                        $updateSpmWeightQuery->setParameter('id', $spmId)
                            ->setParameter('weight', $weight)
                            ->execute();

                        $weight++;
                    }
                }
            );
        }

        $now ??= Time::nowUtc();

        $playlist->queue_reset_at = $now;
        $this->em->persist($playlist);
        $this->em->flush();
    }

    public function resetAllQueues(Station $station): void
    {
        $now = Time::nowUtc();

        foreach ($station->playlists as $playlist) {
            if (PlaylistSources::Songs !== $playlist->source) {
                continue;
            }

            $this->resetQueue($playlist, $now);
        }
    }

    /**
     * @return StationPlaylistQueue[]
     */
    public function getQueue(StationPlaylist $playlist): array
    {
        if (PlaylistSources::Songs !== $playlist->source) {
            throw new InvalidArgumentException('Playlist must contain songs.');
        }

        $this->maybeResyncDynamicSmartBlock($playlist);

        $queuedMediaQuery = $this->em->createQueryBuilder()
            ->select([
                'spm.id AS spm_id',
                'spm.last_played AS last_played',
                'sm.id',
                'sm.song_id',
                'sm.artist',
                'sm.title',
            ])
            ->from(StationMedia::class, 'sm')
            ->join('sm.playlists', 'spm')
            ->where('spm.playlist = :playlist')
            ->setParameter('playlist', $playlist);

        if (PlaylistOrders::Random === $playlist->order) {
            $queuedMediaQuery = $queuedMediaQuery->orderBy('RAND()');
        } else {
            $queuedMediaQuery = $queuedMediaQuery->andWhere('spm.is_queued = 1')
                ->orderBy('spm.weight', 'ASC');
        }

        $queuedMedia = $queuedMediaQuery->getQuery()->getArrayResult();

        return array_map(
            static function ($val): StationPlaylistQueue {
                $record = new StationPlaylistQueue();
                $record->spm_id = $val['spm_id'];
                $record->media_id = $val['id'];
                $record->song_id = $val['song_id'];
                $record->artist = $val['artist'] ?? '';
                $record->title = $val['title'] ?? '';
                $record->last_played = $val['last_played'] ?? null;

                return $record;
            },
            $queuedMedia
        );
    }

    /**
     * Airtime Pro resolves a Dynamic Smart Block's tracklist fresh at the moment it's
     * actually needed for playback, so newly-uploaded matching media shows up
     * immediately rather than waiting for the next scheduled sync. This gives the same
     * behavior cheaply: whenever AutoDJ asks this playlist for its queue, if it's a
     * Dynamic Smart Block and hasn't been resynced very recently, resync it inline
     * first (throttled so rapid repeated calls in one AutoDJ cycle don't hammer the DB).
     *
     * Static Smart Blocks and ordinary playlists are untouched -- this only ever
     * affects `is_smart_block = true` playlists with `smart_block_type = Dynamic`.
     * Failures here are swallowed (falling back to whatever membership already exists)
     * so a Smart Block issue never breaks AutoDJ playback itself.
     */
    private function maybeResyncDynamicSmartBlock(StationPlaylist $playlist): void
    {
        if (!$playlist->is_smart_block || SmartBlockType::Dynamic !== $playlist->smart_block_type) {
            return;
        }

        $playlistId = $playlist->id;
        $now = time();
        $lastSync = $this->smartBlockLastInlineSyncAt[$playlistId] ?? 0;

        if (($now - $lastSync) < self::SMART_BLOCK_INLINE_RESYNC_THROTTLE_SECONDS) {
            return;
        }

        $this->smartBlockLastInlineSyncAt[$playlistId] = $now;

        try {
            $this->smartBlockSyncer->sync($playlist);
        } catch (Throwable) {
            // Deliberately ignored -- see method docblock.
        }
    }

    public function isQueueCompletelyFilled(StationPlaylist $playlist): bool
    {        if (PlaylistSources::Songs !== $playlist->source) {
            return true;
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return true;
        }

        $notQueuedMediaCount = $this->getCountPlaylistMediaBaseQuery($playlist)
            ->andWhere('spm.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return $notQueuedMediaCount === 0;
    }

    public function isQueueEmpty(StationPlaylist $playlist): bool
    {
        if (PlaylistSources::Songs !== $playlist->source) {
            return false;
        }

        if (PlaylistOrders::Random === $playlist->order) {
            return false;
        }

        $totalMediaCount = $this->getCountPlaylistMediaBaseQuery($playlist)
            ->getQuery()
            ->getSingleScalarResult();

        $notQueuedMediaCount = $this->getCountPlaylistMediaBaseQuery($playlist)
            ->andWhere('spm.is_queued = 0')
            ->getQuery()
            ->getSingleScalarResult();

        return $notQueuedMediaCount === $totalMediaCount;
    }

    private function getCountPlaylistMediaBaseQuery(StationPlaylist $playlist): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('count(spm.id)')
            ->from(StationMedia::class, 'sm')
            ->join('sm.playlists', 'spm')
            ->where('spm.playlist = :playlist')
            ->setParameter('playlist', $playlist);
    }
}
