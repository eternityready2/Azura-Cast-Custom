<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

/**
 * Mirrors Airtime Pro's Static vs Dynamic Smart Block distinction.
 *
 * - Static: the criteria are used once to *generate* a fixed tracklist, which is then
 *   locked in as this playlist's membership. It will NOT be touched by the recurring
 *   Smart Block sync task -- it only changes when someone clicks "Generate" again, or
 *   edits membership by hand.
 * - Dynamic: the criteria are the source of truth. Membership is kept in sync
 *   automatically (via the recurring sync task, and resolved fresh at the moment
 *   AutoDJ needs to play from it), so newly-added matching media appears without any
 *   manual action.
 */
#[OA\Schema(type: 'string')]
enum SmartBlockType: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';

    public static function default(): self
    {
        return self::Dynamic;
    }
}
