<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceMonthlyPattern;
use App\Entity\Enums\RecurrenceType;
use App\Entity\StationSchedule;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Computes occurrence date/time ranges for schedule recurrence rules (RFC 5545–style).
 * Used by Scheduler, StationScheduleRepository, and Liquidsoap ConfigWriter.
 */
final class ScheduleRecurrence
{
    private const int DEFAULT_MAX_OCCURRENCES = 500;

    /**
     * Get all occurrence date ranges for a schedule within a time range.
     * Each range uses the schedule's start_time/end_time on the occurrence date(s).
     *
     * @return DateRange[]
     */
    public static function getOccurrencesInRange(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        int $maxOccurrences = self::DEFAULT_MAX_OCCURRENCES
    ): array {
        $rangeStart = $rangeStart->setTimezone($tz)->startOf('day');
        $rangeEnd = $rangeEnd->setTimezone($tz)->endOf('day');

        $effectiveStart = self::effectiveRangeStart($schedule, $tz, $rangeStart);
        $endType = $schedule->recurrence_end_type ?? RecurrenceEndType::Never;
        $endAfter = $schedule->recurrence_end_after;

        // When "stop after N occurrences" is set, always count from a global start
        // so we get the first N plays ever, not the first N in the requested range.
        if ($endType === RecurrenceEndType::After && $endAfter !== null) {
            $startDate = $schedule->start_date;
            if ($startDate !== null && $startDate !== '') {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);
                if ($parsed !== false) {
                    $effectiveStart = $parsed->startOf('day');
                }
            } else {
                // No start date: use a fixed anchor so "first N" is consistent for the calendar.
                $effectiveStart = CarbonImmutable::createFromDate(1970, 1, 1, $tz)->startOf('day');
            }
        }

        if ($effectiveStart->greaterThan($rangeEnd)) {
            return [];
        }

