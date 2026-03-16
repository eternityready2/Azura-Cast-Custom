<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum RecurrenceMonthlyPattern: string
{
    /** Specific date of month (1-31) */
    case Date = 'date';
    /** Nth weekday of month (e.g. 3rd Monday, last Friday) */
    case DayOfWeek = 'day_of_week';
}
