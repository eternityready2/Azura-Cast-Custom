<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Cache\NowPlayingCache;
use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Repository\SongHistoryRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Song;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\AutoDJ\ClockWheel\ClockWheelLegalIdPlaybackService;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Utilities\Time;
use App\Utilities\Types;
use RuntimeException;

final class FeedbackCommand extends AbstractCommand
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly SongHistoryRepository $historyRepo,
        private readonly NowPlayingCache $nowPlayingCache,
        private readonly ClockWheelLegalIdPlaybackService $legalIdPlaybackService,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): bool {
        if (!$asAutoDj) {
            return false;
        }

        $payload = array_map(
            fn($dataVal) => match (true) {
                'true' === $dataVal || 'false' === $dataVal => Types::bool(
                    $dataVal,
                    false,
                    true
                ),
                is_numeric($dataVal) => Types::float($dataVal),
                default => $dataVal
            },
            $payload
        );

        if ($this->isDuplicateTopOfHourFeedback($station, $payload)) {
            return true;
        }

        $historyRow = $this->getSongHistory($station, $payload);
        $this->em->persist($historyRow);

        $this->historyRepo->changeCurrentSong($station, $historyRow);
        $this->em->flush();

        $this->nowPlayingCache->forceUpdate($station);
        return true;
    }

    private function getSongHistory(
        Station $station,
        array $payload
    ): SongHistory {
        if (empty($payload['media_id'])) {
            if (empty($payload['artist']) && empty($payload['title'])) {
                throw new RuntimeException('No payload provided.');
            }

            $newSong = Song::createFromArray([
                'artist' => $payload['artist'] ?? '',
                'title' => $payload['title'] ?? '',
            ]);

            if (!$this->historyRepo->isDifferentFromCurrentSong($station, $newSong)) {
                throw new RuntimeException('Song is not different from current song.');
            }

            return new SongHistory($station, $newSong);
        }

        $media = $this->em->find(StationMedia::class, $payload['media_id']);
        if (!$media instanceof StationMedia) {
            throw new RuntimeException('Media ID does not exist for station.');
        }

        $isTopOfHourFallback = !empty($payload['azuracast_top_of_hour_fallback']);
        $isTopOfHourId = !empty($payload['azuracast_top_of_hour_id']);

        // A TOH transition may promote its metadata before feedback reaches PHP.
        // Reconcile the exact queue row even when the current metadata is already
        // the same Station ID; rejecting here previously left TOH state stale.
        if (
            !$isTopOfHourId
            && !$this->historyRepo->isDifferentFromCurrentSong($station, $media)
        ) {
            throw new RuntimeException('Song is not different from current song.');
        }

        if (!empty($payload['sq_id'])) {
            $sq = $this->em->find(StationQueue::class, $payload['sq_id']);
        } elseif ($isTopOfHourFallback) {
            $sq = $this->resolveTopOfHourQueueRow($station, $media);
        } else {
            $sq = $this->queueRepo->findRecentlyCuedSong($station, $media);

            if ($sq instanceof StationQueue) {
                if (null === $sq->media) {
                    $sq->media = $media;
                }

                if (!empty($payload['playlist_id']) && null === $sq->playlist) {
                    $playlist = $this->em->find(StationPlaylist::class, $payload['playlist_id']);
                    if ($playlist instanceof StationPlaylist) {
                        $sq->playlist = $playlist;
                    }
                }

                $this->em->persist($sq);
                $this->em->flush();
            }
        }

        if ($sq instanceof StationQueue) {
            $this->legalIdPlaybackService->recordPlaybackIfLegalId($station, $sq, $media);
            $this->queueRepo->trackPlayed($station, $sq);
            return SongHistory::fromQueue($sq);
        }

        $history = new SongHistory($station, $media);
        $history->media = $media;

        if (!empty($payload['playlist_id'])) {
            $playlist = $this->em->find(StationPlaylist::class, $payload['playlist_id']);
            if ($playlist instanceof StationPlaylist) {
                $history->playlist = $playlist;
            }
        }

        return $history;
    }

    private function isDuplicateTopOfHourFeedback(Station $station, array $payload): bool
    {
        $isTopOfHour = !empty($payload['azuracast_top_of_hour_id'])
            || !empty($payload['azuracast_top_of_hour_fallback']);

        if (!empty($payload['sq_id'])) {
            $queueRow = $this->em->find(StationQueue::class, $payload['sq_id']);
            if (
                $queueRow instanceof StationQueue
                && $queueRow->is_played
                && ($queueRow->top_of_hour_legal_id || $isTopOfHour)
            ) {
                return true;
            }
        }

        if (!$isTopOfHour) {
            return false;
        }

        $now = Time::nowUtc()->toDateTimeImmutable();
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
            $station,
            $now,
        );
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );
        $windowEnd = $targetTop->modify('+30 seconds');

        $count = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(sq.id)
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.top_of_hour_legal_id = 1
                AND sq.is_played = 1
                AND sq.timestamp_played >= :windowStart
                AND sq.timestamp_played <= :windowEnd
            DQL
        )->setParameter('station', $station)
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function resolveTopOfHourQueueRow(
        Station $station,
        StationMedia $media,
    ): StationQueue {
        $now = Time::nowUtc()->toDateTimeImmutable();
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
            $station,
            $now,
        );
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );

        $planned = $this->queueRepo->findUnplayedTopOfHourLegalIdBetween(
            $station,
            $windowStart,
            $targetTop,
        );
        if ($planned instanceof StationQueue) {
            $this->eventLogger->recordTopOfHourFallback(
                $station,
                $targetTop,
                ClockWheelFallbackReason::TopOfHourHardClock,
            );
            $this->em->flush();

            return $planned;
        }

        $fallback = StationQueue::fromMedia($station, $media);
        $fallback->top_of_hour_legal_id = true;
        $fallback->timestamp_cued = $now;
        $fallback->timestamp_played = $now;
        $fallback->sent_to_autodj = true;
        $this->em->persist($fallback);
        $this->em->flush();

        $this->eventLogger->recordTopOfHourLegalIdQueued(
            $station,
            $media,
            $targetTop,
            $fallback,
        );
        $this->eventLogger->recordTopOfHourFallback(
            $station,
            $targetTop,
            ClockWheelFallbackReason::TopOfHourHardClock,
        );
        $this->em->flush();

        return $fallback;
    }
}
