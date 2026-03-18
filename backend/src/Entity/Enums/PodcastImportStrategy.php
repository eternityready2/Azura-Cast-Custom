<?php

declare(strict_types=1);

namespace App\Entity\Enums;

enum PodcastImportStrategy: string
{
    /** Keep one episode: newest by pub date; replace previous files when feed has newer. */
    case LatestSingle = 'latest_single';

    /** Import every feed item not yet in library (classic behavior). */
    case BackfillAll = 'backfill_all';
}
