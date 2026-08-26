<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\StationStreamer;
use App\Utilities\DateRange;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\Collection;
use Monolog\LogRecord;

final class Scheduler
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public const int STRICT_START_GRACE_SECONDS = 120;

    public function __construct(
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    /**
     * Seconds until the next playlist is scheduled to start, station-wide,
     * within the next hour. Used by soft-strict boundary protection.
     * Checks both today's and tomorrow's occurrence of each schedule item, so
     * a start time just after midnight is not missed late at night. Returns
     * null if nothing starts within the next hour.
     */
    public function secondsUntilNextScheduledStart(
        \App\Entity\Station $station,
        DateTimeImmutable $now,
    ): ?int {
        $tz = $station->getTimezoneObject();
        $nowLocal = CarbonImmutable::instance($now)->setTimezone($tz);

        $best = null;

        $allScheduleItems = [];
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }
            foreach ($playlist->schedule_items as $scheduleItem) {
                $allScheduleItems[] = $scheduleItem;
            }
        }

        // Include clock wheel schedules -- they are distinct from playlists
        // but equally represent hard programme boundaries that music must not
        // run past.
        foreach ($station->clock_wheels as $clockWheel) {
            foreach ($clockWheel->schedule_items as $scheduleItem) {
                $allScheduleItems[] = $scheduleItem;
            }
        }

        foreach ($allScheduleItems as $scheduleItem) {
            $days = $scheduleItem->days;

            // Check both today's and tomorrow's occurrence so a start time
            // just after midnight isn't missed when checking late at night.
            foreach ([$nowLocal, $nowLocal->addDay()] as $candidateDay) {
                $isoDay = (int)$candidateDay->dayOfWeekIso;
                if ([] !== $days && !in_array($isoDay, $days, true)) {
                    continue;
                }

                $startTime = StationSchedule::getDateTime($scheduleItem->start_time, $tz, $candidateDay);
                $deltaSeconds = $startTime->getTimestamp() - $nowLocal->getTimestamp();

                if ($deltaSeconds <= 0 || $deltaSeconds > HourBoundaryPlanner::HOUR_SECONDS) {
                    continue;
                }

                if (null === $best || $deltaSeconds < $best) {
                    $best = $deltaSeconds;
                }
            }
        }

        return $best;
    }

    public function shouldPlaylistPlayNow(
        StationPlaylist $playlist,
        ?DateTimeImmutable $now = null
    ): bool {
        $this->logger->pushProcessor(
            function (LogRecord $record) use ($playlist) {
                $record->extra['playlist'] = [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                ];
                return $record;
            }
        );

        $now ??= Time::nowUtc();

        if (!$this->isPlaylistScheduledToPlayNow($playlist, $now)) {
            $this->logger->debug('Playlist is not scheduled to play now.');
            $this->logger->popProcessor();
            return false;
        }

        $shouldPlay = true;

        switch ($playlist->type) {
            case PlaylistTypes::OncePerHour:
                $shouldPlay = $this->shouldPlaylistPlayNowPerHour($playlist, $now);

                $this->logger->debug(
                    sprintf(
                        'Once-per-hour playlist %s been played yet this hour.',
                        $shouldPlay ? 'HAS NOT' : 'HAS'
                    )
                );
                break;

            case PlaylistTypes::OncePerXSongs:
                $playPerSongs = $playlist->play_per_songs;
                $shouldPlay = !$this->queueRepo->isPlaylistRecentlyPlayed($playlist, $playPerSongs);

                $this->logger->debug(
                    sprintf(
                        'Once-per-X-songs playlist %s been played within the last %d song(s).',
                        $shouldPlay ? 'HAS NOT' : 'HAS',
                        $playPerSongs
                    )
                );
                break;

            case PlaylistTypes::OncePerXMinutes:
                $playPerMinutes = $playlist->play_per_minutes;
                $shouldPlay = !$this->wasPlaylistPlayedInLastXMinutes($playlist, $now, $playPerMinutes);

                $this->logger->debug(
                    sprintf(
                        'Once-per-X-minutes playlist %s been played within the last %d minute(s).',
                        $shouldPlay ? 'HAS NOT' : 'HAS',
                        $playPerMinutes
                    )
                );
                break;

            case PlaylistTypes::Advanced:
                $this->logger->debug('Playlist is "Advanced" type and is not managed by the AutoDJ.');
                $shouldPlay = false;
                break;

            case PlaylistTypes::Standard:
                break;
        }

        $this->logger->popProcessor();
        return $shouldPlay;
    }

    public function isPlaylistScheduledToPlayNow(
        StationPlaylist $playlist,
        DateTimeImmutable $now,
        bool $excludeSpecialRules = false
    ): bool {
        $scheduleItems = $playlist->schedule_items;

        if (0 === $scheduleItems->count()) {
            $this->logger->debug('Playlist has no schedule items; skipping schedule time check.');
            return true;
        }

        $stationTz = $playlist->station->getTimezoneObject();

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $scheduleItems,
            $stationTz,
            $now,
            $excludeSpecialRules
        );

        return null !== $scheduleItem;
    }

    private function shouldPlaylistPlayNowPerHour(
        StationPlaylist $playlist,
        DateTimeImmutable $now
    ): bool {
        if ($this->hourBoundaryPlanner->shouldSuppressOncePerHourPlaylist($playlist)) {
            $this->logger->debug(
                'Once-per-hour playlist at :00 suppressed; station top-of-hour protection handles legal ID.'
            );

            return false;
        }

        $stationNow = CarbonImmutable::instance($now)
            ->setTimezone($playlist->station->getTimezoneObject());
        $targetTime = $stationNow
            ->startOfHour()
            ->addMinutes($playlist->play_per_hour_minute);

        $playedAt = $playlist->played_at;
        if (null === $playedAt) {
            // A newly-created playlist must wait for its first target minute,
            // but remains due if the queue is not rebuilt exactly at that minute.
            return !$targetTime->isAfter($stationNow);
        }

        if ($targetTime->isAfter($stationNow)) {
            $targetTime = $targetTime->subHour();
        }

        // played_at records when this playlist was last put in the queue. The
        // latest hourly occurrence remains due until it has actually been queued.
        return CarbonImmutable::instance($playedAt)->isBefore($targetTime);
    }

    private function wasPlaylistPlayedInLastXMinutes(
        StationPlaylist $playlist,
        DateTimeImmutable $now,
        int $minutes
    ): bool {
        $playedAt = $playlist->played_at;
        if (null === $playedAt) {
            return false;
        }

        return CarbonImmutable::instance($now)
            ->subMinutes($minutes)
            ->isBefore($playedAt);
    }

    /**
     * Get the duration of scheduled play time in seconds (used for remote URLs of indeterminate length).
     *
     * @param StationPlaylist $playlist
     */
    public function getPlaylistScheduleDuration(StationPlaylist $playlist): int
    {
        $stationTz = $playlist->station->getTimezoneObject();
        $now = CarbonImmutable::now($stationTz);

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $playlist->schedule_items,
            $stationTz,
            $now
        );

        return $scheduleItem instanceof StationSchedule
            ? $scheduleItem->getDuration($stationTz)
            : 0;
    }

    public function canStreamerStreamNow(
        StationStreamer $streamer,
        ?DateTimeImmutable $now = null
    ): bool {
        if (!$streamer->enforce_schedule) {
            return true;
        }

        $stationTz = $streamer->station->getTimezoneObject();

        $scheduleItem = $this->getActiveScheduleFromCollection(
            $streamer->schedule_items,
            $stationTz,
            $now
        );

        return null !== $scheduleItem;
    }

    /**
     * @param Collection<int, StationSchedule> $scheduleItems
     * @param DateTimeZone $tz
     * @param DateTimeImmutable|null $now
     * @param bool $excludeSpecialRules
     * @return StationSchedule|null
     */
    private function getActiveScheduleFromCollection(
        Collection $scheduleItems,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null,
        bool $excludeSpecialRules = false
    ): ?StationSchedule {
        $now = Time::nowInTimezone($tz, $now);

        if ($scheduleItems->count() > 0) {
            foreach ($scheduleItems as $scheduleItem) {
                $scheduleName = (string)$scheduleItem;

                if ($this->shouldSchedulePlayNow($scheduleItem, $tz, $now, $excludeSpecialRules)) {
                    $this->logger->debug(
                        sprintf(
                            '%s - Should Play Now',
                            $scheduleName
                        )
                    );
                    return $scheduleItem;
                }

                $this->logger->debug(
                    sprintf(
                        '%s - Not Eligible to Play Now',
                        $scheduleName
                    )
                );
            }
        }
        return null;
    }

    public function shouldSchedulePlayNow(
        StationSchedule $schedule,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null,
        bool $excludeSpecialRules = false
    ): bool {
        $now = Time::nowInTimezone($tz, $now);

        $startTime = StationSchedule::getDateTime($schedule->start_time, $tz, $now);
        $endTime = StationSchedule::getDateTime($schedule->end_time, $tz, $now);

        $this->logger->debug('Checking to see whether schedule should play now.', [
            'startTime' => $startTime,
            'endTime' => $endTime,
        ]);

        if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $now)) {
            $this->logger->debug('Schedule is not scheduled to play today.');
            return false;
        }

        /** @var DateRange[] $comparePeriods */
        $comparePeriods = [];

        if ($startTime->equalTo($endTime)) {
            // Create intervals for "play once" type dates.
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime->addMinutes(15)
            );
            $comparePeriods[] = new DateRange(
                $startTime->subDay(),
                $endTime->subDay()->addMinutes(15)
            );
            $comparePeriods[] = new DateRange(
                $startTime->addDay(),
                $endTime->addDay()->addMinutes(15)
            );
        } elseif ($startTime->greaterThan($endTime)) {
            // Create intervals for overnight playlists (one from yesterday to today, one from today to tomorrow).
            $comparePeriods[] = new DateRange(
                $startTime->subDay(),
                $endTime
            );
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime->addDay()
            );
        } else {
            $comparePeriods[] = new DateRange(
                $startTime,
                $endTime
            );
        }

        return array_any(
            $comparePeriods,
            fn($dateRange) => $this->shouldPlayInSchedulePeriod($schedule, $dateRange, $now, $excludeSpecialRules)
        );
    }

    private function shouldPlayInSchedulePeriod(
        StationSchedule $schedule,
        DateRange $dateRange,
        DateTimeImmutable $now,
        bool $excludeSpecialRules = false
    ): bool {
        if (!$dateRange->contains($now)) {
            return false;
        }

        // Check day-of-week limitations.
        $dayToCheck = $dateRange->start->dayOfWeekIso;
        if (!$this->isScheduleScheduledToPlayToday($schedule, $dayToCheck)) {
            return false;
        }

        // Check playlist special handling rules.
        $playlist = $schedule->playlist;
        if (null === $playlist) {
            return true;
        }

        // Skip the remaining checks if we're doing a "still scheduled to play" Queue check.
        if ($excludeSpecialRules) {
            return true;
        }

        // Handle "Play Single Track" advanced setting.
        if ($playlist->backendPlaySingleTrack()) {
            $playedAt = $playlist->played_at;

            if (null !== $playedAt && $dateRange->start->isBefore($playedAt)) {
                return false;
            }
        }

        // Handle "Loop Once" schedule specification.
        if (
            $schedule->loop_once
            && !$this->shouldPlaylistLoopNow($schedule, $dateRange)
        ) {
            return false;
        }

        return true;
    }

    private function shouldPlaylistLoopNow(
        StationSchedule $schedule,
        DateRange $dateRange
    ): bool {
        $this->logger->debug('Checking if playlist should loop now.');

        $playlist = $schedule->playlist;

        if (null === $playlist) {
            $this->logger->error('Attempting to check playlist loop status on a non-playlist-based schedule item.');
            return false;
        }

        $playlistPlayedAt = $playlist->played_at;

        $isQueueEmpty = $this->spmRepo->isQueueEmpty($playlist);
        $hasCuedPlaylistMedia = $this->queueRepo->hasCuedPlaylistMedia($playlist);

        if (!$dateRange->contains($playlistPlayedAt)) {
            $this->logger->debug('Playlist was not played yet.');

            $isQueueFilled = $this->spmRepo->isQueueCompletelyFilled($playlist);

            if ((!$isQueueFilled || $isQueueEmpty) && !$hasCuedPlaylistMedia) {
                $now = $dateRange->start->subSecond();

                $this->logger->debug('Resetting playlist queue with now override', [$now]);

                $this->spmRepo->resetQueue($playlist, $now);
                $isQueueEmpty = false;
            }
        } elseif ($isQueueEmpty && !$hasCuedPlaylistMedia) {
            $this->logger->debug('Resetting playlist queue.');

            $this->spmRepo->resetQueue($playlist);
            $isQueueEmpty = false;
        }

        $playlist = $this->em->refetch($playlist);

        $playlistQueueResetAt = $playlist->queue_reset_at;

        if (!$isQueueEmpty && !$dateRange->contains($playlistQueueResetAt)) {
            $this->logger->debug('Playlist should loop.');
            return true;
        }

        $this->logger->debug('Playlist should NOT loop.');
        return false;
    }

    /**
     * Determines if a schedule entity should play on the current date.
     *
     * Note: This function is timezone-sensitive and thus requires an explicit TZ be provided. This is
     * normally the station's timezone.
     *
     * @param StationSchedule $schedule
     * @param DateTimeZone $tz
     * @param DateTimeImmutable|null $now
     * @return bool
     */
    public function shouldSchedulePlayOnCurrentDate(
        StationSchedule $schedule,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));

        $startDate = $schedule->start_date;
        $endDate = $schedule->end_date;

        if (!empty($startDate)) {
            $startDate = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);

            if (null !== $startDate) {
                $startDate = StationSchedule::getDateTime(
                    $schedule->start_time,
                    $tz,
                    $startDate
                );

                if ($now->endOfDay()->lt($startDate)) {
                    return false;
                }
            }
        }

        if (!empty($endDate)) {
            $endDate = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $tz);

            if (null !== $endDate) {
                $isOvernightSchedule = $schedule->start_time > $schedule->end_time;

                // For overnight schedules where start_date == end_date,
                // the end_time actually occurs on the next day
                if ($isOvernightSchedule && $schedule->start_date === $schedule->end_date) {
                    $endDate = $endDate->addDay();
                }

                $endDate = StationSchedule::getDateTime(
                    $schedule->end_time,
                    $tz,
                    $endDate
                );

                if ($now->startOfDay()->gt($endDate)) {
                    return false;
                }
            }
        }

        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            return ScheduleRecurrence::isDateInSchedule($schedule, $tz, $now);
        }

        return true;
    }

    private static function hasRecurrence(StationSchedule $schedule): bool
    {
        return ScheduleRecurrence::hasRecurrence($schedule);
    }

    /**
     * Given an ISO-8601 date, return if the playlist can be played on that day.
     *
     * @param StationSchedule $schedule
     * @param int $dayToCheck ISO-8601 date (1 for Monday, 7 for Sunday)
     * @return bool
     */
    public function isScheduleScheduledToPlayToday(
        StationSchedule $schedule,
        int $dayToCheck
    ): bool {
        if (self::hasRecurrence($schedule)) {
            return true;
        }
        $playOnceDays = $schedule->days;
        return empty($playOnceDays)
            || in_array($dayToCheck, $playOnceDays, false);
    }

    /**
     * True while an unserved Strict schedule occurrence is inside its short
     * catch-up window. This lets ID/AI News finish first without allowing normal
     * music to make a scheduled programme several minutes late.
     */
    public function isPlaylistStrictStartDueNow(
        StationPlaylist $playlist,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));

        foreach ($playlist->schedule_items as $schedule) {
            if (!$schedule->strict_start) {
                continue;
            }

            foreach ([$now, $now->subDay()] as $candidateDay) {
                $occurrenceStart = CarbonImmutable::instance(
                    StationSchedule::getDateTime($schedule->start_time, $tz, $candidateDay)
                );
                $secondsLate = $now->getTimestamp() - $occurrenceStart->getTimestamp();

                if ($secondsLate < 0 || $secondsLate > self::STRICT_START_GRACE_SECONDS) {
                    continue;
                }

                if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $occurrenceStart)) {
                    continue;
                }

                if (!$this->isScheduleScheduledToPlayToday($schedule, $occurrenceStart->dayOfWeekIso)) {
                    continue;
                }

                if ($this->queueRepo->hasPlayedPlaylistSince(
                    $playlist,
                    $occurrenceStart->toDateTimeImmutable(),
                )) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }
}
