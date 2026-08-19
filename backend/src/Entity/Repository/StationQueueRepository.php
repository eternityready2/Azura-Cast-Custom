<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Interfaces\SongInterface;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStationBasedRepository<StationQueue>
 */
final class StationQueueRepository extends AbstractStationBasedRepository
{
    protected string $entityClass = StationQueue::class;

    public function clearForMediaAndPlaylist(
        StationMedia $media,
        StationPlaylist $playlist
    ): void {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.media = :media 
                AND sq.playlist = :playlist
                AND sq.is_played = 0
            DQL
        )->setParameter('media', $media)
            ->setParameter('playlist', $playlist)
            ->execute();
    }

    public function clearForPlaylist(
        StationPlaylist $playlist
    ): void {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.playlist = :playlist
                AND sq.is_played = 0
            DQL
        )->setParameter('playlist', $playlist)
            ->execute();
    }

    public function getNextVisible(Station $station): ?StationQueue
    {
        return $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.is_visible = 1')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function trackPlayed(
        Station $station,
        StationQueue $row
    ): void {
        $this->em->createQuery(
            <<<'DQL'
            UPDATE App\Entity\StationQueue sq
            SET sq.timestamp_played = :timestamp
            WHERE sq.station = :station
            AND sq.id = :id
            DQL
        )->setParameter('timestamp', Time::nowUtc())
            ->setParameter('station', $station)
            ->setParameter('id', $row->id)
            ->execute();

        $this->em->createQuery(
            <<<'DQL'
            UPDATE App\Entity\StationQueue sq
            SET sq.is_played=1, sq.sent_to_autodj=1
            WHERE sq.station = :station 
            AND sq.is_played = 0 
            AND (sq.id = :id OR sq.timestamp_cued < :cued)
        DQL
        )->setParameter('station', $station)
            ->setParameter('id', $row->id)
            ->setParameter('cued', $row->timestamp_cued)
            ->execute();
    }

    public function isPlaylistRecentlyPlayed(
        StationPlaylist $playlist,
        ?int $playPerSongs = null
    ): bool {
        $playPerSongs ??= $playlist->play_per_songs;

        $recentPlayedQuery = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sq.playlist) AS playlist_id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND (sq.playlist = :playlist OR sq.is_visible = 1)
                ORDER BY sq.id DESC
            DQL
        )->setParameters([
            'station' => $playlist->station,
            'playlist' => $playlist,
        ])->setMaxResults($playPerSongs);

        $recentPlayedPlaylists = $recentPlayedQuery->getSingleColumnResult();
        return in_array($playlist->id, (array)$recentPlayedPlaylists, true);
    }

    /**
     * @return mixed[]
     */
    public function getRecentlyPlayedByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, sq.album, COALESCE(sm.type, 'music') as media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND (sq.is_played = 0 OR sq.timestamp_played >= :threshold)
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', $threshold)
            ->getArrayResult();
    }

    /**
     * Legal-compliance history (DMCA §114 counting). Unlike getRecentlyPlayedByTimeRange()
     * -- which intentionally includes not-yet-played queued rows for AutoDJ duplicate
     * prevention -- this only returns tracks that have ACTUALLY aired, and only music-type
     * media, so scheduled-but-unplayed picks and non-music items (AI DJ clips, AI News,
     * station IDs) never inflate a DMCA play count.
     */
    public function getPlayedMusicHistoryByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, sq.album, COALESCE(sm.type, 'music') as media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played >= :threshold
                AND sq.media IS NOT NULL
                AND sm.type = 'music'
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('threshold', $threshold)
            ->getArrayResult();
    }

    /**
     * Recent plays with media category for clock wheel category separation (PR9).
     *
     * @return array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null, category_id:int|null}>
     */
    public function getRecentlyPlayedWithCategoryByTimeRange(
        Station $station,
        DateTimeImmutable $now,
        int $minutes
    ): array {
        $threshold = CarbonImmutable::instance($now)->subMinutes($minutes);

        return $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id, sq.timestamp_played, sq.title, sq.artist, m.category_id
                FROM App\Entity\StationQueue sq
                LEFT JOIN App\Entity\StationMedia m WITH m.song_id = sq.song_id AND m.storage_location = :storageLocation
                WHERE sq.station = :station
                AND (sq.is_played = 0 OR sq.timestamp_played >= :threshold)
                ORDER BY sq.timestamp_played DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('storageLocation', $station->media_storage_location)
            ->setParameter('threshold', $threshold)
            ->getArrayResult();
    }

    /**
     * @param Station $station
     * @return StationQueue[]
     */
    public function getUnplayedQueue(Station $station): array
    {
        return $this->getUnplayedBaseQuery($station)->getQuery()->execute();
    }

    public function hasUnplayedQueue(Station $station): bool
    {
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 0
            DQL
        )->setParameter('station', $station)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $result;
    }

    public function hasTopOfHourLegalIdCuedBetween(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): bool {
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.top_of_hour_legal_id = 1
                AND sq.timestamp_cued >= :start
                AND sq.timestamp_cued <= :end
            DQL
        )->setParameter('station', $station)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $result;
    }

    /**
     * Timestamps of already-AIRED (is_played = 1) mandatory legal IDs whose
     * timestamp_played falls within the given window.
     *
     * hasTopOfHourIdQueued() in HourBoundaryPlanner only scans the unplayed queue, so
     * once an hour's ID has actually played it drops out of that scan entirely -- a
     * later BuildQueue evaluation (e.g. the once-a-minute interrupt-fallback tick
     * re-firing, or a slot whose expected-play-time still resolves to the same
     * boundary) can then see "nothing queued for this hour" and queue a second,
     * duplicate ID. The caller re-applies the same boundary-rollover math used for
     * unplayed rows (a track played at :58/:59 serves the *next* hour's boundary),
     * so the window here is intentionally wider than the hour itself -- it must
     * include the tail of the preceding hour where an on-time ID would actually air.
     *
     * @return DateTimeImmutable[]
     */
    public function getRecentlyPlayedTopOfHourLegalIds(
        Station $station,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_played
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played >= :windowStart
                AND sq.timestamp_played <= :windowEnd
                AND (
                    sq.top_of_hour_legal_id = 1
                    OR sm.type IN (:idTypes)
                )
            DQL
        )->setParameter('station', $station)
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->setParameter('idTypes', StationMediaTypes::stationIdTypeValues())
            ->getArrayResult();

        return array_map(
            static fn (array $row): DateTimeImmutable => $row['timestamp_played'],
            $rows
        );
    }

    /**
     * @return array{
     *     tolerance_seconds: int,
     *     hours_with_legal_id: int,
     *     on_time_count: int,
     *     late_count: int,
     *     compliance_percent: float|null,
     *     fallback_count: int,
     *     late_events: array<int, array{expected_play_at: string, actual_play_at: string, drift_seconds: int}>
     * }
     */
    public function getTopOfHourLegalIdComplianceSummary(
        Station $station,
        DateTimeImmutable $since,
        int $toleranceSeconds,
        ?DateTimeImmutable $until = null,
    ): array {
        $until ??= new DateTimeImmutable('now');

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_played, COALESCE(sm.type, '') AS media_type
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.media sm
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.top_of_hour_legal_id = 1
                AND sq.timestamp_played >= :since
                AND sq.timestamp_played <= :until
                ORDER BY sq.timestamp_played ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getArrayResult();

        $onTimeCount = 0;
        $fallbackCount = 0;
        $lateEvents = [];
        $timezone = $station->getTimezoneObject();

        foreach ($rows as $row) {
            if (!$row['timestamp_played'] instanceof \DateTimeInterface) {
                continue;
            }

            $actual = CarbonImmutable::instance($row['timestamp_played'])->setTimezone($timezone);
            $hourStart = $actual->startOfHour();
            $nextHour = $hourStart->addHour();
            $expected = abs($actual->getTimestamp() - $hourStart->getTimestamp())
                <= abs($nextHour->getTimestamp() - $actual->getTimestamp())
                ? $hourStart
                : $nextHour;
            $driftSeconds = abs($actual->getTimestamp() - $expected->getTimestamp());

            if ($driftSeconds <= $toleranceSeconds) {
                $onTimeCount++;
            } else {
                $lateEvents[] = [
                    'expected_play_at' => $expected->format(DateTimeImmutable::ATOM),
                    'actual_play_at' => $actual->format(DateTimeImmutable::ATOM),
                    'drift_seconds' => $driftSeconds,
                ];
            }

            if (!StationMediaTypes::isStationId((string)$row['media_type'])) {
                $fallbackCount++;
            }
        }

        $total = $onTimeCount + count($lateEvents);

        return [
            'tolerance_seconds' => $toleranceSeconds,
            'hours_with_legal_id' => $total,
            'on_time_count' => $onTimeCount,
            'late_count' => count($lateEvents),
            'compliance_percent' => $total > 0 ? round(($onTimeCount / $total) * 100, 1) : null,
            'fallback_count' => $fallbackCount,
            'late_events' => $lateEvents,
        ];
    }

    public function clearUpcomingQueue(Station $station): void
    {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.sent_to_autodj = 0
            DQL
        )->setParameter('station', $station)
            ->execute();
    }

    public function getNextToSendToAutoDj(Station $station): ?StationQueue
    {
        return $this->getBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 0')
            ->orderBy('sq.timestamp_cued', 'ASC')
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function findRecentlyCuedSong(
        Station $station,
        SongInterface $song
    ): ?StationQueue {
        return $this->getUnplayedBaseQuery($station)
            ->andWhere('sq.sent_to_autodj = 1')
            ->andWhere('sq.song_id = :song_id')
            ->setParameter('song_id', $song->song_id)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function hasCuedPlaylistMedia(StationPlaylist $playlist): bool
    {
        $station = $playlist->station;

        $cuedPlaylistContentCountQuery = $this->getUnplayedBaseQuery($station)
            ->select('count(sq.id)')
            ->andWhere('sq.playlist = :playlist')
            ->setParameter('playlist', $playlist)
            ->getQuery();

        $cuedPlaylistContentCount = $cuedPlaylistContentCountQuery->getSingleScalarResult();
        return $cuedPlaylistContentCount > 0;
    }

    public function getUnplayedBaseQuery(Station $station): QueryBuilder
    {
        return $this->getBaseQuery($station)
            ->andWhere('sq.is_played = 0')
            ->orderBy('sq.sent_to_autodj', 'DESC')
            ->addOrderBy('sq.timestamp_cued', 'ASC');
    }

    private function getBaseQuery(Station $station): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('sq, sm, sp, scw')
            ->from(StationQueue::class, 'sq')
            ->leftJoin('sq.media', 'sm')
            ->leftJoin('sq.playlist', 'sp')
            ->leftJoin('sq.clock_wheel', 'scw')
            ->where('sq.station = :station')
            ->setParameter('station', $station);
    }

    public function clearUnplayed(?Station $station = null): void
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(StationQueue::class, 'sq')
            ->where('sq.is_played = 0');

        if (null !== $station) {
            $qb->andWhere('sq.station = :station')
                ->setParameter('station', $station);
        }

        $qb->getQuery()->execute();
    }

    public function cleanup(int $daysToKeep): void
    {
        $threshold = Time::nowUtc()->subDays($daysToKeep);

        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationQueue sq
                WHERE sq.timestamp_cued <= :threshold
            DQL
        )->setParameter('threshold', $threshold)
            ->execute();
    }
}
