<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum ClockWheelSlotTypes: string
{
    /**
     * Copyrighted music — the main content of most radio formats.
     * Liquidsoap will draw from the assigned playlist for this category.
     */
    case Music = 'music';

    /**
     * Talk content: sermons, speeches, interviews, live recordings.
     * Treated separately so talk-heavy formats can be built without mixing with music.
     */
    case Talk = 'talk';

    /**
     * Station identification: sweepers, imaging, jingles.
     * These are typically short (~5–30 s) and run between music blocks.
     */
    case Id = 'id';

    /**
     * Promotional content that is NOT a station ID.
     * Promos are station-produced but longer and content-specific.
     */
    case Promo = 'promo';

    /**
     * Advertisement replacement files.
     * Reserved slot for ad insertion; content comes from the ad playlist.
     */
    case Ad = 'ad';

    public function label(): string
    {
        return match($this) {
            self::Music => 'Music',
            self::Talk  => 'Talk',
            self::Id    => 'ID',
            self::Promo => 'Promo',
            self::Ad    => 'Ad',
        };
    }
}