        $scheduleEndDate = null;
        if ($schedule->end_date !== null && $schedule->end_date !== '') {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $schedule->end_date, $tz);
            if ($parsed !== false) {
                $scheduleEndDate = $parsed->endOf('day');
            }
        }

        $occurrences = [];
        $recurrenceType = $schedule->recurrence_type;
        $interval = max(1, $schedule->recurrence_interval);
        $days = $schedule->days;
        $endDate = $schedule->recurrence_end_date;

        if ($recurrenceType === RecurrenceType::Monthly && $schedule->recurrence_monthly_pattern !== null) {
            $occurrences = self::monthlyOccurrences(
                $schedule,
                $tz,
                $effectiveStart,
                $rangeStart,
                $rangeEnd,
                $scheduleEndDate,
                $endType,
                $endAfter,
                $endDate,
                $maxOccurrences
            );
        } else {
            $occurrences = self::weeklyOccurrences(
                $schedule,
                $tz,
                $effectiveStart,
                $rangeEnd,
                $scheduleEndDate,
                $recurrenceType,
                $interval,
                $days,
                $endType,
                $endAfter,
                $endDate,
                $maxOccurrences
            );
        }

        return self::applyTimeWindow($schedule, $tz, $occurrences);
    }

    public static function hasRecurrence(StationSchedule $schedule): bool
    {
        if ($schedule->recurrence_type !== null && $schedule->recurrence_type !== RecurrenceType::Weekly) {
            return true;
        }
        if ($schedule->recurrence_type === RecurrenceType::Monthly && $schedule->recurrence_monthly_pattern !== null) {
            return true;
        }
        if ($schedule->recurrence_interval > 1) {
            return true;
        }
        // "Stop after N occurrences" or "stop on date" require the recurrence path so the limit is applied (e.g. on calendar).
        $endType = $schedule->recurrence_end_type ?? RecurrenceEndType::Never;
        if ($endType === RecurrenceEndType::After && $schedule->recurrence_end_after !== null) {
            return true;
        }
        if ($endType === RecurrenceEndType::OnDate) {
            return true;
        }
        return false;
    }

    /**
     * Whether the given date (in station TZ) has any occurrence of this schedule.
     */
    public static function isDateInSchedule(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $date
    ): bool {
        $startOfDay = $date->setTimezone($tz)->startOf('day');
        $endOfDay = $startOfDay->endOf('day');
        $ranges = self::getOccurrencesInRange($schedule, $tz, $startOfDay, $endOfDay, 10);
        return $ranges !== [];
    }

    /**
     * Effective start for iteration: schedule start_date or range start.
     */
    private static function effectiveRangeStart(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $rangeStart
    ): CarbonImmutable {
        $startDate = $schedule->start_date;
        if ($startDate !== null && $startDate !== '') {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);
            if ($parsed !== false) {
                $candidate = $parsed->startOf('day');
                return $candidate->greaterThan($rangeStart) ? $candidate : $rangeStart;
            }
        }
        return $rangeStart;
    }

    /**
     * Weekly / biweekly / custom interval: collect candidate dates then filter by end conditions.
     *
     * @param CarbonImmutable[] $candidateDates
     * @return CarbonImmutable[]
     */
    private static function weeklyOccurrences(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $effectiveStart,
        CarbonImmutable $rangeEnd,
        ?CarbonImmutable $scheduleEndDate,
        ?RecurrenceType $recurrenceType,
        int $interval,
        array $days,
        RecurrenceEndType $endType,
        ?int $endAfter,
        ?string $endDate,
        int $maxOccurrences
    ): array {
        $occurrenceDates = [];
        $anchor = self::anchorDate($schedule, $tz, $effectiveStart);
        $i = $effectiveStart->copy();

        while ($i <= $rangeEnd && count($occurrenceDates) < $maxOccurrences) {
            if ($scheduleEndDate !== null && $i->greaterThan($scheduleEndDate)) {
                break;
            }
            if (self::pastEndCondition($i, $endType, $endAfter, $endDate, $occurrenceDates)) {
                break;
            }

            $dayOfWeek = $i->dayOfWeekIso;
            // Loose comparison: $days may contain int or numeric strings from JSON/API.
            $inDays = empty($days) || in_array($dayOfWeek, $days, false);

            if (!$inDays) {
                $i = $i->addDay();
                continue;
            }

            if ($recurrenceType === null || $recurrenceType === RecurrenceType::Weekly) {
                if ($interval <= 1) {
                    $occurrenceDates[] = $i->copy();
                } else {
                    if (self::matchesWeeklyInterval($anchor, $i, $interval)) {
                        $occurrenceDates[] = $i->copy();
                    }
                }
            } elseif ($recurrenceType === RecurrenceType::Biweekly) {
                if (self::matchesWeeklyInterval($anchor, $i, 2)) {
                    $occurrenceDates[] = $i->copy();
                }
            } elseif ($recurrenceType === RecurrenceType::Custom) {
                if (self::matchesWeeklyInterval($anchor, $i, $interval)) {
                    $occurrenceDates[] = $i->copy();
                }
            }

            $i = $i->addDay();
        }

        return $occurrenceDates;
    }

    /**
     * Anchor date for bi-weekly/custom interval: "every N weeks" is computed from this date.
     * When start_date is set, that is the anchor; otherwise the range start is used (less predictable).
     * Callers (e.g. Liquidsoap) use a range starting near "now", so for custom/biweekly we recommend
     * start_date to be set so the pattern aligns as the user expects.
     */
    private static function anchorDate(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $fallback
    ): CarbonImmutable {
        $startDate = $schedule->start_date;
        if ($startDate !== null && $startDate !== '') {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $tz);
            if ($parsed !== false) {
                return $parsed->startOf('day');
            }
        }
        return $fallback->startOf('day');
    }

    private static function matchesWeeklyInterval(
        CarbonImmutable $anchor,
        CarbonImmutable $date,
        int $interval
    ): bool {
        $daysSinceAnchor = (int) $anchor->diffInDays($date->startOf('day'), false);
        if ($daysSinceAnchor < 0) {
            return false;
        }
        $weeks = (int) floor($daysSinceAnchor / 7);
        return $weeks % $interval === 0;
    }

    /**
     * Monthly: by date (1-31) or by day-of-week (e.g. 3rd Monday, last Friday).
     *
     * @return CarbonImmutable[]
     */
    private static function monthlyOccurrences(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $effectiveStart,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?CarbonImmutable $scheduleEndDate,
        RecurrenceEndType $endType,
        ?int $endAfter,
        ?string $endDate,
        int $maxOccurrences
    ): array {
        $occurrenceDates = [];
        $pattern = $schedule->recurrence_monthly_pattern;

        $year = (int) $effectiveStart->format('Y');
        $month = (int) $effectiveStart->format('m');
        $count = 0;
        /** Count valid monthly hits strictly before $rangeStart (for "end after N occurrences" from a global anchor). */
        $occurrencesBeforeRangeStart = 0;

        $monthSteps = 0;
        $maxMonthSteps = 20000;

        while ($count < $maxOccurrences && $monthSteps < $maxMonthSteps) {
            ++$monthSteps;
            $candidates = self::collectMonthlyCandidatesForMonth($schedule, $tz, $year, $month, $pattern);
            if ($candidates === []) {
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($count >= $maxOccurrences) {
                    break 2;
                }

                // Skip outside the requested API window; later months may still fall inside.
                if ($candidate->greaterThan($rangeEnd)) {
                    continue;
                }
                if ($scheduleEndDate !== null && $candidate->greaterThan($scheduleEndDate)) {
                    break 2;
                }
                if ($candidate->lessThan($effectiveStart)) {
                    continue;
                }

                // "End on date" applies to every occurrence, including months before the visible calendar range.
                if ($endType === RecurrenceEndType::OnDate && $endDate !== null && $endDate !== '') {
                    $recurrenceEnd = CarbonImmutable::createFromFormat('Y-m-d', $endDate);
                    if ($recurrenceEnd !== false && $candidate->greaterThan($recurrenceEnd->endOf('day'))) {
                        break 2;
                    }
                }

                // Calendar window: only emit days inside [rangeStart, rangeEnd]. Still count earlier hits
                // toward "after N occurrences" when that end rule is set.
                if ($candidate->lessThan($rangeStart)) {
                    if ($endType === RecurrenceEndType::After && $endAfter !== null) {
                        ++$occurrencesBeforeRangeStart;
                        if ($occurrencesBeforeRangeStart >= $endAfter) {
                            break 2;
                        }
                    }
                    continue;
                }

                $totalAfterOccurrences = $occurrencesBeforeRangeStart + count($occurrenceDates);
                if (self::pastEndCondition(
                    $candidate,
                    $endType,
                    $endAfter,
                    $endDate,
                    $occurrenceDates,
                    $totalAfterOccurrences
                )) {
                    break 2;
                }

                $occurrenceDates[] = $candidate;
                ++$count;
            }

            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $occurrenceDates;
    }

    /**
     * @return CarbonImmutable[]
     */
    private static function collectMonthlyCandidatesForMonth(
        StationSchedule $schedule,
        DateTimeZone $tz,
        int $year,
        int $month,
        ?RecurrenceMonthlyPattern $pattern
    ): array {
        if ($pattern === null) {
            return [];
        }
        if ($pattern === RecurrenceMonthlyPattern::Date) {
            $one = self::nextMonthlyDateOccurrence($schedule, $tz, $year, $month);

            return $one !== null ? [$one] : [];
        }

        $week = $schedule->recurrence_monthly_week;
        if ($week === null) {
            return [];
        }
        $week = (int) $week;
        $dows = self::monthlyDayOfWeekDowList($schedule);
        if ($dows === []) {
            return [];
        }

        $dates = [];
        foreach ($dows as $dow) {
            $c = self::nthWeekdayOfMonth($tz, $year, $month, $week, $dow);
            if ($c !== null) {
                $dates[] = $c;
            }
        }
        usort($dates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $dates;
    }

    /**
     * Unique sorted ISO weekdays (1=Mon..7=Sun) for "nth weekday" monthly pattern.
     * Uses `days` when set; otherwise falls back to recurrence_monthly_day_of_week.
     *
     * @return int[]
     */
    private static function monthlyDayOfWeekDowList(StationSchedule $schedule): array
    {
        $out = [];
        foreach ($schedule->days ?? [] as $d) {
            $d = (int) $d;
            if ($d >= 1 && $d <= 7) {
                $out[] = $d;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        if ($out !== []) {
            return $out;
        }
        if ($schedule->recurrence_monthly_day_of_week !== null) {
            $d = (int) $schedule->recurrence_monthly_day_of_week;
            if ($d >= 1 && $d <= 7) {
                return [$d];
            }
        }

        return [];
    }

    private static function nextMonthlyDateOccurrence(
        StationSchedule $schedule,
        DateTimeZone $tz,
        int $year,
        int $month
    ): ?CarbonImmutable {
        $day = $schedule->recurrence_monthly_day;
        if ($day === null || $day < 1) {
            return null;
        }
        $lastDay = (int) CarbonImmutable::createFromDate($year, $month, 1, $tz)->endOfMonth()->format('d');
        $day = min((int) $day, $lastDay);

        return CarbonImmutable::createFromDate($year, $month, $day, $tz)->startOf('day');
    }

    /**
     * Nth occurrence of $dow (ISO 1=Mon..7=Sun) in the month, or last weekday of month when $week === 5.
     */
    private static function nthWeekdayOfMonth(
        DateTimeZone $tz,
        int $year,
        int $month,
        int $week,
        int $dow
    ): ?CarbonImmutable {
        if ($week < 1 || $week > 5 || $dow < 1 || $dow > 7) {
            return null;
        }

        $first = CarbonImmutable::createFromDate($year, $month, 1, $tz);
        $last = $first->endOfMonth();

        if ($week === 5) {
            $date = $last->copy();
            while ($date->dayOfWeekIso !== $dow) {
                $date = $date->subDay();
                if ($date->month !== $month) {
                    return null;
                }
            }

            return $date->startOf('day');
        }

        $nth = 0;
        $i = $first->copy();
        while ($i->month === $month && $i <= $last) {
            if ($i->dayOfWeekIso === $dow) {
                $nth++;
                if ($nth === $week) {
                    return $i->startOf('day');
                }
            }
            $i = $i->addDay();
        }

        return null;
    }

    /**
     * @param CarbonImmutable[] $occurrenceDates In-range occurrences collected so far (weekly path).
     * @param int|null $totalAfterOccurrences If set, used for "after N" instead of count($occurrenceDates)
     *        (monthly path counts occurrences before the requested range toward N).
     */
    private static function pastEndCondition(
        CarbonImmutable $date,
        RecurrenceEndType $endType,
        ?int $endAfter,
        ?string $endDate,
        array $occurrenceDates,
        ?int $totalAfterOccurrences = null
    ): bool {
        if ($endType === RecurrenceEndType::OnDate && $endDate !== null && $endDate !== '') {
            $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate);
            if ($end !== false && $date->greaterThan($end->endOf('day'))) {
                return true;
            }
        }
        if ($endType === RecurrenceEndType::After && $endAfter !== null) {
            $n = $totalAfterOccurrences ?? count($occurrenceDates);
            if ($n >= $endAfter) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert occurrence dates to DateRange using schedule start_time/end_time.
     * Handles overnight (start_time > end_time) by extending end to next day.
     * Handles "play once" (start_time === end_time) with a 15-minute window so the schedule is only active at that time.
     *
     * @param CarbonImmutable[] $occurrenceDates
     * @return DateRange[]
     */
    private static function applyTimeWindow(
        StationSchedule $schedule,
        DateTimeZone $tz,
        array $occurrenceDates
    ): array {
        $ranges = [];
        foreach ($occurrenceDates as $day) {
            $start = StationSchedule::getDateTime($schedule->start_time, $tz, $day);
            $end = StationSchedule::getDateTime($schedule->end_time, $tz, $day);
            if ($start->equalTo($end)) {
                $end = $start->addMinutes(15);
            } elseif ($end->lessThan($start)) {
                $end = $end->addDay();
            }
            $ranges[] = new DateRange($start, $end);
        }
        return $ranges;
    }
}
