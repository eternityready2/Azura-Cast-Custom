<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Repository\SongHistoryRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\SongHistory;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\ClockWheel\ClockWheelLegalIdPlaybackService;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\Queue;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Utilities\Time;
use Monolog\LogRecord;
use Psr\EventDispatcher\EventDispatcherInterface;

final class QueueInterruptingTracks extends AbstractTask
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Scheduler $scheduler,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly SongHistoryRepository $historyRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly ClockWheelLegalIdPlaybackService $legalIdPlaybackService,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $this->queueForStation($station);
            } finally {
                $this->logger->popProcessor();
            }
        }
    }

    private function queueForStation(Station $station): void
    {
        if (!$station->supportsAutoDjQueue()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        $this->enforceScheduledBoundary($station, $backend);

        $hasInterruptingPlaylist = $this->hourBoundaryPlanner->isTopOfHourInterruptDue(
            $station,
            Time::nowUtc(),
        );
        $tz = $station->getTimezoneObject();

        foreach ($station->playlists as $playlist) {
            if (
                $playlist->isPlayable(true)
                || $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz)
            ) {
                $hasInterruptingPlaylist = true;
                break;
            }
        }

        if (!$hasInterruptingPlaylist && !empty($this->sponsorGuarantee->getPlaylistsBehindPace($station))) {
            $hasInterruptingPlaylist = true;
        }

        if (!$hasInterruptingPlaylist) {
            return;
        }

        $topOfHourEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour);
        if (!$topOfHourEmpty) {
            $this->logger->debug(
                'Top-of-hour queue is active; deferring interrupting queue work for this tick.'
            );
            return;
        }

        $interruptingEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting);
        $songsToPlay = $this->queue->getInterruptingQueue($station);

        if (empty($songsToPlay)) {
            return;
        }

        foreach ($songsToPlay as $sq) {
            $event = AnnotateNextSong::fromStationQueue($sq, true);
            $this->eventDispatcher->dispatch($event);

            $track = $event->buildAnnotations();
            $queueName = $sq->top_of_hour_legal_id
                ? LiquidsoapQueues::TopOfHour
                : LiquidsoapQueues::Interrupting;

            $isEmpty = match ($queueName) {
                LiquidsoapQueues::TopOfHour => $backend->isQueueEmpty(
                    $station,
                    LiquidsoapQueues::TopOfHour
                ),
                LiquidsoapQueues::Interrupting => $interruptingEmpty
                    && $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting),
                default => false,
            };

            if (!$isEmpty) {
                $this->logger->info('Skipping enqueue; target queue is not empty.', [
                    'queue' => $queueName->value,
                ]);
                continue;
            }

            $this->logger->debug('Submitting request to AutoDJ.', [
                'track' => $track,
                'queue' => $queueName->value,
            ]);
            $response = $backend->enqueue($station, $queueName, $track);
            $this->logger->debug('AutoDJ request response', ['response' => $response]);

            if (LiquidsoapQueues::TopOfHour === $queueName) {
                $this->recordTopOfHourPlaybackDirectly($station, $sq);
            }
        }
    }

    /**
     * Records a dedicated-queue legal ID immediately. This remains a fallback
     * for Liquidsoap configurations where metadata feedback through the custom
     * top-of-hour transition is delayed or absent.
     */
    private function recordTopOfHourPlaybackDirectly(Station $station, StationQueue $sq): void
    {
        $media = $sq->media;
        if ($media !== null) {
            try {
                $_ = $media->id;
            } catch (\Throwable) {
                $media = null;
            }
        }

        if (!$media instanceof StationMedia) {
            $this->logger->warning(
                'Top-of-hour ID pushed with no associated media; cannot record history directly.'
            );
            return;
        }

        try {
            $historyRow = SongHistory::fromQueue($sq);
            $this->historyRepo->changeCurrentSong($station, $historyRow);
            $this->queueRepo->trackPlayed($station, $sq);
            $this->legalIdPlaybackService->recordPlaybackIfLegalId($station, $sq, $media);

            $this->logger->info(
                'Top-of-hour ID recorded directly to song history.',
                ['media' => $media->title]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to directly record top-of-hour ID playback.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Last-resort real-time backstop for scheduled boundaries other than the
     * top-of-hour legal-ID boundary.
     */
    private function enforceScheduledBoundary(Station $station, Liquidsoap $backend): void
    {
        $now = Time::nowUtc();

        try {
            $secondsToScheduled = $this->scheduler->secondsUntilNextScheduledStart($station, $now);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scheduled boundary enforcement: lookup failed, skipping this check for this tick.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (null === $secondsToScheduled || $secondsToScheduled > 90) {
            return;
        }

        if ($this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            $secondsUntilTop = $this->hourBoundaryPlanner->secondsUntilNextTopOfHour(
                $now->toDateTimeImmutable(),
                $station->getTimezoneObject(),
            );

            if (abs($secondsUntilTop - $secondsToScheduled) <= 3) {
                return;
            }
        }

        $currentSong = $station->current_song;
        if (null === $currentSong || null === $currentSong->timestamp_start) {
            return;
        }

        $currentSongDuration = $currentSong->duration ?? 0.0;
        $currentSongEndsAt = $currentSong->timestamp_start
            ->modify('+' . (int)round($currentSongDuration) . ' seconds')
            ->getTimestamp();
        $scheduledBoundaryAt = $now->getTimestamp() + $secondsToScheduled;

        if ($currentSongEndsAt <= $scheduledBoundaryAt + 2) {
            return;
        }

        $this->logger->warning(
            'Scheduled boundary enforcement: current track would run past a scheduled start; skipping now.',
            [
                'current_song' => $currentSong->title,
                'seconds_to_scheduled' => $secondsToScheduled,
                'current_song_would_end_at' => $currentSongEndsAt,
                'scheduled_boundary_at' => $scheduledBoundaryAt,
            ]
        );

        $backend->skip($station);
    }
}
