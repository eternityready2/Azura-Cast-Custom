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
use Carbon\CarbonImmutable;
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

        $this->expireMissedTopOfHourRows($station);
        $this->observeScheduledBoundary($station);

        $now = Time::nowUtc()->toDateTimeImmutable();
        $hasTopOfHour = $this->hasTopOfHourToDeliver($station, $now);
        $hasInterruptingPlaylist = $hasTopOfHour;
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

    private function hasTopOfHourToDeliver(Station $station, \DateTimeImmutable $now): bool
    {
        if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        if (!$this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $now)) {
            return false;
        }

        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $now);
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );

        if ($this->queueRepo->findUnplayedTopOfHourLegalIdBetween(
            $station,
            $windowStart,
            $targetTop,
        ) instanceof StationQueue) {
            return true;
        }

        return $this->hourBoundaryPlanner->isTopOfHourInterruptDue($station, $now);
    }

    private function expireMissedTopOfHourRows(Station $station): void
    {
        $hourStart = CarbonImmutable::now($station->getTimezoneObject())
            ->startOfHour()
            ->toDateTimeImmutable();

        $expired = $this->queueRepo->deleteUnplayedTopOfHourLegalIdsBefore($station, $hourStart);
        if ($expired > 0) {
            $this->logger->warning(
                'Removed missed top-of-hour planning rows after their boundary passed.',
                ['count' => $expired]
            );
        }
    }

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
     * Observe approaching scheduled boundaries without ever hard-skipping the
     * current item. Boundary fitting is handled before playout by Queue and
     * HourBoundaryAnnotator using selection, bounded stretch and a fade/cue-out.
     */
    private function observeScheduledBoundary(Station $station): void
    {
        $now = Time::nowUtc();

        try {
            $secondsToScheduled = $this->scheduler->secondsUntilNextScheduledStart($station, $now);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scheduled boundary observation: lookup failed.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (null === $secondsToScheduled || $secondsToScheduled > 90) {
            return;
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

        if ($currentSongEndsAt <= $scheduledBoundaryAt + 60) {
            return;
        }

        $this->logger->warning(
            'Scheduled boundary at risk: current item projects beyond the 60-second grace window; no hard skip will be issued.',
            [
                'current_song' => $currentSong->title,
                'seconds_to_scheduled' => $secondsToScheduled,
                'current_song_would_end_at' => $currentSongEndsAt,
                'scheduled_boundary_at' => $scheduledBoundaryAt,
                'maximum_grace_seconds' => 60,
            ]
        );
    }
}
