<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationSchedule;
use App\Utilities\ScheduleRecurrence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Single source of truth for station-wide Top-of-Hour ID clock math.
 *
 * The ID always targets an operator-selected second inside minute :59. Queue
 * planning uses the same target as a soft broadcast-clock anchor, while the
 * Liquidsoap runtime guard is the final authority that can interrupt whatever
 * is on air at that exact wall-clock time.
 *
 * HARD TOH means a rigid event owns :00. The ID still starts at the configured
 * :59:ss target, but the rigid event is allowed to take control exactly at :00.
 * SOFT ETM means the new hour is open; the ID may finish naturally before normal
 * AutoDJ/news continuity resumes.
 */
final class TopOfHourClock
{
    public const int DEFAULT_LOOKAHEAD_MINUTES = 10;
    public const int MIN_LOOKAHEAD_MINUTES = 1;
    public const int MAX_LOOKAHEAD_MINUTES = 30;

    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = 10;
    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = 1;
    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = 60;

    public const int DEFAULT_ID_MAX_SECONDS = 60;
    public const int MIN_ID_MAX_SECONDS = 15;
    public const int MAX_ID_MAX_SECONDS = 60;

    public const int DEFAULT_ID_START_SECOND = 0;
    public const int MIN_ID_START_SECOND = 0;
    public const int MAX_ID_START_SECOND = 59;

    public const float DEFAULT_ID_FADE_SECONDS = 5.0;
    public const float MIN_ID_FADE_SECONDS = 1.0;
    public const float MAX_ID_FADE_SECONDS = 10.0;

    public const string CONFIG_ID_START_SECOND = 'top_of_hour_id_start_second';
    public const string CONFIG_ID_FADE_SECONDS = 'top_of_hour_id_fade_seconds';

    public function __construct(
        private readonly StationIdSelector $stationIdSelector,
    ) {
    }

    public function isEnabled(Station $station): bool
    {
        return (bool)$station->backend_config->top_of_hour_id_enabled;
    }

    public function getLookaheadMinutes(Station $station): int
    {
        return $this->clamp(
            (int)$station->backend_config->top_of_hour_lookahead_minutes,
            self::MIN_LOOKAHEAD_MINUTES,
            self::MAX_LOOKAHEAD_MINUTES,
            self::DEFAULT_LOOKAHEAD_MINUTES,
        );
    }

    public function getComplianceToleranceSeconds(Station $station): int
    {
        return $this->clamp(
            (int)$station->backend_config->top_of_hour_compliance_tolerance_seconds,
            self::MIN_COMPLIANCE_TOLERANCE_SECONDS,
            self::MAX_COMPLIANCE_TOLERANCE_SECONDS,
            self::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
        );
    }

    public function getIdMaxSeconds(Station $station): int
    {
        return $this->clamp(
            (int)$station->backend_config->top_of_hour_id_max_seconds,
            self::MIN_ID_MAX_SECONDS,
            self::MAX_ID_MAX_SECONDS,
            self::DEFAULT_ID_MAX_SECONDS,
        );
    }

    public function getIdStartSecond(Station $station): int
    {
        $raw = $station->backend_config->toArray(true) ?? [];

        return $this->clamp(
            (int)($raw[self::CONFIG_ID_START_SECOND] ?? self::DEFAULT_ID_START_SECOND),
            self::MIN_ID_START_SECOND,
            self::MAX_ID_START_SECOND,
            self::DEFAULT_ID_START_SECOND,
        );
    }

    public function getIdFadeSeconds(Station $station): float
    {
        $raw = $station->backend_config->toArray(true) ?? [];
        $value = (float)($raw[self::CONFIG_ID_FADE_SECONDS] ?? self::DEFAULT_ID_FADE_SECONDS);

        if ($value < self::MIN_ID_FADE_SECONDS || $value > self::MAX_ID_FADE_SECONDS) {
            return self::DEFAULT_ID_FADE_SECONDS;
        }

        return round($value, 1);
    }

    public function getNextBoundary(
        Station $station,
        DateTimeImmutable $from,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($from)->setTimezone($station->getTimezoneObject());

        return $local->startOfHour()->addHour()->toDateTimeImmutable();
    }

