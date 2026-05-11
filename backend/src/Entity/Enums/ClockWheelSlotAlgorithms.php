<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum ClockWheelSlotAlgorithms: string
{
    /**
     * Pick a track at random from the playlist — no history constraint.
     * The most common default in radio automation.
     */
    case Random = 'random';

    /**
     * Prefer the track whose album has been played least recently.
     * Useful for music-variety formats where album fatigue is a concern.
     */
    case OldestAlbum = 'oldest_album';

    /**
     * Prefer the track whose artist has been played least recently.
     * Standard "artist separation" rule used in commercial pop/CHR formats.
     */
    case OldestArtist = 'oldest_artist';

    /**
     * Prefer the individual track that has been played least recently.
     * Maximises variety across the full library.
     */
    case OldestTrack = 'oldest_track';

    /**
     * Prefer tracks from the album most recently added to the library.
     * Good for "new music" or "fresh adds" segments.
     */
    case MostRecentAlbum = 'most_recent_album';

    /**
     * Prefer tracks from the artist most recently added to the library.
     * Useful for "featured artist of the week" style slots.
     */
    case MostRecentArtist = 'most_recent_artist';

    public function label(): string
    {
        return match($this) {
            self::Random           => 'Random',
            self::OldestAlbum      => 'Oldest Album',
            self::OldestArtist     => 'Oldest Artist',
            self::OldestTrack      => 'Oldest Track',
            self::MostRecentAlbum  => 'Most Recent Album',
            self::MostRecentArtist => 'Most Recent Artist',
        };
    }
}
