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
        $endType = $schedule->recurrence_end_type ?? RecurrenceEndType::Never;
        $endAfter = $schedule->recurrence_end_after;
        $endDate = $schedule->recurrence_end_date;

        if ($recurrenceType === RecurrenceType::Monthly && $schedule->recurrence_monthly_pattern !== null) {
            $occurrences = self::monthlyOccurrences(
                $schedule,
                $tz,
                $effectiveStart,
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
            $inDays = empty($days) || in_array($dayOfWeek, $days, true);

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

        while ($count < $maxOccurrences) {
            $candidate = self::nextMonthlyOccurrence($schedule, $tz, $year, $month, $pattern);
            if ($candidate === null) {
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
                continue;
            }

            if ($candidate->greaterThan($rangeEnd)) {
                break;
            }
            if ($scheduleEndDate !== null && $candidate->greaterThan($scheduleEndDate)) {
                break;
            }
            if ($candidate->lessThan($effectiveStart)) {
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
                continue;
            }

            if (self::pastEndCondition($candidate, $endType, $endAfter, $endDate, $occurrenceDates)) {
                break;
            }

            $occurrenceDates[] = $candidate;
            $count++;

            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $occurrenceDates;
    }

    private static function nextMonthlyOccurrence(
        StationSchedule $schedule,
        DateTimeZone $tz,
        int $year,
        int $month,
        RecurrenceMonthlyPattern $pattern
    ): ?CarbonImmutable {
        if ($pattern === RecurrenceMonthlyPattern::Date) {
            $day = $schedule->recurrence_monthly_day;
            if ($day === null || $day < 1) {
                return null;
            }
            $lastDay = (int) CarbonImmutable::createFromDate($year, $month, 1, $tz)->endOfMonth()->format('d');
            $day = min($day, $lastDay);
            return CarbonImmutable::createFromDate($year, $month, $day, $tz)->startOf('day');
        }

        $week = $schedule->recurrence_monthly_week;
        $dow = $schedule->recurrence_monthly_day_of_week;
        if ($week === null || $dow === null) {
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
     * @param CarbonImmutable[] $occurrenceDates
     */
    private static function pastEndCondition(
        CarbonImmutable $date,
        RecurrenceEndType $endType,
        ?int $endAfter,
        ?string $endDate,
        array $occurrenceDates
    ): bool {
        if ($endType === RecurrenceEndType::OnDate && $endDate !== null && $endDate !== '') {
            $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate);
            if ($end !== false && $date->greaterThan($end->endOf('day'))) {
                return true;
            }
        }
        if ($endType === RecurrenceEndType::After && $endAfter !== null && count($occurrenceDates) >= $endAfter) {
            return true;
        }
        return false;
    }

    /**
     * Convert occurrence dates to DateRange using schedule start_time/end_time.
     * Handles overnight (start_time > end_time) by extending end to next day.
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
            if ($end->lessThan($start) || $start->equalTo($end)) {
                $end = $end->addDay();
            }
            $ranges[] = new DateRange($start, $end);
        }
        return $ranges;
    }
}
