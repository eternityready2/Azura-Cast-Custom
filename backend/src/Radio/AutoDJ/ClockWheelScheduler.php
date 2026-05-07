<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
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
        private readonly StationPlaylistMediaRepository $spmRepo,
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
     */
    private function resolveSlot(
        StationClockWheelSlot $slot,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        // If the slot is pinned to a specific playlist, use it directly.
        if (null !== $slot->playlist) {
            return $this->getSongFromPlaylist($slot->playlist, $recentHistory, $expectedPlayTime);
        }

        // Otherwise, fall back to any active playlist on the station.
        // For now: pick the first enabled non-jingle playlist.
        $station = $slot->clock_wheel->station;
        foreach ($station->playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if (!$playlist->is_enabled) {
                continue;
            }
            $result = $this->getSongFromPlaylist($playlist, $recentHistory, $expectedPlayTime);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Pull a track from a playlist, respecting duplicate prevention.
     */
    private function getSongFromPlaylist(
        StationPlaylist $playlist,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime
    ): ?StationQueue {
        $mediaQueue = $this->spmRepo->getQueue($playlist);

        if (empty($mediaQueue)) {
            return null;
        }

        $validTrack = $playlist->avoid_duplicates
            ? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            : array_shift($mediaQueue);

        if (null === $validTrack) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $spm = $this->em->find(StationPlaylistMedia::class, $validTrack->spm_id);
        if ($spm instanceof StationPlaylistMedia) {
            $spm->played($expectedPlayTime->getTimestamp());
            $this->em->persist($spm);
        }

        $playlist->played_at = $expectedPlayTime;
        $this->em->persist($playlist);

        $queueEntry = StationQueue::fromMedia($playlist->station, $media);
        $queueEntry->playlist = $playlist;
        $this->em->persist($queueEntry);

        return $queueEntry;
    }
}
