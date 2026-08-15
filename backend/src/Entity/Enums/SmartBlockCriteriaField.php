<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * The field a single Smart Block criterion filters on.
 */
#[OA\Schema(type: 'string')]
enum SmartBlockCriteriaField: string
{
    case Genre = 'genre';
    case Category = 'category';
    case Artist = 'artist';
    case Album = 'album';
    case Title = 'title';
    case Duration = 'duration';
    case CustomField = 'custom_field';
    /** Matches Airtime Pro's "Last Played > N days ago" criteria (never-played counts as infinitely long ago). */
    case LastPlayed = 'last_played_days_ago';

    public static function default(): self
    {
        return self::Genre;
    }

    /**
     * Whether this field's value is free text (string comparisons) rather than numeric
     * (used to decide which comparison operators are valid/shown in the UI).
     */
    public function isNumeric(): bool
    {
        return self::Duration === $this || self::LastPlayed === $this;
    }
}
