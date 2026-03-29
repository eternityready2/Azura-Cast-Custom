<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum PodcastEpisodeStorageType: string
{
    case Podcast = 'podcast';
    case Media = 'media';
}
