<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
/**
 * @deprecated No longer used for runtime behavior. PR8 uses PHP selection first; AutoDJ cue_out
 *             applies automatically when {@see StationQueue::$clock_wheel_enforce_cap} is set.
 */
enum ClockWheelDurationEnforcement: string
{
    case Php = 'php';

    case Annotate = 'annotate';
}
