<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\StationSchedule;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\DuplicatePrevention;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duration-aware format clock planner: selects the active anchor slot for the
 * current second in the hour and picks media that fits before the next anchor.
 */
final class ClockWheelPlaybackPlanner
{
    private const int HOUR_SECONDS = 3600;

    /** Minimum playable window (seconds) before deferring a flexible music slot. */
    private const int MIN_MUSIC_WINDOW_SECONDS = 30;

    private const int MIN_TALK_WINDOW_SECONDS = 45;

    private const int MIN_SHORT_FORM_WINDOW_SECONDS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

  /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function resolveNextQueueEntry(
        StationClockWheel $wheel,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
        StationSchedule $activeSchedule,
    ): ?StationQueue {
        $slots = $this->sortSlots($wheel->slots->toArray());

        $station = $wheel->station;

        if ($slots === []) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlots,
            );

            return null;
        }
        $tz = $station->getTimezoneObject();
        $secondsIntoHour = $this->getPlannedSecondsIntoHour($station, $expectedPlayTime, $tz);

        $activeIndex = $this->getActiveSlotIndex($slots, $secondsIntoHour);
        $activeSlot = $slots[$activeIndex];
        $nextAnchor = $this->getNextAnchorSeconds($slots, $activeIndex);
        $availableSeconds = max(1, $nextAnchor - $secondsIntoHour);
        $minWindow = $this->getMinWindowSeconds($activeSlot);

        $this->logger->info('Clock Wheel slot selection.', [
            'clock_wheel_id' => $wheel->id,
            'seconds_into_hour' => $secondsIntoHour,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'active_slot_order' => $activeSlot->slot_order,
            'active_position_seconds' => $activeSlot->position_seconds,
            'next_anchor_seconds' => $nextAnchor,
            'available_seconds' => $availableSeconds,
            'min_window_seconds' => $minWindow,
            'slot_type' => $activeSlot->type?->value,
        ]);

        if ($this->isFlexibleMusicSlot($activeSlot) && $availableSeconds < $minWindow) {
            $this->logger->info(
                'Clock Wheel: insufficient time before next anchor; deferring to next BuildQueue tick.',
                [
                    'available_seconds' => $availableSeconds,
                    'min_window_seconds' => $minWindow,
                    'slot_type' => $activeSlot->type?->value,
                ]
            );
            $this->eventLogger->recordDeferred(
                $station,
                $wheel,
                $activeSlot,
                $expectedPlayTime,
                ClockWheelFallbackReason::DeferredInsufficientWindow,
                $secondsIntoHour,
            );

            return null;
        }

        $scheduleMode = $activeSchedule->clock_wheel_mode ?? ClockWheelScheduleMode::Flexible;

        return $this->resolveSlot(
            $wheel,
            $activeSlot,
            $recentHistory,
            $availableSeconds,
            $scheduleMode,
            $station,
            $expectedPlayTime,
            $secondsIntoHour,
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
                $a->position_seconds <=> $b->position_seconds
                ?: $a->slot_order <=> $b->slot_order
        );

        return $slots;
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour so anchors stay aligned when the
     * AutoDJ queue is built ahead of wall clock time.
     */
    private function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $seconds = $local->getTimestamp() - $hourStart->getTimestamp();

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;
            if ($playedAt === null) {
                continue;
            }

            $queuedLocal = CarbonImmutable::instance($playedAt)->setTimezone($tz);
            if ($queuedLocal->format('Y-m-d H') !== $local->format('Y-m-d H')) {
                continue;
            }

            if ($queuedLocal->greaterThanOrEqualTo($local)) {
                continue;
            }

            $queuedHourStart = $queuedLocal->startOf('hour');
            $queuedStartOffset = $queuedLocal->getTimestamp() - $queuedHourStart->getTimestamp();
            $queuedEndOffset = $queuedStartOffset + (int)ceil((float)($row->duration ?? 0));

            $seconds = max($seconds, min($queuedEndOffset, self::HOUR_SECONDS - 1));
        }

        return min(max(0, $seconds), self::HOUR_SECONDS - 1);
    }

    private function getMinWindowSeconds(StationClockWheelSlot $slot): int
    {
        if (!$this->isFlexibleMusicSlot($slot)) {
            return self::MIN_SHORT_FORM_WINDOW_SECONDS;
        }

        return match ($slot->type) {
            ClockWheelSlotTypes::Talk => self::MIN_TALK_WINDOW_SECONDS,
            ClockWheelSlotTypes::Music, null => self::MIN_MUSIC_WINDOW_SECONDS,
            default => self::MIN_MUSIC_WINDOW_SECONDS,
        };
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getActiveSlotIndex(array $slots, int $secondsIntoHour): int
    {
        $activeIndex = 0;

        foreach ($slots as $index => $slot) {
            if ($slot->position_seconds <= $secondsIntoHour) {
                $activeIndex = $index;
            } else {
                break;
            }
        }

        return $activeIndex;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getNextAnchorSeconds(array $slots, int $activeIndex): int
    {
        if (isset($slots[$activeIndex + 1])) {
            return $slots[$activeIndex + 1]->position_seconds;
        }

        return self::HOUR_SECONDS;
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
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
    ): ?StationQueue {
        $type = $slot->type;
        $categoryId = $slot->category_id;
        $playlistId = $slot->playlist_id;

        if ($type === null) {
            $this->logger->warning('Clock Wheel slot has no type — skipping.');
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlotType,
                $secondsIntoHour,
            );

            return null;
        }

        $params = ['storageLocation' => $station->media_storage_location];
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation';

        if ($playlistId !== null && $playlistId > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $playlistId;
        }

        $dql .= ' AND m.type = :type';
        $params['type'] = $type->value;

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
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $strictSchedule = ClockWheelScheduleMode::Strict === $scheduleMode;

        $candidates = $this->filterByDuration($candidates, $maxDuration, $slot, $strictSchedule);

        if ($candidates === []) {
            $this->logger->warning(
                'Clock Wheel slot: no media fits the available window.',
                ['available_seconds' => $availableSeconds, 'max_duration' => $maxDuration]
            );
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaFitsWindow,
                $secondsIntoHour,
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
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::DuplicatePreventionEmpty,
                $secondsIntoHour,
            );

            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::MediaNotFound,
                $secondsIntoHour,
            );

            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->clock_wheel = $slot->clock_wheel;
        $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
        $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
        $queueEntry->clock_wheel_enforce_cap = $this->shouldEnforcePlaybackCap(
            $slot,
            $scheduleMode,
            $media,
            $maxDuration
        );
        $this->em->persist($queueEntry);

        $this->eventLogger->recordTrackQueued(
            $station,
            $wheel,
            $slot,
            $media,
            $expectedPlayTime,
            $secondsIntoHour,
        );

        $this->logger->info('Clock Wheel resolved track.', [
            'media_id' => $media->id,
            'title' => $media->title,
            'effective_length' => $media->getCalculatedLength(),
            'available_seconds' => $availableSeconds,
            'clock_wheel_schedule_mode' => $scheduleMode->value,
            'clock_wheel_enforce_cap' => $queueEntry->clock_wheel_enforce_cap,
        ]);

        return $queueEntry;
    }

    private function resolveMaxDuration(StationClockWheelSlot $slot, int $availableSeconds): float
    {
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return (float)min($slot->duration_seconds, $availableSeconds);
        }

        return (float)$availableSeconds;
    }

    /**
     * When true, AutoDJ applies a cue_out cap (Liquidsoap) as a fallback after PHP track selection.
     */
    private function shouldEnforcePlaybackCap(
        StationClockWheelSlot $slot,
        ClockWheelScheduleMode $scheduleMode,
        StationMedia $media,
        float $maxDuration,
    ): bool {
        if (ClockWheelScheduleMode::Strict === $scheduleMode) {
            return true;
        }

        if (!$this->isFlexibleMusicSlot($slot)) {
            return true;
        }

        return $media->getCalculatedLength() > $maxDuration;
    }

    private function filterByDuration(
        array $candidates,
        float $maxDuration,
        StationClockWheelSlot $slot,
        bool $strictSchedule,
    ): array {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            return $fitting;
        }

        if ($strictSchedule) {
            return [];
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static fn (StationMedia $a, StationMedia $b): int =>
                $a->getCalculatedLength() <=> $b->getCalculatedLength()
        );

        $shortest = $candidates[0];
        $logKey = $this->isFlexibleMusicSlot($slot)
            ? 'Clock Wheel: no track fits the available window; using shortest music/talk candidate.'
            : 'Clock Wheel: no short-form track fits the available window; using shortest candidate with playback cap.';

        $this->logger->warning(
            $logKey,
            [
                'available_seconds' => $maxDuration,
                'media_id' => $shortest->id,
                'effective_length' => $shortest->getCalculatedLength(),
                'slot_type' => $slot->type?->value,
            ]
        );

        return [$shortest];
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
