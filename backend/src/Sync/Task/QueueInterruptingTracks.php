<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
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

        // Top-of-hour IDs are intentionally excluded here. They are queued only
        // by TopOfHourIdScheduler through the normal AutoDJ path.
        $hasInterruptingPlaylist = false;
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

        // Do not stack interrupting audio. Top-of-hour IDs never use this queue.
        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting)) {
            $this->logger->info('Interrupting queue: Queue is not empty.');
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

            $queueName = LiquidsoapQueues::Interrupting;

            $this->logger->debug('Submitting request to AutoDJ.', [
                'track' => $track,
                'queue' => $queueName->value,
            ]);
            $response = $backend->enqueue($station, $queueName, $track);
            $this->logger->debug('AutoDJ request response', ['response' => $response]);
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

        // Don't let generic scheduled-boundary enforcement skip a song on
        // behalf of a :00 playlist that the enabled station-wide TOH feature
        // suppresses. TOH itself is non-interrupting; this guard keeps the
        // generic skip backstop from reintroducing a cut at the same boundary.
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
