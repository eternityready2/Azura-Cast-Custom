<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Station;
use App\Entity\StationQueue;
use Psr\SimpleCache\CacheInterface;

/**
 * Generic transport-retirement state for wall-clock interruptions.
 *
 * A retirement transaction is one-shot. Repeated /nextsong fetches while the
 * same song remains quarantined must never reset newly-sent queue rows back to
 * unsent, otherwise the same fresh post-clock song can be handed to Liquidsoap
 * repeatedly.
 */
final class AutoDjRetirementService
{
    use EntityManagerAwareTrait;

    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getExcludedSongId(Station $station): ?string
    {
        $value = $this->cache->get($this->getCacheKey($station));
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return '' !== $value ? $value : null;
    }

    /**
     * Activate a new retirement transaction.
     *
     * @return bool True only when a new/different quarantine was activated.
     */
    public function activate(
        Station $station,
        string $songId,
    ): bool {
        $songId = trim($songId);
        if ('' === $songId) {
            return false;
        }

        $alreadyExcluded = $this->getExcludedSongId($station);
        if (null !== $alreadyExcluded && hash_equals($alreadyExcluded, $songId)) {
            return false;
        }

        $this->cache->set(
            $this->getCacheKey($station),
            $songId,
            self::CACHE_TTL_SECONDS,
        );

        // Reconcile transport state exactly once for this retirement. Doing this
        // on every subsequent /nextsong call can turn a freshly handed-out row
        // back into sent_to_autodj=0 and cause it to be returned again.
        $this->em->createQuery(
            <<<'DQL'
                UPDATE App\Entity\StationQueue sq
                SET sq.sent_to_autodj = 0
                WHERE sq.station = :station
                AND sq.is_played = 0
                AND sq.top_of_hour_legal_id = 0
                AND sq.sent_to_autodj = 1
            DQL
        )->setParameter('station', $station)
            ->execute();

        // Rows for the interrupted song must not continue counting toward queue
        // depth while the quarantine is active. Listener requests are deferred,
        // not lost: make them pending again before removing their stale queue row.
        /** @var StationQueue[] $retiredRows */
        $retiredRows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq, sr
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.request sr
                WHERE sq.station = :station
                AND sq.is_played = 0
                AND sq.top_of_hour_legal_id = 0
                AND sq.song_id = :songId
            DQL
        )->setParameter('station', $station)
            ->setParameter('songId', $songId)
            ->getResult();

        foreach ($retiredRows as $queueRow) {
            if (null !== $queueRow->request) {
                $queueRow->request->played_at = null;
                $this->em->persist($queueRow->request);
            }

            $this->em->remove($queueRow);
        }

        $this->em->flush();
        return true;
    }

    /**
     * Absolute last handoff guard before an ordinary AutoDJ request is annotated.
     */
    public function getNextToSendToAutoDj(Station $station): ?StationQueue
    {
        $excludedSongId = $this->getExcludedSongId($station);
        if (null === $excludedSongId) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('sq, sm, sp, scw')
            ->from(StationQueue::class, 'sq')
            ->leftJoin('sq.media', 'sm')
            ->leftJoin('sq.playlist', 'sp')
            ->leftJoin('sq.clock_wheel', 'scw')
            ->where('sq.station = :station')
            ->andWhere('sq.is_played = 0')
            ->andWhere('sq.sent_to_autodj = 0')
            ->andWhere('sq.top_of_hour_legal_id = 0')
            ->andWhere('sq.song_id != :excludedSongId')
            ->setParameter('station', $station)
            ->setParameter('excludedSongId', $excludedSongId)
            ->orderBy('sq.timestamp_cued', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function clear(Station $station): void
    {
        $this->cache->delete($this->getCacheKey($station));
    }

    public function isExcluded(Station $station, string $songId): bool
    {
        $excluded = $this->getExcludedSongId($station);
        return null !== $excluded && hash_equals($excluded, $songId);
    }

    private function getCacheKey(Station $station): string
    {
        $stationId = isset($station->id) ? $station->id : spl_object_id($station);
        return 'autodj.retired_song.' . $stationId;
    }
}