    public function plan(
        Station $station,
        DateTimeImmutable $from,
    ): ?TopOfHourPlan {
        if (!$this->isEnabled($station)) {
            return null;
        }

        $boundary = CarbonImmutable::instance($this->getNextBoundary($station, $from));
        $media = $this->stationIdSelector->select(
            $station,
            $from,
            $this->getIdMaxSeconds($station),
        );
        if (null === $media) {
            return null;
        }

        $duration = $media->getCalculatedLength();
        if ($duration <= 0.0 || $duration > self::MAX_ID_MAX_SECONDS) {
            return null;
        }

        $hard = $this->hasRigidStartAtBoundary($station, $boundary);
        $mode = $hard ? TopOfHourMode::HardToh : TopOfHourMode::SoftEtm;

        // The operator owns the exact ID start. HARD/SOFT changes what happens
        // at :00, not when the ID begins. This keeps :59:ss a true station clock
        // event and lets operators place IDs according to their real duration.
        $targetStart = $boundary
            ->subMinute()
            ->startOfMinute()
            ->addSeconds($this->getIdStartSecond($station));

        return new TopOfHourPlan(
            mode: $mode,
            boundaryAt: $boundary->toDateTimeImmutable(),
            targetStartAt: $targetStart->toDateTimeImmutable(),
            media: $media,
            durationSeconds: $duration,
        );
    }

    /**
     * True when a Clock Wheel explicitly scheduled at this boundary contains a
     * mandatory position-zero ID/legal-ID slot. In that special case the wheel
     * already supplies identification and the station-wide producer yields so
     * two IDs are not stacked back-to-back.
     */
    public function clockWheelOwnsBoundary(
        Station $station,
        DateTimeImmutable $boundaryAt,
    ): bool {
        $boundary = CarbonImmutable::instance($boundaryAt)->setTimezone($station->getTimezoneObject());

        foreach ($station->clock_wheels as $wheel) {
            if (!$wheel->is_active || !$this->wheelHasMandatoryId($wheel)) {
                continue;
            }

            foreach ($wheel->schedule_items as $schedule) {
                if ($this->scheduleStartsAt($schedule, $boundary)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function secondsUntilPlayoutAnchor(
        Station $station,
        DateTimeImmutable $from,
    ): ?int {
        $plan = $this->plan($station, $from);
        if (null === $plan) {
            return null;
        }

        $seconds = (int)floor(
            ((float)$plan->targetStartAt->format('U.u')) - ((float)$from->format('U.u'))
        );
        if ($seconds <= 0) {
            return null;
        }

        $lookaheadSeconds = $this->getLookaheadMinutes($station) * 60;
        return $seconds <= $lookaheadSeconds ? $seconds : null;
    }

    public function isInLookaheadZone(
        Station $station,
        DateTimeImmutable $from,
    ): bool {
        if (!$this->isEnabled($station)) {
            return false;
        }

        $plan = $this->plan($station, $from);
        if (null === $plan) {
            return false;
        }

        $seconds = (float)$plan->targetStartAt->format('U.u') - (float)$from->format('U.u');
        if ($seconds <= 0) {
            // A late recovery is still inside the current :59 minute, but it is
            // no longer an advance-planning lookahead condition.
            return false;
        }

        return $seconds <= ($this->getLookaheadMinutes($station) * 60);
    }

    private function hasRigidStartAtBoundary(
        Station $station,
        CarbonImmutable $boundary,
    ): bool {
        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            foreach ($playlist->schedule_items as $schedule) {
                if (
                    !$schedule->strict_start
                    && !$schedule->is_emergency
                    && !$playlist->backendInterruptOtherSongs()
                ) {
                    continue;
                }

                if ($this->scheduleStartsAt($schedule, $boundary)) {
                    return true;
                }
            }
        }

        foreach ($station->clock_wheels as $wheel) {
            if (!$wheel->is_active) {
                continue;
            }

            foreach ($wheel->schedule_items as $schedule) {
                if (ClockWheelScheduleMode::Strict !== $schedule->clock_wheel_mode) {
                    continue;
                }

                if ($this->scheduleStartsAt($schedule, $boundary)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function wheelHasMandatoryId(StationClockWheel $wheel): bool
    {
        foreach ($wheel->slots as $slot) {
            if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($slot->type, $slot->position_seconds)) {
                return true;
            }
        }

        return false;
    }

    private function scheduleStartsAt(
        StationSchedule $schedule,
        CarbonImmutable $boundary,
    ): bool {
        $tz = $boundary->getTimezone();

        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $boundary->subMinutes(2),
                $boundary->addMinutes(2),
                10,
            );

            foreach ($occurrences as $occurrence) {
                $start = CarbonImmutable::instance($occurrence->start)->setTimezone($tz);
                if ($start->getTimestamp() === $boundary->getTimestamp()) {
                    return true;
                }
            }

            return false;
        }

        $date = $boundary->format('Y-m-d');
        if (null !== $schedule->start_date && $date < $schedule->start_date) {
            return false;
        }
        if (null !== $schedule->end_date && $date > $schedule->end_date) {
            return false;
        }

        $days = $schedule->days;
        if ([] !== $days && !in_array($boundary->dayOfWeekIso, $days, false)) {
            return false;
        }

        $hour = intdiv($schedule->start_time, 100);
        $minute = $schedule->start_time % 100;

        return $boundary->hour === $hour
            && $boundary->minute === $minute
            && 0 === $boundary->second;
    }

    private function clamp(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
