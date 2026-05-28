<?php

declare(strict_types=1);

namespace App\Radio\Schedule;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceMonthlyPattern;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Station;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\StationClockWheel;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\StationStreamer;
use App\Exception\ValidationException;
use App\Radio\AutoDJ\Scheduler;
use App\Utilities\DateRange;
use App\Utilities\ScheduleRecurrence;
use App\Utilities\Time;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Ensures station calendar entries (playlists, streamers, clock wheels) do not overlap.
 */
final class ScheduleConflictChecker
{
    private const int VALIDATION_WINDOW_DAYS = 90;

    public function __construct(
        private readonly ReloadableEntityManagerInterface $em,
        private readonly Scheduler $scheduler,
    ) {
    }

    /**
     * @param StationPlaylist|StationStreamer|StationClockWheel $relation
     * @param array<int, array<string, mixed>> $items
     */
    public function assertBatchHasNoConflicts(
        Station $station,
        StationPlaylist|StationStreamer|StationClockWheel $relation,
        array $items,
    ): void {
        $existing = $this->getAllScheduledItemsForStation($station);

        $candidates = [];
        foreach ($items as $item) {
            $candidates[] = $this->buildCandidateSchedule($relation, $item);
        }

        foreach ($candidates as $i => $candidateA) {
            foreach ($candidates as $j => $candidateB) {
                if ($j <= $i) {
                    continue;
                }

                if ($this->schedulesOverlap($station, $candidateA, $candidateB)) {
                    throw new ValidationException(
                        __('Two schedule entries in this request overlap with each other.')
                    );
                }
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($existing as $existingItem) {
                if ($this->isSameRelation($existingItem, $relation)) {
                    continue;
                }

                if ($this->schedulesOverlap($station, $candidate, $existingItem)) {
                    throw new ValidationException(
                        $this->formatConflictMessage($existingItem)
                    );
                }
            }
        }
    }

    /**
     * Returns true when a playlist or streamer schedule is active (clock wheel must defer).
     */
    public function hasNonClockWheelScheduleActive(
        Station $station,
        DateTimeImmutable $now,
    ): bool {
        $tz = $station->getTimezoneObject();

        foreach ($this->getAllScheduledItemsForStation($station) as $schedule) {
            if ($schedule->clock_wheel !== null) {
                continue;
            }

            if ($this->scheduler->shouldSchedulePlayNow($schedule, $tz, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return StationSchedule[]
     */
    private function getAllScheduledItemsForStation(Station $station): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, sp, sst, scw
                FROM App\Entity\StationSchedule ssc
                LEFT JOIN ssc.playlist sp
                LEFT JOIN ssc.streamer sst
                LEFT JOIN ssc.clock_wheel scw
                WHERE (sp.station = :station AND sp.is_jingle = 0 AND sp.is_enabled = 1)
                OR (sst.station = :station AND sst.is_active = 1)
                OR (scw.station = :station AND scw.is_active = 1)
            DQL
        )->setParameter('station', $station)
            ->execute();
    }

    private function schedulesOverlap(
        Station $station,
        StationSchedule $a,
        StationSchedule $b,
    ): bool {
        $tz = $station->getTimezoneObject();
        $rangeStart = CarbonImmutable::instance(Time::nowInTimezone($tz))->startOf('day');
        $rangeEnd = $rangeStart->addDays(self::VALIDATION_WINDOW_DAYS)->endOf('day');

        $rangesA = $this->expandOccurrences($a, $tz, $rangeStart, $rangeEnd);
        $rangesB = $this->expandOccurrences($b, $tz, $rangeStart, $rangeEnd);

        foreach ($rangesA as $rangeA) {
            foreach ($rangesB as $rangeB) {
                if ($rangeA->isWithin($rangeB)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return DateRange[]
     */
    private function expandOccurrences(
        StationSchedule $schedule,
        DateTimeZone $tz,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): array {
        if (ScheduleRecurrence::hasRecurrence($schedule)) {
            return ScheduleRecurrence::getOccurrencesInRange(
                $schedule,
                $tz,
                $rangeStart,
                $rangeEnd,
                200
            );
        }

        $ranges = [];
        $cursor = $rangeStart;

        while ($cursor <= $rangeEnd) {
            $dayOfWeek = $cursor->dayOfWeekIso;

            if (
                $this->scheduler->shouldSchedulePlayOnCurrentDate($schedule, $tz, $cursor)
                && $this->scheduler->isScheduleScheduledToPlayToday($schedule, $dayOfWeek)
            ) {
                $start = StationSchedule::getDateTime($schedule->start_time, $tz, $cursor);
                $end = StationSchedule::getDateTime($schedule->end_time, $tz, $cursor);

                if ($end->lessThan($start)) {
                    $end = $end->addDay();
                }

                $ranges[] = new DateRange($start, $end);
            }

            $cursor = $cursor->addDay();
        }

        return $ranges;
    }

  /**
     * @param StationPlaylist|StationStreamer|StationClockWheel $relation
     * @param array<string, mixed> $item
     */
    private function buildCandidateSchedule(
        StationPlaylist|StationStreamer|StationClockWheel $relation,
        array $item,
    ): StationSchedule {
        $record = new StationSchedule($relation);
        $record->start_time = (int)$item['start_time'];
        $record->end_time = (int)$item['end_time'];
        $record->start_date = $item['start_date'] ?? null;
        $record->end_date = $item['end_date'] ?? null;

        $daysInput = $item['days'] ?? [];
        if (!is_array($daysInput)) {
            $daysInput = [];
        }
        $record->days = array_values(array_unique(array_filter(
            array_map(static fn ($d) => (int)$d, $daysInput),
            static fn (int $d) => $d >= 1 && $d <= 7
        )));

        $record->loop_once = $item['loop_once'] ?? false;

        if ($relation instanceof StationClockWheel) {
            $record->loop_once = false;
            $modeRaw = $item['clock_wheel_mode'] ?? ClockWheelScheduleMode::Flexible->value;
            $record->clock_wheel_mode = is_string($modeRaw)
                ? (ClockWheelScheduleMode::tryFrom($modeRaw) ?? ClockWheelScheduleMode::Flexible)
                : ClockWheelScheduleMode::Flexible;
        } else {
            $record->clock_wheel_mode = null;
        }

        $record->recurrence_type = isset($item['recurrence_type'])
            ? (is_string($item['recurrence_type']) ? RecurrenceType::tryFrom($item['recurrence_type']) : $item['recurrence_type'])
            : null;
        $record->recurrence_interval = isset($item['recurrence_interval'])
            ? max(1, (int)$item['recurrence_interval'])
            : 1;
        $record->recurrence_monthly_pattern = isset($item['recurrence_monthly_pattern'])
            ? (is_string($item['recurrence_monthly_pattern']) ? RecurrenceMonthlyPattern::tryFrom($item['recurrence_monthly_pattern']) : $item['recurrence_monthly_pattern'])
            : null;
        $record->recurrence_monthly_day = isset($item['recurrence_monthly_day']) ? (int)$item['recurrence_monthly_day'] : null;
        $record->recurrence_monthly_week = isset($item['recurrence_monthly_week']) ? (int)$item['recurrence_monthly_week'] : null;
        $record->recurrence_monthly_day_of_week = isset($item['recurrence_monthly_day_of_week']) ? (int)$item['recurrence_monthly_day_of_week'] : null;
        $record->recurrence_end_type = isset($item['recurrence_end_type'])
            ? (is_string($item['recurrence_end_type']) ? RecurrenceEndType::tryFrom($item['recurrence_end_type']) ?? RecurrenceEndType::Never : $item['recurrence_end_type'])
            : RecurrenceEndType::Never;
        $record->recurrence_end_after = isset($item['recurrence_end_after']) ? (int)$item['recurrence_end_after'] : null;
        $record->recurrence_end_date = $item['recurrence_end_date'] ?? null;

        return $record;
    }

    private function isSameRelation(
        StationSchedule $schedule,
        StationPlaylist|StationStreamer|StationClockWheel $relation,
    ): bool {
        if ($relation instanceof StationPlaylist) {
            return $schedule->playlist?->id === $relation->id;
        }

        if ($relation instanceof StationStreamer) {
            return $schedule->streamer?->id === $relation->id;
        }

        return $schedule->clock_wheel?->id === $relation->id;
    }

    private function formatConflictMessage(StationSchedule $existing): string
    {
        if ($existing->playlist !== null) {
            return sprintf(
                __('This time conflicts with the scheduled playlist "%s".'),
                $existing->playlist->name
            );
        }

        if ($existing->streamer !== null) {
            return sprintf(
                __('This time conflicts with the scheduled streamer "%s".'),
                $existing->streamer->display_name
            );
        }

        if ($existing->clock_wheel !== null) {
            return sprintf(
                __('This time conflicts with the scheduled clock wheel "%s".'),
                $existing->clock_wheel->name
            );
        }

        return __('This time conflicts with another scheduled item.');
    }
}
