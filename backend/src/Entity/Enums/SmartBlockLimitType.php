<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * Whether {@see \App\Entity\StationPlaylist::$smart_block_limit} caps the pool by a
 * number of tracks, or by total playback duration in minutes -- mirrors Airtime Pro's
 * "Limit to: [amount] [hours|tracks]" control in the Smart Block editor.
 */
#[OA\Schema(type: 'string')]
enum SmartBlockLimitType: string
{
    case Tracks = 'tracks';
    case Duration = 'duration';

    public static function default(): self
    {
        return self::Tracks;
    }
}
