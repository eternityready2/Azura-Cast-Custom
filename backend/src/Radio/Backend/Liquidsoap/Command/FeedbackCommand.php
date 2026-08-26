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

        // The hard-clock fallback can report the same Station ID that was
        // already promoted to current metadata by the TOH transition. Do not
        // reject that feedback before reconciling its queue row; doing so left
        // the legal-ID row permanently unplayed and blocked normal AutoDJ.
        if (
            !$isTopOfHourFallback
            && !$this->historyRepo->isDifferentFromCurrentSong($station, $media)
        ) {
            throw new RuntimeException('Song is not different from current song.');
        }

        if (!empty($payload['sq_id'])) {
            $sq = $this->em->find(StationQueue::class, $payload['sq_id']);
        } elseif ($isTopOfHourFallback) {
            // A hard-clock fallback is owned by the TOH coordinator. Resolve it
            // against the boundary row before considering ordinary same-media
            // queue entries so an unrelated station-ID play cannot absorb the
            // fallback feedback.
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
