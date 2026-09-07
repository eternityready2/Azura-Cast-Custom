<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap;

use App\Entity\Enums\PlaylistSources;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives rigid scheduled playlists real wall-clock authority over the final
 * station source graph.
 *
 * The normal AutoDJ source is intentionally very high-availability and can stay
 * ready with pre-fetched queue rows. A strict schedule switch that lives below
 * that source cannot guarantee its wall-clock start; AutoDJ can continue serving
 * stale/pre-fetched music even though the scheduled window has already begun.
 *
 * This wrapper is written after Harbor/live (priority 20) and immediately before
 * the Top-of-Hour ID wrapper (priority 15). The resulting authority order is:
 *
 *     Top-of-Hour ID -> rigid scheduled programme -> live/AutoDJ
 *
 * Thus HARD TOH can release exactly at :00 directly into the scheduled show.
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
            // have a native Liquidsoap playlist variable to switch to directly.
            if (in_array($playlist->source, [PlaylistSources::Playlists, PlaylistSources::Requests], true)) {
                continue;
            }

            if (!ConfigWriter::shouldWritePlaylist($event, $playlist)) {
                continue;
            }

            // Members of a Playlist Group are owned by the group runtime, not by
            // a standalone Liquidsoap source.
            if (count($playlist->group_memberships) > 0) {
                continue;
            }

            $playlistVarName = ConfigWriter::getPlaylistVariableName($playlist);
            if (in_array($playlistVarName, $playlistVarNames, true)) {
                $playlistVarName .= '_' . $playlist->id;
            }
            $playlistVarNames[] = $playlistVarName;

            if (0 === $playlist->schedule_items->count()) {
                continue;
            }

            foreach ($playlist->schedule_items as $scheduleItem) {
                if (!$this->isRigidSchedule($playlist, $scheduleItem)) {
                    continue;
                }

                $playTime = $this->getScheduledPlaylistPlayTime($event, $scheduleItem);
                $rigidBranches[] = $playlist->backendPlaySingleTrack()
                    ? '(predicate.at_most(1, {' . $playTime . '}), ' . $playlistVarName . ')'
                    : '({ ' . $playTime . ' }, ' . $playlistVarName . ')';
            }
        }

        if ([] === $rigidBranches) {
            return;
        }

        $branches = implode(",\n        ", $rigidBranches);

        $event->appendBlock(
            <<<LIQ
            # Rigid scheduled-programme wall-clock lane.
            # This source is fallible outside all rigid schedule windows.
            rigid_schedule_source = switch(
                id="rigid_schedule_sources",
                track_sensitive=false,
                replay_metadata=true,
                [
                    {$branches}
                ]
            )

            radio_before_rigid_schedule = radio
            rigid_schedule_active = ref(false)

            def rigid_schedule_enter(old, new) =
                rigid_schedule_active := true

                # If ordinary automated playout was interrupted, discard the
                # actual active leaf. Skipping only the wrapper leaves the same
                # request.dynamic song parked underneath and lets it resume later.
                # A live DJ is allowed to remain connected and resumes after the
                # rigid programme's window ends.
                if not azuracast.live_enabled() then
                    source.skip(source.effective(old))
                end

                # The old PHP strict-start path can have queued a duplicate copy
                # of this programme in the interrupting lane. The native rigid
                # source is authoritative now; purge that stale tail.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])

                log("Rigid Schedule: scheduled programme took wall-clock authority.")
                new
            end

            def rigid_schedule_exit(_, new) =
                # Anything the legacy interrupting task staged while this native
                # rigid source was on air is stale and must not play afterwards.
                interrupting_queue.skip()
                interrupting_queue.set_queue([])
                rigid_schedule_active := false
                log("Rigid Schedule: scheduled programme released wall-clock authority.")
                new
            end

            radio = switch(
                id="rigid_schedule_runtime",
                track_sensitive=false,
                replay_metadata=true,
                transition_length=0.0,
                transitions=[rigid_schedule_enter, rigid_schedule_exit],
                [
                    ({rigid_schedule_source.is_ready()}, rigid_schedule_source),
                    ({true}, radio_before_rigid_schedule)
                ]
            )
            LIQ
        );
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
     * runtime uses the exact same station-local schedule windows as the existing
     * playlist configuration.
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
