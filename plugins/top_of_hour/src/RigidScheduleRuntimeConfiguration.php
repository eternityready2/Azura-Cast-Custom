<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use App\Radio\Backend\Liquidsoap\PlaylistFileWriter;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives rigid scheduled playlists real wall-clock authority over the final
 * station source graph.
 *
 * The plugin writes this wrapper immediately below the TOH ID wrapper. The
 * resulting authority order is:
 *
 *     Top-of-Hour ID -> rigid scheduled programme -> live/AutoDJ
 */
final class RigidScheduleRuntimeConfiguration implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WriteLiquidsoapConfiguration::class => ['writeRuntime', 16],
        ];
    }

    public function writeRuntime(WriteLiquidsoapConfiguration $event): void
    {
        $station = $event->getStation();
        $playlistVarNames = [];
        $rigidBranches = [];

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            // These sources are resolved through the PHP AutoDJ queue and do not
            // have a static media file that a native wall-clock source can read.
            if (in_array($playlist->source, [PlaylistSources::Playlists, PlaylistSources::Requests], true)) {
                continue;
            }

            // Members of a Playlist Group are owned by the group runtime, not by
            // a standalone Liquidsoap source.
            if (count($playlist->group_memberships) > 0) {
                continue;
            }

            $rigidSchedules = [];
            foreach ($playlist->schedule_items as $scheduleItem) {
                if ($this->isRigidSchedule($playlist, $scheduleItem)) {
                    $rigidSchedules[] = $scheduleItem;
                }
            }

            $usesConfiguredNativeSource = ConfigWriter::shouldWritePlaylist($event, $playlist);

            if ($usesConfiguredNativeSource) {
                // Mirror ConfigWriter's native-variable collision handling across
                // every playlist it writes, not only the rigid ones.
                $playlistVarName = ConfigWriter::getPlaylistVariableName($playlist);
                if (in_array($playlistVarName, $playlistVarNames, true)) {
                    $playlistVarName .= '_' . $playlist->id;
                }
                $playlistVarNames[] = $playlistVarName;
            } elseif ([] !== $rigidSchedules && PlaylistSources::Songs === $playlist->source) {
                // A strict-start Songs playlist may be AutoDJ-only. The outer
                // rigid lane still needs a native source to own the wall clock,
                // so create one from the playlist file maintained for Songs.
                $playlistId = isset($playlist->id) ? $playlist->id : spl_object_id($playlist);
                $playlistVarName = 'rigid_' . ConfigWriter::getPlaylistVariableName($playlist) . '_' . $playlistId;
                $this->writeDedicatedSongSource($event, $playlist, $playlistVarName);
            } else {
                continue;
            }

            if ([] === $rigidSchedules) {
                continue;
            }

            foreach ($rigidSchedules as $scheduleItem) {
                $playTime = $this->getScheduledPlaylistPlayTime($event, $scheduleItem);
                $rigidBranches[] = $playlist->backendPlaySingleTrack()
                    ? '(predicate.at_most(1, {' . $playTime . '}), ' . $playlistVarName . ')'
                    : '({ ' . $playTime . ' }, ' . $playlistVarName . ')';
            }
        }

        // Shared broadcast-clock transport gate. The plugin-owned retirement
        // transport permanently destroys the active request and any prefetched
        // tail. fallback.skip also retires already-processed frames above
        // request.dynamic. A live DJ is never gated.
        $event->appendBlock(
            <<<'LIQ'
            # Broadcast-clock AutoDJ transport gate (Top-of-Hour plugin).
            broadcast_clock_autodj_blocked = ref(false)
            broadcast_clock_rejoin_waiting = ref(false)
            rigid_schedule_active = ref(false)
            radio_before_broadcast_clock_gate = radio

            # Capture identity from the fully processed source before making that
            # graph unavailable. request.dynamic may already have advanced into a
            # prefetched successor while crossfade still contains the song that is
            # actually being faded off air. The audible identity therefore wins.
            def broadcast_clock_capture_retirement_song() =
                audible_metadata = radio_before_broadcast_clock_gate.last_metadata()
                if null.defined(audible_metadata) then
                    audible_song_id = list.assoc(
                        default="",
                        "song_id",
                        null.get(audible_metadata)
                    )
                    azuracast.set_autodj_retirement_song_hint(audible_song_id)
                else
                    azuracast.set_autodj_retirement_song_hint("")
                end
            end

            def broadcast_clock_block_autodj() =
                if not azuracast.live_enabled() then
                    broadcast_clock_capture_retirement_song()
                    broadcast_clock_autodj_blocked := true
                    azuracast.discard_autodj_current()
                    log("Broadcast Clock: blocked AutoDJ and retired its audible/active/prefetched requests.")
                end
            end

            def broadcast_clock_release_autodj() =
                broadcast_clock_autodj_blocked := false
                broadcast_clock_rejoin_waiting := false
                log("Broadcast Clock: released AutoDJ transport gate.")
            end

            def broadcast_clock_prefetch_autodj() =
                if not azuracast.live_enabled() and not azuracast.autodj_fresh_ready() then
                    azuracast.prefetch_autodj_next()
                    log("Broadcast Clock: requested a fresh AutoDJ rejoin track while gate remained closed.")
                end
            end

            def broadcast_clock_rejoin_tick() =
                if azuracast.live_enabled() then
                    broadcast_clock_release_autodj()
                    -1.0
                elsif azuracast.autodj_fresh_ready() then
                    broadcast_clock_release_autodj()
                    log("Broadcast Clock: fresh AutoDJ request is ready; rejoin released.")
                    -1.0
                else
                    # There is deliberately no time-based fail-open here. If the
                    # backend cannot produce a non-retired request, silence/hold
                    # is safer than violating the station's no-resume invariant.
                    0.1
                end
            end

            def broadcast_clock_release_when_fresh() =
                if azuracast.live_enabled() then
                    broadcast_clock_release_autodj()
                elsif azuracast.autodj_fresh_ready() then
                    broadcast_clock_release_autodj()
                    log("Broadcast Clock: fresh AutoDJ request already ready at rejoin.")
                elsif not broadcast_clock_rejoin_waiting() then
                    broadcast_clock_rejoin_waiting := true
                    broadcast_clock_prefetch_autodj()
                    thread.run.recurrent(delay=0.1, broadcast_clock_rejoin_tick)
                    log("Broadcast Clock: holding rejoin until a fresh AutoDJ request is ready; no fail-open.")
                end
            end

            def broadcast_clock_base_available() =
                azuracast.live_enabled() or not broadcast_clock_autodj_blocked()
            end

            # source.available requires a level predicate: true for the whole time
            # the underlying station graph should remain available.
            broadcast_clock_base = source.available(
                radio_before_broadcast_clock_gate,
                {broadcast_clock_base_available()}
            )

            broadcast_clock_hold = blank(id="broadcast_clock_hold")

            # Unlike a plain fallback, fallback.skip explicitly skips the main
            # source's current track before switching away. The main source here
            # is the fully processed station graph (after stretch/crossfade), so
            # this flushes stale frames that a leaf request.dynamic skip cannot.
            radio = fallback.skip(
                broadcast_clock_base,
                fallback=broadcast_clock_hold
            )
            source.set_id(radio, "broadcast_clock_autodj_gate")
            LIQ
        );

        if ([] === $rigidBranches) {
            return;
        }

        $branches = implode(",\n                    ", $rigidBranches);
        $transitions = implode(
            ', ',
            [...array_fill(0, count($rigidBranches), 'rigid_schedule_enter'), 'rigid_schedule_exit'],
        );

        $event->appendBlock(
            <<<LIQ
            # Rigid scheduled-programme wall-clock lane (Top-of-Hour plugin).
            radio_before_rigid_schedule = radio

            def rigid_schedule_enter(_, new) =
                rigid_schedule_active := true

                # Destroy the exact request.dynamic AutoDJ request and hold the
                # ordinary graph unavailable for the entire rigid programme.
                # A live DJ stays connected and is never discarded or gated.
                broadcast_clock_block_autodj()

                # The PHP strict-start path may have staged a duplicate copy in
                # the interrupting queue. This native rigid lane is authoritative.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])

                log("Rigid Schedule: scheduled programme took wall-clock authority.")
                new
            end

            def rigid_schedule_exit(_, new) =
                # Anything staged into the legacy interrupting lane while the
                # rigid source was on air is stale and must not follow it.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])
                rigid_schedule_active := false

                # Prepare and verify a fresh AutoDJ request before reopening the
                # processed graph. This prevents a stale buffered programme/music
                # frame from being used as a bridge out of the rigid lane.
                broadcast_clock_release_when_fresh()
                log("Rigid Schedule: scheduled programme released wall-clock authority.")
                new
            end

            radio = switch(
                id="rigid_schedule_runtime",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[{$transitions}],
                [
                    {$branches},
                    ({true}, radio_before_rigid_schedule)
                ]
            )
            LIQ
        );
    }

    private function writeDedicatedSongSource(
        WriteLiquidsoapConfiguration $event,
        StationPlaylist $playlist,
        string $playlistVarName,
    ): void {
        $playlistMode = match ($playlist->order) {
            PlaylistOrders::Sequential => 'normal',
            PlaylistOrders::Shuffle, PlaylistOrders::SmartShuffle => 'randomize',
            PlaylistOrders::Random => 'random',
        };

        $playlistParams = [
            'id=' . ConfigWriter::toRawString($playlistVarName),
            'mime_type="audio/x-mpegurl"',
            'mode="' . $playlistMode . '"',
            'reload_mode="watch"',
            ConfigWriter::toRawString(PlaylistFileWriter::getPlaylistFilePath($playlist)),
        ];

        $event->appendLines([
            '# Dedicated native source for an AutoDJ-only rigid scheduled programme.',
            $playlistVarName . ' = playlist(' . implode(',', $playlistParams) . ')',
        ]);

        if ($playlist->is_jingle) {
            $event->appendLines([
                $playlistVarName . ' = azuracast.utilities.drop_metadata(' . $playlistVarName . ')',
            ]);
        }
    }

    private function isRigidSchedule(
        StationPlaylist $playlist,
        StationSchedule $schedule,
    ): bool {
        return $schedule->strict_start
            || $schedule->is_emergency
            || $playlist->backendInterruptOtherSongs();
    }

    /**
     * Mirrors ConfigWriter's schedule predicate generation so the outer rigid
     * runtime uses the exact same station-local schedule windows.
     */
    private function getScheduledPlaylistPlayTime(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $playlistSchedule,
    ): string {
        $tzObject = $event->getStation()->getTimezoneObject();

        if (ScheduleRecurrence::hasRecurrence($playlistSchedule)) {
            $now = CarbonImmutable::now($tzObject);
            $rangeEnd = $now->addDays(400);
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $playlistSchedule,
                $tzObject,
                $now->subDay(),
                $rangeEnd,
                500,
            );
            if ([] === $occurrences) {
                return 'false';
            }

            $scheduleMethod = 'rigid_schedule_' . $playlistSchedule->id . '_recurrence';
            $parts = [];
            foreach ($occurrences as $dateRange) {
                $startTs = $dateRange->start->getTimestamp();
                $endTs = $dateRange->end->getTimestamp();
                $parts[] = "(time() >= $startTs. and time() <= $endTs.)";
            }

            $event->appendLines([
                'def ' . $scheduleMethod . '() =',
                '  (' . implode(' or ', $parts) . ')',
                'end',
            ]);

            return $scheduleMethod . '()';
        }

        $startTime = $playlistSchedule->start_time;
        $endTime = $playlistSchedule->end_time;

        if ($startTime > $endTime) {
            $playTimes = [
                ConfigWriter::formatTimeCode($startTime) . '-23h59m59s',
                '00h00m-' . ConfigWriter::formatTimeCode($endTime),
            ];

            $playlistScheduleDays = $playlistSchedule->days;
            if ([] !== $playlistScheduleDays && count($playlistScheduleDays) < 7) {
                $currentPlayDays = [];
                $nextPlayDays = [];

                foreach ($playlistScheduleDays as $day) {
                    $currentPlayDays[] = (($day === 7) ? '0' : $day) . 'w';

                    $day++;
                    if ($day > 7) {
                        $day = 1;
                    }
                    $nextPlayDays[] = (($day === 7) ? '0' : $day) . 'w';
                }

                $playTimes[0] = '(' . implode(' or ', $currentPlayDays) . ') and ' . $playTimes[0];
                $playTimes[1] = '(' . implode(' or ', $nextPlayDays) . ') and ' . $playTimes[1];
            }

            $playTime = '(' . implode(') or (', $playTimes) . ')';
            return $this->applyScheduleDateRangeBounds($event, $playlistSchedule, $playTime);
        }

        $playTime = ($startTime === $endTime)
            ? ConfigWriter::formatTimeCode($startTime)
            : ConfigWriter::formatTimeCode($startTime) . '-' . ConfigWriter::formatTimeCode($endTime);

        $playlistScheduleDays = $playlistSchedule->days;
        if ([] !== $playlistScheduleDays && count($playlistScheduleDays) < 7) {
            $playDays = [];
            foreach ($playlistScheduleDays as $day) {
                $playDays[] = (($day === 7) ? '0' : $day) . 'w';
            }
            $playTime = '(' . implode(' or ', $playDays) . ') and ' . $playTime;
        }

        return $this->applyScheduleDateRangeBounds($event, $playlistSchedule, $playTime);
    }

    private function applyScheduleDateRangeBounds(
        WriteLiquidsoapConfiguration $event,
        StationSchedule $playlistSchedule,
        string $playTime,
    ): string {
        $startDate = $playlistSchedule->start_date;
        $endDate = $playlistSchedule->end_date;

        if (empty($startDate) && empty($endDate)) {
            return $playTime;
        }

        $tzObject = $event->getStation()->getTimezoneObject();
        $scheduleMethod = 'rigid_schedule_' . $playlistSchedule->id . '_date_range';
        $body = ['def ' . $scheduleMethod . '() ='];
        $conditions = [];

        if (!empty($startDate)) {
            $startDateObj = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tzObject);
            if (null !== $startDateObj) {
                $startDateObj = $startDateObj->setTime(0, 0);
                $body[] = '    range_start = ' . $startDateObj->getTimestamp() . '.';
                $conditions[] = 'range_start <= current_time';
            }
        }

        if (!empty($endDate)) {
            $endDateObj = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tzObject);
            if (null !== $endDateObj) {
                $endDateObj = $endDateObj->setTime(23, 59, 59);
                $body[] = '    range_end = ' . $endDateObj->getTimestamp() . '.';
                $conditions[] = 'current_time <= range_end';
            }
        }

        if ([] === $conditions) {
            return $playTime;
        }

        $body[] = '    current_time = time()';
        $body[] = '    result = (' . implode(' and ', $conditions) . ')';
        $body[] = '    result';
        $body[] = 'end';
        $event->appendLines($body);

        return $scheduleMethod . '() and ' . $playTime;
    }
}
