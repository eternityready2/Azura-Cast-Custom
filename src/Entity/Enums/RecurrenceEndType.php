<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum RecurrenceEndType: string
{
    case Never = 'never';
    case After = 'after';
    case OnDate = 'on_date';
}
