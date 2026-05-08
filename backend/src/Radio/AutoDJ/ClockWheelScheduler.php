<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Event\Radio\BuildQueue;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Intercepts the AutoDJ queue building process to inject Clock Wheel playback.
 *
 * When a StationClockWheelEvent is active at the expected play time, this
 * subscriber fires BEFORE the normal QueueBuilder and resolves the next song
 * from the wheel's ordered slots, bypassing normal playlist rotation entirely.
 */
final class ClockWheelScheduler implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 3 — runs after requests (5) but before normal QueueBuilder (0)
            BuildQueue::class => [
                ['buildFromClockWheel', 3],
            ],
        ];
    }

    public function buildFromClockWheel(BuildQueue $event): void
    {
        // If the event already has a next song (e.g. from a request), skip.
        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $activeEvent = $this->findActiveClockWheelSchedule($station->id, $expectedPlayTime);

        if (null === $activeEvent) {
            return;
        }

        $wheel = $activeEvent->clock_wheel;

        $this->logger->info(
            sprintf('Clock Wheel "%s" is active. Overriding normal AutoDJ queue.', $wheel->name),
            ['clock_wheel_id' => $wheel->id, 'schedule_id' => $activeEvent->id]
        );

        $recentHistory = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $nextSong = $this->resolveNextSongFromWheel($wheel, $recentHistory, $expectedPlayTime);

        if (null !== $nextSong) {
            $set = $event->setNextSongs($nextSong);

            if ($set) {
                $this->em->flush();
                $this->logger->info(
                    'Clock Wheel resolved next song.',
                    ['next_song' => (string)$event]
                );
            }
        } else {
            $this->logger->warning(
                sprintf('Clock Wheel "%s" could not resolve a playable track. Falling through to normal AutoDJ.', $wheel->name)
            );
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Find a StationSchedule that links to an active Clock Wheel for the given station and time.
     */
    private function findActiveClockWheelSchedule(int $stationId, DateTimeImmutable $now): ?StationSchedule
    {
        // AzuraCast time-code: HHMM as integer (e.g. 09:30 → 930)
        $timeCode = (int)$now->format('G') * 100 + (int)$now->format('i');
        // ISO 8601 weekday: 1=Mon … 7=Sun
        $weekday = (int)$now->format('N');

        /** @var StationSchedule[] $schedules */
        $schedules = $this->em->createQuery(
            'SELECT s, w FROM App\Entity\StationSchedule s
             JOIN s.clock_wheel w
             WHERE w.station = :stationId
             AND w.is_active = true
             AND s.start_time <= :timeCode
             AND s.end_time > :timeCode'
        )
            ->setParameter('stationId', $stationId)
            ->setParameter('timeCode', $timeCode)
            ->getResult();

        foreach ($schedules as $schedule) {
            $days = $schedule->days;
            if (empty($days) || in_array($weekday, $days, true)) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Walk through the wheel's slots in order and return the first resolvable track.
     */
    private function resolveNextSongFromWheel(
        StationClockWheel $wheel,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $slots = $wheel->slots->toArray();

        // Sort slots by slot_order ascending
        usort($slots, static fn(StationClockWheelSlot $a, StationClockWheelSlot $b) => $a->slot_order <=> $b->slot_order);

        foreach ($slots as $slot) {
            $queue = $this->resolveSlot($slot, $recentHistory, $expectedPlayTime);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }

    /**
     * Resolve a single slot to a StationQueue entry.
     * Queries station_media directly by type, so no playlist assignment is needed.
     */
    private function resolveSlot(
        StationClockWheelSlot $slot,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $station = $slot->clock_wheel->station;
        $type = $slot->type;
        $categoryId = $slot->category_id;

        // Must have at least one filter.
        if ($type === null && $categoryId === null) {
            $this->logger->warning('Clock Wheel slot has neither type nor category set — skipping.');
            return null;
        }

        // Build DQL dynamically: filter by type and/or category.
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             JOIN m.storage_location sl
             JOIN sl.stations st
             WHERE st.id = :stationId';

        $params = ['stationId' => $station->id];

        if ($type !== null) {
            $dql .= ' AND m.type = :type';
            $params['type'] = $type;
        }

        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        // Fetch all media matching the slot filters from this station's storage locations.
        /** @var StationMedia[] $candidates */
        $candidates = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        if (empty($candidates)) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot: no media found with type "%s"%s for station %d.',
                    $type?->value ?? '(any)',
                    $categoryId !== null ? sprintf(' and category_id %d', $categoryId) : '',
                    $station->id
                )
            );
            return null;
        }

        // Build proper StationPlaylistQueue objects so duplicate prevention can match
        // on song_id, artist, and title — not just media_id.
        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new \App\Entity\Api\StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        // Apply the slot's algorithm to order candidates before duplicate prevention.
        $algorithm = $slot->algorithm ?? \App\Entity\Enums\ClockWheelSlotAlgorithms::Random;
        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        // Apply duplicate prevention.
        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if (null === $validTrack) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $this->em->persist($queueEntry);

        return $queueEntry;
    }

    /**
     * Orders $mediaQueue according to the given algorithm.
     *
     * - Random:           shuffle (fair random selection)
     * - OldestTrack:      track least recently played (or never played) comes first
     * - OldestAlbum:      tracks from the album least recently played come first
     * - OldestArtist:     tracks from the artist least recently played come first
     * - MostRecentAlbum:  tracks from the album most recently played come first
     * - MostRecentArtist: tracks from the artist most recently played come first
     *
     * @param \App\Entity\Api\StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     * @return \App\Entity\Api\StationPlaylistQueue[]
     */
    private function applyAlgorithm(
        array $mediaQueue,
        array $candidates,
        \App\Entity\Enums\ClockWheelSlotAlgorithms $algorithm,
        array $recentHistory
    ): array {
        if ($algorithm === \App\Entity\Enums\ClockWheelSlotAlgorithms::Random) {
            shuffle($mediaQueue);
            return $mediaQueue;
        }

        // Build song_id → timestamp_played (unix int) from history.
        // Lower timestamp = older play. 0 = never played (treat as oldest).
        $histTimestamp = []; // song_id → int
        $histArtist = [];    // song_id → string
        foreach ($recentHistory as $h) {
            $songId = $h['song_id'];
            $ts = $h['timestamp_played'];
            if ($ts instanceof \DateTimeInterface) {
                $ts = $ts->getTimestamp();
            }
            $ts = (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
            $histArtist[$songId] = $h['artist'] ?? '';
        }

        // OldestTrack: sort by last-played timestamp ASC; never-played (0) comes first.
        if ($algorithm === \App\Entity\Enums\ClockWheelSlotAlgorithms::OldestTrack) {
            usort($mediaQueue, static function (
                \App\Entity\Api\StationPlaylistQueue $a,
                \App\Entity\Api\StationPlaylistQueue $b
            ) use ($histTimestamp): int {
                $tsA = $histTimestamp[$a->song_id] ?? 0;
                $tsB = $histTimestamp[$b->song_id] ?? 0;
                return $tsA <=> $tsB; // 0 (never played) first, then oldest timestamp
            });
            return $mediaQueue;
        }

        // Album / Artist algorithms — group candidates, sort groups by recency.
        $isAlbum = in_array($algorithm, [
            \App\Entity\Enums\ClockWheelSlotAlgorithms::OldestAlbum,
            \App\Entity\Enums\ClockWheelSlotAlgorithms::MostRecentAlbum,
        ], true);
        $isOldest = in_array($algorithm, [
            \App\Entity\Enums\ClockWheelSlotAlgorithms::OldestAlbum,
            \App\Entity\Enums\ClockWheelSlotAlgorithms::OldestArtist,
        ], true);

        // Index candidates by id and song_id for lookups.
        $candidatesById = [];
        $candidatesBySongId = [];
        foreach ($candidates as $m) {
            $candidatesById[$m->id] = $m;
            $candidatesBySongId[$m->song_id] = $m;
        }

        // Determine the grouping key (album or artist) for each queue entry.
        $getGroupKey = static function (\App\Entity\Api\StationPlaylistQueue $q) use ($candidatesById, $isAlbum): string {
            $m = $candidatesById[$q->media_id] ?? null;
            if ($m === null) {
                return '';
            }
            return strtolower(trim((string)($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))));
        };

        // Group queue entries by album/artist key.
        $groups = []; // groupKey → StationPlaylistQueue[]
        foreach ($mediaQueue as $q) {
            $groups[$getGroupKey($q)][] = $q;
        }

        // Determine the most-recent play timestamp for each group.
        // A group's recency = highest timestamp of any history entry belonging to that group.
        // Groups with no history get 0 (= never played = oldest).
        $groupLastPlayed = array_fill_keys(array_keys($groups), 0);

        if ($isAlbum) {
            // For album-based grouping, look up album for history entries via DB when not in candidates.
            $histSongIds = array_keys($histTimestamp);
            $histAlbum = []; // song_id → album key

            foreach ($histSongIds as $songId) {
                if (isset($candidatesBySongId[$songId])) {
                    $histAlbum[$songId] = strtolower(trim((string)($candidatesBySongId[$songId]->album ?? '')));
                }
            }

            $missingSongIds = array_diff($histSongIds, array_keys($histAlbum));
            if (!empty($missingSongIds)) {
                $rows = $this->em->createQuery(
                    'SELECT m.song_id, m.album FROM App\Entity\StationMedia m WHERE m.song_id IN (:ids)'
                )->setParameter('ids', array_values($missingSongIds))->getArrayResult();
                foreach ($rows as $row) {
                    $histAlbum[$row['song_id']] = strtolower(trim((string)($row['album'] ?? '')));
                }
            }

            foreach ($histSongIds as $songId) {
                $albumKey = $histAlbum[$songId] ?? '';
                if (!array_key_exists($albumKey, $groupLastPlayed)) {
                    continue; // history entry belongs to an album not in candidates
                }
                $ts = $histTimestamp[$songId];
                if ($ts > $groupLastPlayed[$albumKey]) {
                    $groupLastPlayed[$albumKey] = $ts;
                }
            }
        } else {
            // Artist-based grouping: history already has artist field.
            foreach ($histTimestamp as $songId => $ts) {
                $artistKey = strtolower(trim((string)($histArtist[$songId] ?? '')));
                if (!array_key_exists($artistKey, $groupLastPlayed)) {
                    continue;
                }
                if ($ts > $groupLastPlayed[$artistKey]) {
                    $groupLastPlayed[$artistKey] = $ts;
                }
            }
        }

        // Sort group keys: oldest (lowest timestamp) first for OldestAlbum/Artist,
        // most recent (highest timestamp) first for MostRecentAlbum/Artist.
        // Shuffle first so ties between equal-timestamp groups are broken randomly.
        $groupKeys = array_keys($groups);
        shuffle($groupKeys);
        usort($groupKeys, static function (string $a, string $b) use ($groupLastPlayed, $isOldest): int {
            $tsA = $groupLastPlayed[$a];
            $tsB = $groupLastPlayed[$b];
            return $isOldest ? ($tsA <=> $tsB) : ($tsB <=> $tsA);
        });

        // Flatten into final ordered queue; shuffle within each group for track variety.
        $sorted = [];
        foreach ($groupKeys as $key) {
            $groupItems = $groups[$key];
            shuffle($groupItems);
            foreach ($groupItems as $q) {
                $sorted[] = $q;
            }
        }

        return $sorted;
    }
}
