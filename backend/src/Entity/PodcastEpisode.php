<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\PodcastSources;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[
    ORM\Entity,
    ORM\Table(name: 'podcast_episode'),
    Attributes\Auditable
]
final class PodcastEpisode implements IdentifiableEntityInterface
{
    use Traits\HasUniqueId;
    use Traits\TruncateStrings;

    public const string DIR_PODCAST_EPISODE_ARTWORK = '.podcast_episode_art';

    #[ORM\ManyToOne(inversedBy: 'episodes')]
    #[ORM\JoinColumn(name: 'podcast_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Podcast $podcast;

    #[ORM\ManyToOne(inversedBy: 'podcast_episodes')]
    #[ORM\JoinColumn(name: 'playlist_media_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public ?StationMedia $playlist_media = null;

    /* TODO Remove direct identifier access. */
    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $playlist_media_id = null;

    #[ORM\OneToOne(mappedBy: 'episode')]
    public ?PodcastMedia $media = null;

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
    public int $publish_at {
        set(int|null $value) => $value ?? $this->created_at;
    }

    #[ORM\Column]
    public bool $explicit;

    #[ORM\Column(nullable: true)]
    public ?int $season_number;

    #[ORM\Column(nullable: true)]
    public ?int $episode_number;

    #[ORM\Column]
    public int $created_at;

    #[ORM\Column]
    #[Attributes\AuditIgnore]
    public int $art_updated_at = 0;

    /**
     * Original feed enclosure URL when the episode is listed in RSS but audio is not stored locally
     * (default “latest only” import keeps one local file; older episodes use this for enclosure URLs).
     */
    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $remote_enclosure_url = null {
        set => $this->truncateNullableString($value, 2048);
    }

    /** MIME hint from the feed for remote enclosures (RSS enclosure type). */
    #[ORM\Column(length: 127, nullable: true)]
    public ?string $remote_enclosure_mime = null {
        set => $this->truncateNullableString($value, 127);
    }

    public function __construct(Podcast $podcast)
    {
        $this->podcast = $podcast;
        $this->created_at = time();
        $this->publish_at = time();
    }

    public static function getArtPath(string $uniqueId): string
    {
        return self::DIR_PODCAST_EPISODE_ARTWORK . '/' . $uniqueId . '.jpg';
    }

    public function isPublished(): bool
    {
        if ($this->publish_at > time()) {
            return false;
        }

        return match ($this->podcast->source) {
            PodcastSources::Manual => ($this->media !== null),
            PodcastSources::Import => ($this->media !== null) || $this->hasRemoteImportEnclosure(),
            PodcastSources::Playlist => ($this->playlist_media !== null)
        };
    }

    private function hasRemoteImportEnclosure(): bool
    {
        return $this->remote_enclosure_url !== null && $this->remote_enclosure_url !== '';
    }
}
