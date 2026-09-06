<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
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

        // Real-time last-resort backstop for rigid scheduled starts. Normal
        // queue planning, broadcast-clock timing, and stretch/squeeze handle
        // the common case. This check only protects the operator's explicit
        // wall-clock schedule when real playout would otherwise overrun it.
        //
        // Top-of-Hour Station IDs do not own a separate interrupt queue and do
        // not disable this rule. In HARD TOH the ID is planned to end exactly
        // at :00, so the guard naturally does nothing when the clock plan is on
        // time. If playout ever drifts, the rigid :00 event still remains the
        // final authority.
        $this->enforceScheduledBoundary($station, $backend);

        // Automatic Top-of-Hour IDs are deliberately excluded here. The rebuilt
        // feature creates them only through TopOfHourQueueSubscriber and the
        // normal AutoDJ queue, so this task cannot race it with a second source.
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

        // Do not stack interrupting audio. Top-of-Hour IDs never use this queue.
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
     * Last-resort real-wall-clock backstop for rigid scheduled starts.
     *
     * Only acts inside a short pre-boundary window, and only when the current
     * on-air item would genuinely still be running after the scheduled start.
     * This does not replace normal broadcast-clock queue planning.
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

        // Wide enough to guarantee at least one once-a-minute task tick lands
        // inside the protection window, but narrow enough to avoid early cuts.
        if ($secondsToScheduled > 90) {
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

        // Small grace margin: if the item is already ending essentially on the
        // boundary, let the normal transition complete instead of forcing one.
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
