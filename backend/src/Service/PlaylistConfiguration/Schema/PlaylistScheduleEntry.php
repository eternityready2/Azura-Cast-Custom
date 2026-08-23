<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Utilities\Types;
use JsonSerializable;

/**
 * Portable playlist schedule entry. The extended fields preserve this fork's
 * strict/flexible scheduling and recurrence configuration when playlists are
 * moved between stations or installations.
 */
final class PlaylistScheduleEntry implements JsonSerializable
{
    public function __construct(
        public readonly int $startTime,
        public readonly int $endTime,
        public readonly array $days,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly bool $loopOnce,
        public readonly bool $preventRequests,
        public readonly bool $strictStart = false,
        public readonly bool $isEmergency = false,
        public readonly ?string $recurrenceType = null,
        public readonly int $recurrenceInterval = 1,
        public readonly ?string $recurrenceMonthlyPattern = null,
        public readonly ?int $recurrenceMonthlyDay = null,
        public readonly ?int $recurrenceMonthlyWeek = null,
        public readonly ?int $recurrenceMonthlyDayOfWeek = null,
        public readonly ?string $recurrenceEndType = null,
        public readonly ?int $recurrenceEndAfter = null,
        public readonly ?string $recurrenceEndDate = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startTime: Types::int($data['start_time'] ?? null),
            endTime: Types::int($data['end_time'] ?? null),
            days: array_map('intval', Types::array($data['days'] ?? [])),
            startDate: Types::stringOrNull($data['start_date'] ?? null),
            endDate: Types::stringOrNull($data['end_date'] ?? null),
            loopOnce: Types::bool($data['loop_once'] ?? null),
            preventRequests: Types::bool($data['prevent_requests'] ?? null),
            strictStart: Types::bool($data['strict_start'] ?? false),
            isEmergency: Types::bool($data['is_emergency'] ?? false),
            recurrenceType: Types::stringOrNull($data['recurrence_type'] ?? null),
            recurrenceInterval: max(1, Types::int($data['recurrence_interval'] ?? 1, 1)),
            recurrenceMonthlyPattern: Types::stringOrNull($data['recurrence_monthly_pattern'] ?? null),
            recurrenceMonthlyDay: Types::intOrNull($data['recurrence_monthly_day'] ?? null),
            recurrenceMonthlyWeek: Types::intOrNull($data['recurrence_monthly_week'] ?? null),
            recurrenceMonthlyDayOfWeek: Types::intOrNull($data['recurrence_monthly_day_of_week'] ?? null),
            recurrenceEndType: Types::stringOrNull($data['recurrence_end_type'] ?? null),
            recurrenceEndAfter: Types::intOrNull($data['recurrence_end_after'] ?? null),
            recurrenceEndDate: Types::stringOrNull($data['recurrence_end_date'] ?? null),
        );
    }

    public function jsonSerialize(): mixed
    {
        return [
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'days' => $this->days,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'loop_once' => $this->loopOnce,
            'prevent_requests' => $this->preventRequests,
            'strict_start' => $this->strictStart,
            'is_emergency' => $this->isEmergency,
            'recurrence_type' => $this->recurrenceType,
            'recurrence_interval' => $this->recurrenceInterval,
            'recurrence_monthly_pattern' => $this->recurrenceMonthlyPattern,
            'recurrence_monthly_day' => $this->recurrenceMonthlyDay,
            'recurrence_monthly_week' => $this->recurrenceMonthlyWeek,
            'recurrence_monthly_day_of_week' => $this->recurrenceMonthlyDayOfWeek,
            'recurrence_end_type' => $this->recurrenceEndType,
            'recurrence_end_after' => $this->recurrenceEndAfter,
            'recurrence_end_date' => $this->recurrenceEndDate,
        ];
    }
}
