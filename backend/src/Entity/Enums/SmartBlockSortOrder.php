<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * Controls the order in which tracks are assigned weights when the Smart Block syncer
 * builds (or rebuilds) a block's StationPlaylistMedia rows.
 *
 * Because AutoDJ reads tracks in weight order, setting this at sync time means the
 * playback order on-air matches what the user chose here -- no extra AutoDJ changes needed.
 *
 * Random      → weights are assigned via random_int(), same as the default shuffle behaviour.
 * NewestFirst → tracks uploaded most recently get the lowest weight numbers (play first).
 * OldestFirst → tracks uploaded first get the lowest weight numbers (play first).
 * AlphaTitle  → sorted A→Z by track title.
 * AlphaArtist → sorted A→Z by artist name.
 */
#[OA\Schema(type: 'string')]
enum SmartBlockSortOrder: string
{
    case Random = 'random';
    case NewestFirst = 'newest_first';
    case OldestFirst = 'oldest_first';
    case AlphaTitle = 'alpha_title';
    case AlphaArtist = 'alpha_artist';

    public static function default(): self
    {
        return self::Random;
    }

    public function label(): string
    {
        return match ($this) {
            self::Random      => 'Random',
            self::NewestFirst => 'Newest First',
            self::OldestFirst => 'Oldest First',
            self::AlphaTitle  => 'Alphabetical (Title)',
            self::AlphaArtist => 'Alphabetical (Artist)',
        };
    }
}
