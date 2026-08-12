<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * Whether a Smart Block's criteria rows must ALL match a track (AND) or if
 * matching ANY ONE of them is sufficient (OR).
 */
#[OA\Schema(type: 'string')]
enum SmartBlockMatchType: string
{
    case All = 'all';
    case Any = 'any';

    public static function default(): self
    {
        return self::All;
    }
}
