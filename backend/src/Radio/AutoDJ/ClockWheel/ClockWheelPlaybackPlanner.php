<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\Scheduler;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sequential format-clock planner: plays wheel entries in list order, loops until the
 * calendar event ends, and cuts tracks at the schedule block end.
 */
final class ClockWheelPlaybackPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly Scheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function resolveNextQueueEntry(
        StationClockWheel $wheel,
        StationSchedule $activeSchedule,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
    ): ?StationQueue {
        $slots = $this->sortSlots($wheel->slots->toArray());

        if ($slots === []) {
            return null;
        }

        $station = $wheel->station;
        $tz = $station->getTimezoneObject();
        $occurrence = $this->scheduler->getActiveOccurrenceRange($activeSchedule, $tz, $expectedPlayTime);

        if ($occurrence === null) {
            $this->logger->debug('Clock Wheel: schedule occurrence not active at expected play time.');
            return null;
        }

        $blockStart = $occurrence->start;
        $blockEnd = $occurrence->end;
        $expectedLocal = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);

        if ($expectedLocal->greaterThanOrEqualTo($blockEnd)) {
            $this->logger->debug('Clock Wheel: schedule block has ended.');
            return null;
        }

        $remainingSeconds = (int)max(1, $blockEnd->getTimestamp() - $expectedLocal->getTimestamp());
        $playIndex = $this->countQueuedTracksInBlock($station, $blockStart, $expectedPlayTime);
        $activeIndex = $playIndex % count($slots);
        $activeSlot = $slots[$activeIndex];

        $this->logger->info('Clock Wheel sequential slot selection.', [
            'clock_wheel_id' => $wheel->id,
            'schedule_id' => $activeSchedule->id,
            'block_start' => $blockStart->toIso8601String(),
            'block_end' => $blockEnd->toIso8601String(),
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'play_index' => $playIndex,
            'active_slot_order' => $activeSlot->slot_order,
            'remaining_block_seconds' => $remainingSeconds,
            'slot_type' => $activeSlot->type?->value,
        ]);

        return $this->resolveSlot(
            $activeSlot,
            $recentHistory,
            $remainingSeconds
        );
    }

    /**
     * @param StationClockWheelSlot[] $slots
     *
     * @return StationClockWheelSlot[]
     */
    private function sortSlots(array $slots): array
    {
        usort(
            $slots,
            static fn (StationClockWheelSlot $a, StationClockWheelSlot $b): int =>
                $a->slot_order <=> $b->slot_order
        );

        return $slots;
    }

    private function countQueuedTracksInBlock(
        Station $station,
        CarbonImmutable $blockStart,
        DateTimeImmutable $until,
    ): int {
        $blockStartTs = $blockStart->getTimestamp();
        $untilTs = CarbonImmutable::instance($until)->getTimestamp();
        $count = 0;

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;
            if ($playedAt === null) {
                continue;
            }

            $ts = $playedAt->getTimestamp();
            if ($ts >= $blockStartTs && $ts < $untilTs) {
                $count++;
            }
        }

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(sq.id)
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.is_played = 1
                AND sq.timestamp_played >= :blockStart
                AND sq.timestamp_played < :until
            DQL
        )->setParameter('station', $station)
            ->setParameter('blockStart', $blockStart)
            ->setParameter('until', $until)
            ->getSingleScalarResult();

        return $count + (int)$rows;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveSlot(
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $remainingBlockSeconds,
    ): ?StationQueue {
        $station = $slot->clock_wheel->station;
        $type = $slot->type;
        $categoryId = $slot->category_id;
        $playlistId = $slot->playlist_id;

        if ($type === null && $categoryId === null && ($playlistId === null || $playlistId === 0)) {
            $this->logger->warning('Clock Wheel slot has no type, category, or playlist — skipping.');
            return null;
        }

        $params = ['stationId' => $station->id];
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             JOIN m.storage_location sl
             JOIN sl.stations st
             WHERE st.id = :stationId';

        if ($playlistId !== null && $playlistId > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $playlistId;
        }

        if ($type !== null) {
            $dql .= ' AND m.type = :type';
            $params['type'] = $type;
        }

        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        /** @var StationMedia[] $candidates */
        $candidates = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        if ($candidates === []) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot: no media found (type=%s, category=%s, playlist=%s).',
                    $type?->value ?? '(any)',
                    $categoryId ?? '(any)',
                    $playlistId ?? '(any)',
                )
            );
            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $remainingBlockSeconds);

        $candidates = $this->filterByDuration($candidates, $maxDuration, $slot);

        if ($candidates === []) {
            $this->logger->warning(
                'Clock Wheel slot: no media fits the remaining schedule window.',
                ['remaining_seconds' => $remainingBlockSeconds, 'max_duration' => $maxDuration]
            );
            return null;
        }

        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Random;
        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if ($validTrack === null) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $effectiveLength = $media->getCalculatedLength();
        $playLength = min($effectiveLength, $maxDuration);
        $queueEntry->duration = $playLength;

        $this->em->persist($queueEntry);

        $this->logger->info('Clock Wheel resolved track.', [
            'media_id' => $media->id,
            'title' => $media->title,
            'effective_length' => $effectiveLength,
            'play_length' => $playLength,
            'remaining_block_seconds' => $remainingBlockSeconds,
        ]);

        return $queueEntry;
    }

    private function resolveMaxDuration(StationClockWheelSlot $slot, int $remainingBlockSeconds): float
    {
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return (float)min($slot->duration_seconds, $remainingBlockSeconds);
        }

        return (float)$remainingBlockSeconds;
    }

    /**
     * @param StationMedia[] $candidates
     *
     * @return StationMedia[]
     */
    private function filterByDuration(
        array $candidates,
        float $maxDuration,
        StationClockWheelSlot $slot,
    ): array {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            return $fitting;
        }

        if ($this->isFlexibleMusicSlot($slot)) {
            usort(
                $candidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $a->getCalculatedLength() <=> $b->getCalculatedLength()
            );

            $shortest = $candidates[0];
            $this->logger->warning(
                'Clock Wheel: no track fits remaining window; using shortest candidate (will be cut at block end).',
                [
                    'remaining_seconds' => $maxDuration,
                    'media_id' => $shortest->id,
                    'effective_length' => $shortest->getCalculatedLength(),
                ]
            );

            return [$shortest];
        }

        return [];
    }

    private function isFlexibleMusicSlot(StationClockWheelSlot $slot): bool
    {
        if ($slot->duration_seconds !== null) {
            return false;
        }

        $type = $slot->type;

        return $type === null
            || $type === ClockWheelSlotTypes::Music
            || $type === ClockWheelSlotTypes::Talk;
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return StationPlaylistQueue[]
     */
    private function applyAlgorithm(
        array $mediaQueue,
        array $candidates,
        ClockWheelSlotAlgorithms $algorithm,
        array $recentHistory,
    ): array {
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            shuffle($mediaQueue);
            return $mediaQueue;
        }

        $histTimestamp = [];
        $histArtist = [];
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

        if ($algorithm === ClockWheelSlotAlgorithms::OldestTrack) {
            usort(
                $mediaQueue,
                static function (StationPlaylistQueue $a, StationPlaylistQueue $b) use ($histTimestamp): int {
                    $tsA = $histTimestamp[$a->song_id] ?? 0;
                    $tsB = $histTimestamp[$b->song_id] ?? 0;
                    return $tsA <=> $tsB;
                }
            );
            return $mediaQueue;
        }

        $isAlbum = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::MostRecentAlbum,
        ], true);
        $isOldest = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::OldestArtist,
        ], true);

        $candidatesById = [];
        $candidatesBySongId = [];
        foreach ($candidates as $m) {
            $candidatesById[$m->id] = $m;
            $candidatesBySongId[$m->song_id] = $m;
        }

        $getGroupKey = static function (StationPlaylistQueue $q) use ($candidatesById, $isAlbum): string {
            $m = $candidatesById[$q->media_id] ?? null;
            if ($m === null) {
                return '';
            }
            return strtolower(trim((string)($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))));
        };

        $groups = [];
        foreach ($mediaQueue as $q) {
            $groups[$getGroupKey($q)][] = $q;
        }

        $groupLastPlayed = array_fill_keys(array_keys($groups), 0);

        if ($isAlbum) {
            $histSongIds = array_keys($histTimestamp);
            $histAlbum = [];

            foreach ($histSongIds as $songId) {
                if (isset($candidatesBySongId[$songId])) {
                    $histAlbum[$songId] = strtolower(trim((string)($candidatesBySongId[$songId]->album ?? '')));
                }
            }

            $missingSongIds = array_diff($histSongIds, array_keys($histAlbum));
            if ($missingSongIds !== []) {
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
                    continue;
                }
                $ts = $histTimestamp[$songId];
                if ($ts > $groupLastPlayed[$albumKey]) {
                    $groupLastPlayed[$albumKey] = $ts;
                }
            }
        } else {
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

        $groupKeys = array_keys($groups);
        shuffle($groupKeys);
        usort($groupKeys, static function (string $a, string $b) use ($groupLastPlayed, $isOldest): int {
            $tsA = $groupLastPlayed[$a];
            $tsB = $groupLastPlayed[$b];
            return $isOldest ? ($tsA <=> $tsB) : ($tsB <=> $tsA);
        });

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
