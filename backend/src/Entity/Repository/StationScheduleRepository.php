<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Api\StationSchedule as ApiStationSchedule;
use App\Entity\ApiGenerator\ScheduleApiGenerator;
use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceMonthlyPattern;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Station;
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

/**
 * @extends Repository<StationSchedule>
 */
final class StationScheduleRepository extends Repository
{
    protected string $entityClass = StationSchedule::class;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ScheduleApiGenerator $scheduleApiGenerator
    ) {
    }

    /**
     * @param StationPlaylist|StationStreamer $relation
     * @param array $items
     */
    public function setScheduleItems(
        StationPlaylist|StationStreamer $relation,
        array $items = []
    ): void {
        $rawScheduleItems = $this->findByRelation($relation);

        $scheduleItems = [];
        foreach ($rawScheduleItems as $row) {
            $scheduleItems[$row->id] = $row;
        }

        foreach ($items as $item) {
            $this->validateRecurrenceItem($item);

            if (isset($item['id'], $scheduleItems[$item['id']])) {
                $record = $scheduleItems[$item['id']];
                unset($scheduleItems[$item['id']]);
            } else {
                $record = new StationSchedule($relation);
            }

            $record->start_time = (int)$item['start_time'];
            $record->end_time = (int)$item['end_time'];
            $record->start_date = $item['start_date'] ?? null;
            $record->end_date = $item['end_date'] ?? null;
            $record->days = $item['days'] ?? [];
            $record->loop_once = $item['loop_once'] ?? false;

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

            $this->em->persist($record);
        }

        foreach ($scheduleItems as $row) {
            $this->em->remove($row);
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $item
     * @throws ValidationException
     */
    private function validateRecurrenceItem(array $item): void
    {
        $recurrenceType = isset($item['recurrence_type'])
            ? (is_string($item['recurrence_type']) ? RecurrenceType::tryFrom($item['recurrence_type']) : $item['recurrence_type'])
            : null;
        $endType = isset($item['recurrence_end_type'])
            ? (is_string($item['recurrence_end_type']) ? RecurrenceEndType::tryFrom($item['recurrence_end_type']) : $item['recurrence_end_type'])
            : RecurrenceEndType::Never;

        if ($recurrenceType === RecurrenceType::Monthly) {
            $pattern = isset($item['recurrence_monthly_pattern'])
                ? (is_string($item['recurrence_monthly_pattern']) ? RecurrenceMonthlyPattern::tryFrom($item['recurrence_monthly_pattern']) : $item['recurrence_monthly_pattern'])
                : null;
            if ($pattern === null) {
                throw new ValidationException(__('Monthly recurrence requires a pattern (date or day_of_week).'));
            }
            if ($pattern === RecurrenceMonthlyPattern::Date) {
                $day = isset($item['recurrence_monthly_day']) ? (int)$item['recurrence_monthly_day'] : null;
                if ($day === null || $day < 1 || $day > 31) {
                    throw new ValidationException(__('Monthly date pattern requires day of month (1-31).'));
                }
            } else {
                $week = isset($item['recurrence_monthly_week']) ? (int)$item['recurrence_monthly_week'] : null;
                $dow = isset($item['recurrence_monthly_day_of_week']) ? (int)$item['recurrence_monthly_day_of_week'] : null;
                if ($week === null || $week < 1 || $week > 5 || $dow === null || $dow < 1 || $dow > 7) {
                    throw new ValidationException(__('Monthly day-of-week pattern requires week (1-4 or 5 for last) and day of week (1-7).'));
                }
            }
        }

        if ($endType === RecurrenceEndType::After) {
            $after = isset($item['recurrence_end_after']) ? (int)$item['recurrence_end_after'] : null;
            if ($after === null || $after < 1) {
                throw new ValidationException(__('Recurrence end "after" requires a positive occurrence count.'));
            }
        }
        if ($endType === RecurrenceEndType::OnDate) {
            $endDate = $item['recurrence_end_date'] ?? null;
            if ($endDate === null || $endDate === '') {
                throw new ValidationException(__('Recurrence end "on date" requires recurrence_end_date.'));
            }
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string)$endDate);
            if ($parsed === false) {
                throw new ValidationException(__('Recurrence end date must be in Y-m-d format.'));
            }
        }
    }

    /**
     * @param StationPlaylist|StationStreamer $relation
     *
     * @return StationSchedule[]
     */
    public function findByRelation(StationPlaylist|StationStreamer $relation): array
    {
        if ($relation instanceof StationPlaylist) {
            return $this->repository->findBy(['playlist' => $relation]);
        }

        return $this->repository->findBy(['streamer' => $relation]);
    }

    /**
     * @param Station $station
     *
     * @return StationSchedule[]
     */
    public function getAllScheduledItemsForStation(Station $station): array
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT ssc, sp, sst
                FROM App\Entity\StationSchedule ssc
                LEFT JOIN ssc.playlist sp
                LEFT JOIN ssc.streamer sst
                WHERE (sp.station = :station AND sp.is_jingle = 0 AND sp.is_enabled = 1)
                OR (sst.station = :station AND sst.is_active = 1)
            DQL
        )->setParameter('station', $station)
            ->execute();
    }

    /**
     * @param Station $station
     * @param DateTimeImmutable|null $now
     *
     * @return ApiStationSchedule[]
     */
    public function getUpcomingSchedule(
        Station $station,
        ?DateTimeImmutable $now = null
    ): array {
        $stationTz = $station->getTimezoneObject();
        $now = CarbonImmutable::instance(Time::nowInTimezone($stationTz, $now));

        $startDate = $now->subDay();
        $endDate = $now->addDay()->addHour();

        $events = [];

        foreach ($this->getAllScheduledItemsForStation($station) as $scheduleItem) {
            /** @var StationSchedule $scheduleItem */
            if (ScheduleRecurrence::hasRecurrence($scheduleItem)) {
                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $scheduleItem,
                    $stationTz,
                    $startDate,
                    $endDate,
                    50
                );
                foreach ($occurrences as $dateRange) {
                    if ($dateRange->end->lessThan($now)) {
                        continue;
                    }
                    $events[] = ($this->scheduleApiGenerator)(
                        $station,
                        $scheduleItem,
                        $dateRange,
                        $now
                    );
                }
                continue;
            }

            $i = $startDate;

            while ($i <= $endDate) {
                $dayOfWeek = $i->dayOfWeekIso;

                if (
                    $this->scheduler->shouldSchedulePlayOnCurrentDate($scheduleItem, $stationTz, $i)
                    && $this->scheduler->isScheduleScheduledToPlayToday($scheduleItem, $dayOfWeek)
                ) {
                    $start = StationSchedule::getDateTime($scheduleItem->start_time, $stationTz, $i);
                    $end = StationSchedule::getDateTime($scheduleItem->end_time, $stationTz, $i);

                    // Handle overnight schedule items
                    if ($end < $start) {
                        $end = $end->addDay();

                        // For overnight schedules, verify the event end doesn't exceed the configured end_date
                        if (!empty($scheduleItem->end_date)) {
                            $configuredEndDate = CarbonImmutable::createFromFormat(
                                'Y-m-d',
                                $scheduleItem->end_date,
                                $stationTz
                            );
                            if (null !== $configuredEndDate) {
                                // Allow one extra day if start_date == end_date (single overnight event)
                                if ($scheduleItem->start_date === $scheduleItem->end_date) {
                                    $configuredEndDate = $configuredEndDate->addDay();
                                }
                                $maxEndDateTime = StationSchedule::getDateTime(
                                    $scheduleItem->end_time,
                                    $stationTz,
                                    $configuredEndDate
                                );

                                if ($end->greaterThan($maxEndDateTime)) {
                                    $i = $i->addDay();
                                    continue; // Skip this event - it exceeds the configured date range
                                }
                            }
                        }
                    }

                    // Skip events that have already happened today.
                    if ($end->lessThan($now)) {
                        $i = $i->addDay();
                        continue;
                    }

                    $events[] = ($this->scheduleApiGenerator)(
                        $station,
                        $scheduleItem,
                        new DateRange($start, $end),
                        $now
                    );
                }

                $i = $i->addDay();
            }
        }

        usort(
            $events,
            static function ($a, $b) {
                return $a->start_timestamp <=> $b->start_timestamp;
            }
        );

        return $events;
    }

    /**
     * Earliest upcoming start timestamp for the given playlist's schedule (for "sync N hours before air").
     * Uses station timezone. Looks up to 35 days ahead.
     *
     * @return int|null Unix timestamp of next start, or null if playlist has no schedule or no future occurrence
     */
    public function getNextStartTimestampForPlaylist(
        Station $station,
        StationPlaylist $playlist,
        ?DateTimeImmutable $now = null
    ): ?int {
        $stationTz = $station->getTimezoneObject();
        $now = CarbonImmutable::instance(Time::nowInTimezone($stationTz, $now));
        $rangeStart = $now;
        $rangeEnd = $now->addDays(35);

        $scheduleItems = $this->findByRelation($playlist);
        if ($scheduleItems === []) {
            return null;
        }

        $candidates = [];

        foreach ($scheduleItems as $scheduleItem) {
            if (ScheduleRecurrence::hasRecurrence($scheduleItem)) {
                $occurrences = ScheduleRecurrence::getOccurrencesInRange(
                    $scheduleItem,
                    $stationTz,
                    $rangeStart,
                    $rangeEnd,
                    20
                );
                foreach ($occurrences as $dateRange) {
                    if ($dateRange->start->getTimestamp() >= $now->getTimestamp()) {
                        $candidates[] = $dateRange->start->getTimestamp();
                        break;
                    }
                }
                continue;
            }

            $i = $rangeStart->startOf('day');
            while ($i <= $rangeEnd) {
                $dayOfWeek = $i->dayOfWeekIso;
                if (
                    $this->scheduler->shouldSchedulePlayOnCurrentDate($scheduleItem, $stationTz, $i)
                    && $this->scheduler->isScheduleScheduledToPlayToday($scheduleItem, $dayOfWeek)
                ) {
                    $start = StationSchedule::getDateTime($scheduleItem->start_time, $stationTz, $i);
                    if ($start->getTimestamp() >= $now->getTimestamp()) {
                        $candidates[] = $start->getTimestamp();
                        break;
                    }
                }
                $i = $i->addDay();
            }
        }

        if ($candidates === []) {
            return null;
        }

        return min($candidates);
    }
}
