<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\PodcastEpisodeStorageType;
use App\Entity\Enums\PodcastImportStrategy;
use App\Entity\Enums\PodcastSources;
use Azura\Normalizer\Attributes\DeepNormalize;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[
    ORM\Entity,
    ORM\Table(name: 'podcast'),
    Attributes\Auditable
]
final class Podcast implements Interfaces\IdentifiableEntityInterface
{
    use Traits\HasUniqueId;
    use Traits\TruncateStrings;

    public const string DIR_PODCAST_ARTWORK = '.podcast_art';

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'storage_location_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly StorageLocation $storage_location;

    /* TODO Remove direct identifier access. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $storage_location_id;

    #[DeepNormalize(true)]
    #[ORM\ManyToOne(inversedBy: 'podcasts')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public ?StationPlaylist $playlist = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $playlist_id = null;

    #[ORM\Column(type: 'string', length: 50, enumType: PodcastSources::class)]
    public PodcastSources $source = PodcastSources::Manual;

    /** RSS/Atom feed URL for import source. */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $feed_url = null {
        set => $this->trimTruncateNullableString($value, 500);
    }

    /** Enable automatic download of new episodes from feed. */
    #[ORM\Column]
    public bool $auto_import_enabled = false;

    /** Keep only the last N episodes (0 = keep all). Older episodes are deleted and replaced. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    public int $auto_keep_episodes = 0;

    /**
     * Auto-import behavior: latest_single = one newest episode, replace previous;
     * backfill_all = import all missing items from feed.
     */
    #[ORM\Column(type: 'string', length: 32, enumType: PodcastImportStrategy::class, options: ['default' => 'backfill_all'])]
    public PodcastImportStrategy $import_strategy = PodcastImportStrategy::BackfillAll;

    /**
     * Optional cron (e.g. "30 7 1 * *" = 07:30 on the 1st of each month). When set, auto-import runs only when this expression is due.
     * Empty = run on every global podcast sync tick (~15 min) when auto_import_enabled.
     */
    #[ORM\Column(length: 120, nullable: true)]
    public ?string $import_cron = null {
        set => $this->trimTruncateNullableString($value, 120);
    }

    /**
     * When set and podcast has a linked playlist with schedule: run auto-import only within this many hours
     * before the playlist's next scheduled start. E.g. 5 = sync once, ~5 hours before each air time.
     * Null or 0 = do not use schedule-based sync (use import_cron or every tick).
     */
    #[ORM\Column(type: 'smallint', nullable: true, options: ['default' => null])]
    public ?int $import_sync_before_hours = null;

    /** Where to store episode files: podcast folder (default) or station media folder (for use in playlists). */
    #[ORM\Column(type: 'string', length: 50, enumType: PodcastEpisodeStorageType::class, options: ['default' => 'podcast'])]
    public PodcastEpisodeStorageType $episode_storage_type = PodcastEpisodeStorageType::Podcast;

    /** Optional subfolder path within station media for this podcast's episodes (e.g. "Radio Shows/MyShow"). Empty = use default podcast_imports/{id}. */
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $media_folder_path = null {
        set => $this->truncateNullableString($value, 500);
    }

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public string $title {
        set => $this->truncateString($value);
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $link = null {
        set => $this->truncateNullableString($value);
    }

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    public string $description {
        set => $this->truncateString($value, 4000);
    }

    #[ORM\Column]
    public bool $explicit;

    #[ORM\Column]
    public bool $is_enabled = true;

    #[ORM\Column(name: 'branding_config', type: 'json', nullable: true)]
    private ?array $branding_config_raw = null;

    public PodcastBrandingConfiguration $branding_config {
        get => new PodcastBrandingConfiguration((array)$this->branding_config_raw);
        set (PodcastBrandingConfiguration|array|null $value) {
            $this->branding_config_raw = PodcastBrandingConfiguration::merge(
                $this->branding_config_raw,
                $value
            );
        }
    }

    #[ORM\Column(length: 2)]
    #[Assert\NotBlank]
    public string $language {
        set => $this->truncateString($value, 2);
    }

    #[ORM\Column(length: 255)]
    public string $author {
        set => $this->truncateString($value);
    }

    #[ORM\Column(length: 255)]
    #[Assert\Email]
    public string $email {
        set => $this->truncateString($value);
    }

    #[ORM\Column]
    #[Attributes\AuditIgnore]
    public int $art_updated_at = 0;

    #[ORM\Column]
    public bool $playlist_auto_publish = true;

    /** @var Collection<int, PodcastCategory> */
    #[ORM\OneToMany(targetEntity: PodcastCategory::class, mappedBy: 'podcast')]
    public private(set) Collection $categories;

    /** @var Collection<int, PodcastEpisode> */
    #[
        ORM\OneToMany(targetEntity: PodcastEpisode::class, mappedBy: 'podcast', fetch: 'EXTRA_LAZY'),
        ORM\OrderBy(['publish_at' => 'DESC'])
    ]
    public private(set) Collection $episodes;

    public function __construct(StorageLocation $storageLocation)
    {
        $this->storage_location = $storageLocation;

        $this->categories = new ArrayCollection();
        $this->episodes = new ArrayCollection();
    }

    public function __clone(): void
    {
        $this->categories = new ArrayCollection();
        $this->episodes = new ArrayCollection();
    }

    public static function getArtPath(string $uniqueId): string
    {
        return self::DIR_PODCAST_ARTWORK . '/' . $uniqueId . '.jpg';
    }
}
