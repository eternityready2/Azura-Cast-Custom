<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Defines soft wall-clock anchors for ordinary AutoDJ playout.
 *
 * Unlike a hard interrupt, a soft anchor gives queue planning enough notice to
 * backtime the preceding audio. Stretch/squeeze can then close a small timing
 * gap and the normal cue-out/fade path is only used when a track cannot fit.
 */
final class BroadcastClockPlanner
{
    private const int LOOKAHEAD_SECONDS = 3600;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly TopOfHourClock $topOfHourClock,
    ) {
    }

    public function secondsUntilNextSoftAnchor(
        Station $station,
        DateTimeImmutable $now,
    ): ?int {
        $tz = $station->getTimezoneObject();
        $nowLocal = CarbonImmutable::instance($now)->setTimezone($tz);
        $best = null;

        // The rebuilt station ID is a first-class broadcast-clock anchor. HARD
        // and SOFT modes both anchor at :59:00; the mode only describes who owns
        // the following :00 boundary. This puts normal music, Smart Blocks and
        // the 24-hour Linear Log on the exact same timing calculation as the ID.
        $topOfHourDelta = $this->topOfHourClock->secondsUntilPlayoutAnchor($station, $now);
        if (null !== $topOfHourDelta) {
            $best = $topOfHourDelta;
        }

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled || !$this->isClockAnchoredPlaylist($playlist)) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                foreach ($this->getScheduleBoundaries($schedule, $nowLocal) as $boundary) {
                    $delta = $boundary->getTimestamp() - $nowLocal->getTimestamp();
                    if ($delta <= 0 || $delta > self::LOOKAHEAD_SECONDS) {
                        continue;
                    }

                    $best = null === $best ? $delta : min($best, $delta);
                }
            }
        }

        $newsDelta = $this->secondsUntilNextAiNewsAnchor($station, $nowLocal);
        if (null !== $newsDelta) {
            $best = null === $best ? $newsDelta : min($best, $newsDelta);
        }

        return $best;
    }

    /**
     * Maximum source duration that makes the next queue item's projected start
     * land on the next soft anchor.
     *
     * Queue::addDurationToTime() subtracts the configured crossfade overlap from
     * every normal source duration. Using raw wall-clock seconds here would make
     * the queue cursor land one crossfade early and could allow another general
     * rotation track to be planned immediately before the scheduled programme.
     */
    public function maxContentDurationBeforeNextSoftAnchor(
        Station $station,
        DateTimeImmutable $now,
    ): ?float {
        $seconds = $this->secondsUntilNextSoftAnchor($station, $now);
        if (null === $seconds) {
            return null;
        }

        $crossfadeOverlap = max(0.0, $station->backend_config->getCrossfadeDuration());

        return max(1.0, (float)$seconds + $crossfadeOverlap);
    }

    /**
     * True when an ordinary Standard playlist would occupy airtime currently
     * owned by a scheduled long-form Standard playlist.
     */
    public function isPlaylistPreemptedByProgram(
        StationPlaylist $playlist,
        DateTimeImmutable $when,
    ): bool {
        if (PlaylistTypes::Standard !== $playlist->type) {
            return false;
        }

        if (
            $playlist->schedule_items->count() > 0
            && $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $when, true)
        ) {
            return false;
        }

        return $this->isProgramWindowActive($playlist->station, $when);
    }

    public function isProgramWindowActive(
        Station $station,
        DateTimeImmutable $when,
    ): bool {
        return null !== $this->findActiveProgramPlaylist($station, $when);
    }

    public function areRequestsBlockedBySchedule(
        Station $station,
        DateTimeImmutable $when,
    ): bool {
        $tz = $station->getTimezoneObject();

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled || 0 === $playlist->schedule_items->count()) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                if (!$schedule->prevent_requests) {
                    continue;
                }

                if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $when, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findActiveProgramPlaylist(
        Station $station,
        DateTimeImmutable $when,
    ): ?StationPlaylist {
        $tz = $station->getTimezoneObject();

        foreach ($station->playlists as $scheduledPlaylist) {
            if (
                !$scheduledPlaylist->is_enabled
                || PlaylistTypes::Standard !== $scheduledPlaylist->type
                || $scheduledPlaylist->backendInterruptOtherSongs()
                || 0 === $scheduledPlaylist->schedule_items->count()
            ) {
                continue;
            }

            foreach ($scheduledPlaylist->schedule_items as $schedule) {
                if ($schedule->start_time === $schedule->end_time) {
                    continue;
                }

                if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $when, true)) {
                    return $scheduledPlaylist;
                }
            }
        }

        return null;
    }

    private function isClockAnchoredPlaylist(StationPlaylist $playlist): bool
    {
        if ($playlist->backendInterruptOtherSongs()) {
            return false;
        }

        if (PlaylistTypes::Standard === $playlist->type) {
            return true;
        }

        foreach ($playlist->schedule_items as $schedule) {
            if ($schedule->strict_start) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CarbonImmutable> */
    private function getScheduleBoundaries(
        StationSchedule $schedule,
        CarbonImmutable $now,
    ): array {
        $playlist = $schedule->playlist;
        if (!$playlist instanceof StationPlaylist) {
            return [];
        }

        $tz = $playlist->station->getTimezoneObject();
        $boundaries = [];
        $allowOverrun = in_array(
            StationPlaylist::OPTION_ALLOW_OVERRUN,
            $playlist->backend_options,
            true,
        );

        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $now->subDay(),
                $now->addDay(),
                20,
            );

            foreach ($occurrences as $occurrence) {
                $boundaries[] = CarbonImmutable::instance($occurrence->start)->setTimezone($tz);

                if ($schedule->start_time !== $schedule->end_time && !$allowOverrun) {
                    $boundaries[] = CarbonImmutable::instance($occurrence->end)->setTimezone($tz);
                }
            }

            return $boundaries;
        }

        $dayStart = $now->startOfDay();
        foreach ([-1, 0, 1] as $dayOffset) {
            $candidateDay = $dayStart->addDays($dayOffset);
            $start = StationSchedule::getDateTime($schedule->start_time, $tz, $candidateDay);

            if (!$this->scheduler->shouldSchedulePlayOnCurrentDate($schedule, $tz, $start)) {
                continue;
            }

            if (!$this->scheduler->isScheduleScheduledToPlayToday($schedule, $start->dayOfWeekIso)) {
                continue;
            }

            $boundaries[] = $start;

            if ($schedule->start_time === $schedule->end_time || $allowOverrun) {
                continue;
            }

            $end = StationSchedule::getDateTime($schedule->end_time, $tz, $candidateDay);
            if ($schedule->start_time > $schedule->end_time) {
                $end = $end->addDay();
            }
            $boundaries[] = $end;
        }

        return $boundaries;
    }

    private function secondsUntilNextAiNewsAnchor(
        Station $station,
        CarbonImmutable $now,
    ): ?int {
        $config = $station->backend_config;
        if (!($config->ai_news_enabled ?? false)) {
            return null;
        }

        $minutes = [];
        if ($config->ai_news_top_of_hour ?? true) {
            $minutes[] = 59;
        }
        if ($config->ai_news_bottom_of_hour ?? false) {
            $minutes[] = 29;
        }
        if ([] === $minutes) {
            $minutes[] = 59;
        }

        $best = null;
        for ($hourOffset = 0; $hourOffset <= 1; $hourOffset++) {
            $hour = $now->startOfHour()->addHours($hourOffset);

            foreach ($minutes as $minute) {
                $candidate = $hour->setMinute($minute)->setSecond(0);
                $delta = $candidate->getTimestamp() - $now->getTimestamp();
                if ($delta <= 0 || $delta > self::LOOKAHEAD_SECONDS) {
                    continue;
                }

                if (!$this->isAiNewsActiveAt($station, $candidate)) {
                    continue;
                }

                $best = null === $best ? $delta : min($best, $delta);
            }
        }

        return $best;
    }

    private function isAiNewsActiveAt(Station $station, CarbonImmutable $candidate): bool
    {
        $config = $station->backend_config;
        $activeDays = array_map(
            static fn(mixed $day): int => (int)$day,
            $config->ai_news_active_days ?? [],
        );

        if ([] !== $activeDays && !in_array($candidate->dayOfWeekIso, $activeDays, true)) {
            return false;
        }

        $activeHours = trim((string)($config->ai_news_active_hours ?? ''));
        if ('' === $activeHours) {
            return true;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            return true;
        }

        $start = ((int)$matches[1] * 60) + (int)$matches[2];
        $end = ((int)$matches[3] * 60) + (int)$matches[4];
        $current = ($candidate->hour * 60) + $candidate->minute;

        if ($start <= $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }
}
