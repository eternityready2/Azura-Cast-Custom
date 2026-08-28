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

    /**
     * Manually process any requests for stations that use "Manual AutoDJ" mode.
     *
     * @param bool $force
     */
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

        // This feature only works on Liquidsoap.
        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        // Real-time last-resort backstop for scheduled playlist/clock-wheel/
        // smart-block starts (e.g. a talk show at 5:01pm, a midnight program
        // change). Runs BEFORE the interrupting-queue logic below and is
        // deliberately independent of it.
        //
        // Every other mechanism that tries to keep a track from running past a
        // scheduled boundary -- QueueBuilder's cap at build time,
        // HourBoundaryAnnotator's safety net at annotation time -- depends on
        // knowing the boundary is close *before* the track starts playing. Both
        // are computed against Liquidsoap's own internal prefetch timing for
        // when it requests the next track, which this codebase does not
        // control and cannot fully predict (it's compiled into the base
        // Liquidsoap image, not this application). If that prefetch happens
        // further ahead than expected, a track can be selected and annotated
        // while the boundary genuinely was far away, then actually air right
        // up against it with nothing left to re-check.
        //
        // This check is different in kind, not just another attempt at the
        // same idea: it runs once a minute against real wall-clock time and
        // the station's actual `current_song` (what is ACTUALLY playing right
        // now, not a projection), so it cannot be fooled by prefetch timing.
        // If the currently playing track would still be running after the
        // next scheduled start, it calls the station's existing skip()
        // mechanism (the same one behind the admin "Skip Song" button) to
        // retire it early. The whole AutoDJ chain is wrapped in
        // azuracast.apply_crossfade() after this point in config generation,
        // so the skip still gets the station's normal crossfade treatment
        // rather than being a bare cut.
        $this->enforceScheduledBoundary($station, $backend);

        // TOPH is an interrupting source even when the station has no interrupting playlists.
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

        // Check that the target queues are empty first.
        //
        // These are checked independently: the top-of-hour ID has its own queue
        // (see the routing note below), so a promo or liner still sitting in the
        // interrupting queue must not block the mandatory legal ID from being
        // pushed, and vice versa.
        $interruptingEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting);
        $topOfHourEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour);

        if (!$interruptingEmpty && !$topOfHourEmpty) {
            $this->logger->info('Interrupting queue: Queues are not empty!');
            return;
        }

        // Build a queue of interrupting songs to queue up.
        $songsToPlay = $this->queue->getInterruptingQueue($station);

        if (empty($songsToPlay)) {
            return;
        }

        foreach ($songsToPlay as $sq) {
            $event = AnnotateNextSong::fromStationQueue($sq, true);
            $this->eventDispatcher->dispatch($event);

            $track = $event->buildAnnotations();

            $queueName = ($sq->top_of_hour_legal_id ?? false)
                ? LiquidsoapQueues::TopOfHour
                : LiquidsoapQueues::Interrupting;

            $isEmpty = (LiquidsoapQueues::TopOfHour === $queueName)
                ? $topOfHourEmpty
                : $interruptingEmpty;

            if (!$isEmpty) {
                // Queue is not empty -- something is already playing or
                // queued for this output. For the legal ID specifically, that
                // almost certainly means the ID we pushed on a *previous* tick
                // is still playing. Record it in song history right now if it
                // hasn't been recorded yet (isDifferentFromCurrentSong will
                // return false once it's already the current song, so this is
                // idempotent and safe to call every tick while it plays).
                if (LiquidsoapQueues::TopOfHour === $queueName) {
                    $this->recordTopOfHourPlaybackDirectly($station, $sq);
                }

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
     * Records the legal ID's play directly, rather than waiting for
     * Liquidsoap's own metadata feedback loop (send_feedback -> the /feedback
     * API -> FeedbackCommand) to report it back.
     *
     * Overnight evidence (the station's own playback timeline) showed the ID
     * logging correctly and instantly whenever it landed through the normal
     * advance-queue path -- which plays through AzuraCast's stock crossfade
     * operator (`cross()`, purpose-built with guaranteed metadata handling).
     * But an ID pushed through this task's dedicated top_of_hour_requests
     * queue -- which uses a plain `fallback()` with a custom `transitions=`
     * callback, not `cross()` -- was audibly playing (confirmed: the outgoing
     * song faded correctly and did not resume) while being completely absent
     * from song history. `fallback()`'s transition callback mechanism is not
     * the same purpose-built operator AzuraCast's own crossfade relies on, and
     * isn't documented to guarantee the same metadata-forwarding behaviour
     * through a custom `add()`-mixed output.
     *
     * Rather than patch that Liquidsoap-side metadata forwarding blind --
     * this codebase can inspect the generated config but cannot run it, and
     * guessing at exact operator semantics is exactly the kind of unverified
     * change that has already caused real problems on this station -- this
     * records the play directly, in PHP, using the identical repository calls
     * FeedbackCommand itself would make. We already have complete certainty
     * about what was just pushed; there's no need to depend on Liquidsoap
     * correctly reporting it back for this one mandatory, compliance-relevant
     * action.
     *
     * Self-consistently duplicate-safe: if Liquidsoap's own feedback loop
     * *does* still successfully report this same play afterwards,
     * FeedbackCommand's own isDifferentFromCurrentSong() check will see that
     * current_song already matches (since this method just set it) and skip,
     * exactly as it already does for any other repeated feedback call.
     */
    private function recordTopOfHourPlaybackDirectly(Station $station, StationQueue $sq): void
    {
        // Force-initialize the media proxy. Doctrine lazy proxies pass
        // instanceof checks but return null/empty on property access until
        // initialized. Calling ->getId() or any scalar triggers initialization.
        $media = $sq->media;
        if ($media !== null) {
            try {
                // Accessing any property initializes the proxy if it isn't already.
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
            // Never let a history-recording failure break actual playback --
            // the ID has already been sent to Liquidsoap and will air
            // regardless of whether this bookkeeping succeeds.
            $this->logger->error(
                'Failed to directly record top-of-hour ID playback.',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Last-resort, real-wall-clock-time backstop -- see the call site in
     * queueForStation() for the full reasoning. Only acts inside a short
     * window right before a scheduled boundary, and only when the currently
     * playing track would genuinely still be running once that boundary
     * hits; this is not a substitute for the normal, smoother build-time and
     * annotation-time capping, which still handles the common case.
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

        if (null === $secondsToScheduled) {
            return;
        }

        // Only act inside a short pre-boundary window. Wide enough to
        // guarantee at least one once-a-minute tick lands inside it
        // regardless of exact cron alignment, narrow enough that this never
        // fires as an early/aggressive cutoff far ahead of the actual
        // boundary.
        if ($secondsToScheduled > 90) {
            return;
        }

        // Don't compete with the top-of-hour ID mechanism.
        //
        // secondsUntilNextScheduledStart() doesn't know about the once-per-hour
        // suppression rule (shouldSuppressOncePerHourPlaylist) -- it can report
        // an upcoming boundary that is actually the same :00 turnover TOPH
        // already owns. TOPH's own path uses a proper fade-under transition
        // (see ConfigWriter's top_of_hour_queue block); a bare skip() here at
        // the same moment would fight with that instead of deferring to it. If
        // this boundary is within a couple seconds of the top-of-hour mark,
        // leave it to TOPH entirely.
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

        // timestamp_start is a DateTimeImmutable, not a raw integer -- modify()
        // rather than arithmetic on the object itself.
        $currentSongEndsAt = $currentSong->timestamp_start
            ->modify('+' . (int)round($currentSongDuration) . ' seconds')
            ->getTimestamp();
        $scheduledBoundaryAt = $now->getTimestamp() + $secondsToScheduled;

        // Small grace margin: if the current track was already going to end
        // within a couple seconds of the boundary anyway, there's nothing to
        // fix here and skipping would be needless.
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
