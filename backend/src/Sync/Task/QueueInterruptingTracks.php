<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Station;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\Queue;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\AutoDJ\SponsorGuaranteedPlayoutService;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
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
        private readonly TopOfHourClock $topOfHourClock,
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

        $backend = $this->adapters->getBackendAdapter($station);
        if (!($backend instanceof Liquidsoap)) {
            return;
        }

        // Last-resort protection for ordinary rigid schedule boundaries. During
        // minute :59, however, an enabled automatic TOH ID owns the pre-boundary
        // transition and performs its own slow fade. Do not issue an abrupt
        // radio.skip() underneath that fade; the rigid :00 switch still keeps
        // absolute authority when the boundary actually arrives.
        $this->enforceScheduledBoundary($station, $backend);

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

        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Interrupting)) {
            $this->logger->info('Interrupting queue: Queue is not empty.');
            return;
        }

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

        // When the next rigid start is exactly the top of the hour and TOH is
        // enabled, minute :59 belongs to the ID runtime. It has already planned
        // a smooth pre-fade and will release the underlying source exactly at
        // :00. An early backend skip here would destroy that smooth transition.
        if ($this->topOfHourClock->isEnabled($station) && $secondsToScheduled > 0) {
            $tz = $station->getTimezoneObject();
            $localNow = $now->setTimezone($tz);
            $scheduledLocal = $now
                ->modify('+' . $secondsToScheduled . ' seconds')
                ->setTimezone($tz);

            if (
                '59' === $localNow->format('i')
                && '00:00' === $scheduledLocal->format('i:s')
            ) {
                $this->logger->debug(
                    'Scheduled boundary enforcement delegated to TOH runtime for the :59 pre-fade.',
                    ['seconds_to_scheduled' => $secondsToScheduled]
                );
                return;
            }
        }

        $currentSong = $station->current_song;
        if (null === $currentSong) {
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
