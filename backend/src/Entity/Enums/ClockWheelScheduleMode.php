<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum ClockWheelScheduleMode: string
{
    /** Natural-length playback within anchor windows; may defer or pick shortest fit. */
    case Flexible = 'flexible';

    /** Wall-clock discipline: must fit the window; hard cap when station enforcement is enabled. */
    case Strict = 'strict';
}
