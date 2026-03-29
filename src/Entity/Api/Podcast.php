<?php

declare(strict_types=1);

namespace App\Entity\Api;

use App\Entity\Api\Traits\HasLinks;
use App\Entity\PodcastBrandingConfiguration;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Api_Podcast',
    type: 'object'
)]
final class Podcast
{
    use HasLinks;

    #[OA\Property]
    public string $id;

    #[OA\Property]
    public int $storage_location_id;

    #[OA\Property]
    public string $source;

    #[OA\Property]
    public ?int $playlist_id = null;

    #[OA\Property]
    public bool $playlist_auto_publish = false;

    #[OA\Property(description: 'RSS/Atom feed URL for import source')]
    public ?string $feed_url = null;

    #[OA\Property(description: 'Enable automatic download of new episodes from feed')]
    public bool $auto_import_enabled = false;

    #[OA\Property(description: 'Keep only the last N episodes (0 = keep all). Older episodes are deleted.')]
    public int $auto_keep_episodes = 0;

    #[OA\Property(description: 'Auto-import: latest_single = one newest episode (replace); backfill_all = all missing from feed.')]
    public string $import_strategy = 'latest_single';

    #[OA\Property(description: 'When set and podcast has a linked playlist: run auto-import only N hours before the playlist\'s next scheduled start (e.g. 5). Null/0 = every sync tick.')]
    public ?int $import_sync_before_hours = null;

    #[OA\Property(description: 'Where to store episode files: podcast folder or station media folder (for playlists).')]
    public string $episode_storage_type = 'podcast';

    #[OA\Property(description: 'Folder path within station media for episode files when episode_storage_type is media (e.g. "Radio Shows/MyShow"). Empty = media library root.')]
    public ?string $media_folder_path = null;

    #[OA\Property]
    public string $title;

    #[OA\Property]
    public ?string $link = null;

    #[OA\Property]
    public string $description;

    #[OA\Property]
    public string $description_short;

    #[OA\Property]
    public bool $explicit;

    #[OA\Property]
    public bool $is_enabled = true;

    #[OA\Property(description: "An array containing podcast-specific branding configuration")]
    public PodcastBrandingConfiguration $branding_config;

    #[OA\Property]
    public string $language;

    #[OA\Property]
    public string $language_name;

    #[OA\Property]
    public string $author;

    #[OA\Property]
    public string $email;

    #[OA\Property]
    public bool $has_custom_art = false;

    #[OA\Property]
    public string $art;

    #[OA\Property]
    public int $art_updated_at = 0;

    #[OA\Property(
        description: 'The UUIDv5 global unique identifier for this podcast, based on its RSS feed URL.'
    )]
    public string $guid;

    #[OA\Property]
    public bool $is_published = true;

    #[OA\Property(
        description: 'Episode count: for import-source podcasts with a feed URL, number of <item>/<entry> elements in the remote RSS/Atom feed (cached; falls back to local episodes with a title if the feed cannot be fetched). For other sources, number of local episodes with a non-empty title.'
    )]
    public int $episodes = 0;

    /**
     * @var PodcastCategory[]
     */
    #[OA\Property(
        type: 'array',
        items: new OA\Items(type: PodcastCategory::class)
    )]
    public array $categories = [];
}
