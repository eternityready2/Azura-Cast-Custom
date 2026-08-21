<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Utilities\File;
use App\Utilities\Time;
use Azura\Normalizer\Attributes\DeepNormalize;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Serializer\Attribute as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

#[
    OA\Schema(type: "object"),
    ORM\Entity,
    ORM\Table(name: 'station_playlists'),
    ORM\HasLifecycleCallbacks,
    Attributes\Auditable
]
final class StationPlaylist implements
    Stringable,
    Interfaces\StationAwareInterface,
    Interfaces\StationCloneAwareInterface,
    Interfaces\IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    public const int DEFAULT_WEIGHT = 3;
    public const int DEFAULT_REMOTE_BUFFER = 20;

    public const string OPTION_INTERRUPT_OTHER_SONGS = 'interrupt';
    public const string OPTION_PLAY_SINGLE_TRACK = 'single_track';
    public const string OPTION_MERGE = 'merge';
    public const string OPTION_PRIORITIZE_OVER_REQUESTS = 'prioritize';
    public const string OPTION_ALLOW_OVERRUN = 'allow_overrun';

    #[
        ORM\ManyToOne(inversedBy: 'playlists'),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    /* TODO Remove direct identifier access. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: "Test Playlist"),
        ORM\Column(length: 200),
        Assert\NotBlank
    ]
    public string $name {
        set => $this->truncateString(
            str_replace(';', ':', $value),
            200
        );
    }

    #[
        OA\Property(example: "A playlist containing my favorite songs"),
        ORM\Column(type: 'text', nullable: true)
    ]
    public ?string $description = null;

    #[
        OA\Property(example: "default"),
        ORM\Column(type: 'string', length: 50, enumType: PlaylistTypes::class)
    ]
    public PlaylistTypes $type;

    #[
        OA\Property(example: "songs"),
        ORM\Column(type: 'string', length: 50, enumType: PlaylistSources::class)
    ]
    public PlaylistSources $source {
        set {
            $this->source = $value;

            if (PlaylistSources::RemoteUrl === $value || PlaylistSources::Requests === $value) {
                $this->type = PlaylistTypes::Standard;
            }
        }
    }

    #[
        OA\Property(example: "shuffle"),
        ORM\Column(name: 'playback_order', type: 'string', length: 50, enumType: PlaylistOrders::class)
    ]
    public PlaylistOrders $order;

    /**
     * @deprecated Unused. Smart Shuffle was removed; retained for existing database rows.
     * NULL uses the default (5).
     */
    #[
        OA\Property(example: 5, nullable: true),
        ORM\Column(nullable: true)
    ]
    public ?int $smart_shuffle_distance = null;

    /**
     * Minimum days between repeats of the same track from this playlist (positive rotation goal).
     * NULL disables rotation goal enforcement.
     */
    #[
        OA\Property(example: 7, nullable: true),
        ORM\Column(nullable: true)
    ]
    public ?int $rotation_goal_days = null {
        set (int|string|null $value) {
            if (null === $value || '' === $value) {
                $this->rotation_goal_days = null;
                return;
            }

            $days = (int)$value;
            $this->rotation_goal_days = $days > 0 ? $days : null;
        }
    }

    /**
     * Library Aging: gradually boosts a track's selection priority the longer
     * it goes unplayed, rather than Rotation Goal's hard floor. NULL disables it.
     */
    #[
        OA\Property(example: 14, nullable: true),
        ORM\Column(nullable: true)
    ]
    public ?int $aging_threshold_days = null {
        set (int|string|null $value) {
            if (null === $value || '' === $value) {
                $this->aging_threshold_days = null;
                return;
            }

            $days = (int)$value;
            $this->aging_threshold_days = $days > 0 ? $days : null;
        }
    }

    /**
     * Optional named crossfade profile (see station crossfade_profiles in backend_config).
     */
    #[
        OA\Property(example: 'quick_id', nullable: true),
        ORM\Column(length: 50, nullable: true)
    ]
    public ?string $crossfade_profile = null {
        set (string|null $value) {
            $value = null !== $value ? trim($value) : null;
            $this->crossfade_profile = ('' === $value) ? null : $value;
        }
    }

    #[
        OA\Property(example: "https://remote-url.example.com/stream.mp3"),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $remote_url = null;

    #[
        OA\Property(example: "stream"),
        ORM\Column(type: 'string', length: 25, nullable: true, enumType: PlaylistRemoteTypes::class)
    ]
    public ?PlaylistRemoteTypes $remote_type;

    #[
        OA\Property(
            description: "The total time (in seconds) that Liquidsoap should buffer remote URL streams.",
            example: 0
        ),
        ORM\Column(name: 'remote_timeout', type: 'smallint')
    ]
    public int $remote_buffer = 0;

    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $is_enabled = true;

    #[
        OA\Property(
            description: "If yes, do not send jingle metadata to AutoDJ or trigger web hooks.",
            example: false
        ),
        ORM\Column
    ]
    public bool $is_jingle = false;

    /**
     * Sponsor guaranteed playout: when enabled, this playlist represents a paid
     * sponsor spot that MUST air its guaranteed number of plays per day --
     * never silently skipped by normal rotation/fallback logic, same class of
     * guarantee as the Top of Hour legal ID. Reused for the Sponsor Play Report.
     */
    #[
        OA\Property(example: false),
        ORM\Column(options: ['default' => false])
    ]
    public bool $is_sponsor = false;

    #[
        OA\Property(example: 'Acme Hardware'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $sponsor_name = null {
        set => $this->truncateNullableString($value, 255);
    }

    /** Guaranteed plays per day for this sponsor. NULL = no guarantee tracked. */
    #[
        OA\Property(example: 4, nullable: true),
        ORM\Column(nullable: true)
    ]
    public ?int $sponsor_guaranteed_plays_per_day = null {
        set (int|string|null $value) {
            if (null === $value || '' === $value) {
                $this->sponsor_guaranteed_plays_per_day = null;
                return;
            }
            $days = (int)$value;
            $this->sponsor_guaranteed_plays_per_day = $days > 0 ? $days : null;
        }
    }

    /** Optional contract window -- outside this range, the guarantee is not enforced. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $sponsor_contract_start = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $sponsor_contract_end = null;

    #[
        OA\Property(example: 5),
        ORM\Column(type: 'smallint')
    ]
    public int $play_per_songs = 0;

    #[
        OA\Property(example: 120),
        ORM\Column(type: 'smallint')
    ]
    public int $play_per_minutes = 0;

    #[
        OA\Property(example: 15),
        ORM\Column(type: 'smallint')
    ]
    public int $play_per_hour_minute = 0 {
        set {
            if ($value > 59 || $value < 0) {
                $value = 0;
            }

            $this->play_per_hour_minute = $value;
        }
    }

    #[
        OA\Property(
            description: "The relative weight of the playlist. Larger numbers play more often than playlists "
            . "with lower number weights.",
            example: 3,
        ),
        ORM\Column(type: 'smallint')
    ]
    public int $weight = self::DEFAULT_WEIGHT {
        get => ($this->weight >= 1) ? $this->weight : self::DEFAULT_WEIGHT;
    }

    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $include_in_requests = true;

    #[
        OA\Property(
            description: "Whether this playlist's media is included in 'on demand' download/streaming if enabled.",
            example: true
        ),
        ORM\Column
    ]
    public bool $include_in_on_demand = false;

    #[ORM\Column(name: 'backend_options', length: 255, nullable: true)]
    private ?string $backend_options_raw = '';

    #[OA\Property(
        items: new OA\Items(type: 'string'),
        example: "interrupt,loop_once,single_track,merge"
    )]
    public array $backend_options {
        get => explode(',', $this->backend_options_raw ?? '');
        set {
            $this->backend_options_raw = implode(',', array_filter($value));
        }
    }

    public function backendInterruptOtherSongs(): bool
    {
        return in_array(self::OPTION_INTERRUPT_OTHER_SONGS, $this->backend_options, true);
    }

    public function backendMerge(): bool
    {
        return in_array(self::OPTION_MERGE, $this->backend_options, true);
    }

    public function backendPlaySingleTrack(): bool
    {
        return in_array(self::OPTION_PLAY_SINGLE_TRACK, $this->backend_options, true);
    }

    public function backendPrioritizeOverRequests(): bool
    {
        return in_array(self::OPTION_PRIORITIZE_OVER_REQUESTS, $this->backend_options, true);
    }

    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $avoid_duplicates = true;

    /**
     * Smart Block: when enabled (only valid for `source = songs` playlists), this
     * playlist's membership is not managed by hand -- it is automatically kept in sync
     * with the station's media library based on its {@see self::$smart_block_criteria}
     * rules by a recurring background task. Everything else about the playlist (weight,
     * scheduling, rotation goal, duplicate avoidance, etc.) works exactly as normal.
     */
    #[
        OA\Property(example: false),
        ORM\Column(options: ['default' => false])
    ]
    public bool $is_smart_block = false;

    #[
        OA\Property(example: 'all'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockMatchType::class, options: ['default' => 'all'])
    ]
    public SmartBlockMatchType $smart_block_match_type = SmartBlockMatchType::All;

    /**
     * Optional cap on how much content the Smart Block will keep as members at once,
     * measured either in track count or total duration depending on
     * {@see self::$smart_block_limit_type} (e.g. "50 tracks" or "3600 seconds"). If more
     * matching content exists than this, a random sample is kept and refreshed on every
     * sync. NULL means no cap (all matching tracks are included).
     */
    #[
        OA\Property(example: 50, nullable: true),
        ORM\Column(nullable: true)
    ]
    public ?int $smart_block_limit = null {
        set (int|string|null $value) {
            if (null === $value || '' === $value) {
                $this->smart_block_limit = null;
                return;
            }

            $limit = (int)$value;
            $this->smart_block_limit = $limit > 0 ? $limit : null;
        }
    }

    /**
     * Whether {@see self::$smart_block_limit} counts tracks or seconds of duration --
     * mirrors LibreTime/Airtime's "Limit to X items" vs "Limit to X time" choice.
     */
    #[
        OA\Property(example: 'tracks'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockLimitType::class, options: ['default' => 'tracks'])
    ]
    public SmartBlockLimitType $smart_block_limit_type = SmartBlockLimitType::Tracks;

    /**
     * Airtime Pro's Static vs Dynamic distinction (see {@see SmartBlockType}).
     * Static: criteria generate a one-time, hand-editable tracklist -- the recurring
     * sync task leaves it alone once generated. Dynamic: membership is continuously
     * kept in sync with the criteria, including being resolved fresh at the moment
     * AutoDJ is about to play from it (see QueueBuilder).
     */
    #[
        OA\Property(example: 'dynamic'),
        ORM\Column(type: 'string', length: 10, enumType: SmartBlockType::class, options: ['default' => 'dynamic'])
    ]
    public SmartBlockType $smart_block_type = SmartBlockType::Dynamic;

    /**
     * The order in which matching tracks are weighted when the Smart Block syncer
     * builds the playlist. Because AutoDJ plays in weight order, this directly controls
     * on-air playback order for Sequential playlists and the initial shuffle seed for
     * Shuffle/Random playlists.
     */
    #[
        OA\Property(example: 'random'),
        ORM\Column(type: 'string', length: 20, enumType: SmartBlockSortOrder::class, options: ['default' => 'random'])
    ]
    public SmartBlockSortOrder $smart_block_sort_order = SmartBlockSortOrder::Random;

    /**
     * When false, the Smart Block syncer will not add a track that is already a member
     * of this playlist -- effectively preventing repeated tracks in small libraries.
     * When true (default), all matching tracks are eligible regardless of current membership.
     *
     * Note: this is distinct from the playlist-level $avoid_duplicates flag (which is an
     * AutoDJ duplicate-prevention feature). This flag controls whether the syncer itself
     * re-adds tracks that are already in the block.
     */
    #[
        OA\Property(example: true),
        ORM\Column(options: ['default' => true])
    ]
    public bool $smart_block_avoid_duplicates = true;

    #[
        ORM\Column(type: 'datetime_immutable', precision: 6, nullable: true),
        Attributes\AuditIgnore
    ]
    public ?DateTimeImmutable $played_at = null {
        set (DateTimeImmutable|string|int|null $value) => Time::toNullableUtcCarbonImmutable($value);
    }

    #[
        ORM\Column(type: 'datetime_immutable', precision: 6, nullable: true),
        Attributes\AuditIgnore
    ]
    public ?DateTimeImmutable $queue_reset_at = null {
        set (DateTimeImmutable|string|int|null $value) => Time::toNullableUtcCarbonImmutable($value);
    }

    /** @var Collection<int, StationPlaylistMedia> */
    #[
        ORM\OneToMany(targetEntity: StationPlaylistMedia::class, mappedBy: 'playlist', fetch: 'EXTRA_LAZY'),
        ORM\OrderBy(['weight' => 'ASC'])
    ]
    public private(set) Collection $media_items;

    /** @var Collection<int, StationPlaylistFolder> */
    #[
        ORM\OneToMany(targetEntity: StationPlaylistFolder::class, mappedBy: 'playlist', fetch: 'EXTRA_LAZY')
    ]
    public private(set) Collection $folders;

    /**
     * The filter rules for this playlist's Smart Block, if {@see self::$is_smart_block}
     * is enabled. See {@see StationPlaylistSmartBlockCriteria} for details.
     *
     * @var Collection<int, StationPlaylistSmartBlockCriteria>
     */
    #[
        OA\Property(type: "array", items: new OA\Items()),
        ORM\OneToMany(
            targetEntity: StationPlaylistSmartBlockCriteria::class,
            mappedBy: 'playlist',
            fetch: 'EXTRA_LAZY',
            cascade: ['persist', 'remove'],
            orphanRemoval: true
        ),
        ORM\OrderBy(['weight' => 'ASC']),
        DeepNormalize(true),
        Serializer\MaxDepth(1)
    ]
    public private(set) Collection $smart_block_criteria;

    /** @var Collection<int, StationSchedule> */
    #[
        OA\Property(type: "array", items: new OA\Items()),
        ORM\OneToMany(targetEntity: StationSchedule::class, mappedBy: 'playlist', fetch: 'EXTRA_LAZY'),
        DeepNormalize(true),
        Serializer\MaxDepth(1)
    ]
    public private(set) Collection $schedule_items;

    /** @var Collection<int, Podcast> */
    #[
        OA\Property(type: "array", items: new OA\Items()),
        ORM\OneToMany(targetEntity: Podcast::class, mappedBy: 'playlist', fetch: 'EXTRA_LAZY'),
        DeepNormalize(true),
        Serializer\MaxDepth(1)
    ]
    public private(set) Collection $podcasts;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    public int $group_next_position = 0;

    /**
     * If this playlist has `source = group` (a "Playlist Group" / clock wheel), this is the
     * flat, explicitly-ordered set of its member playlists. Replaces the old nested/tree
     * `StationPlaylistGroup` model, which was prone to losing track of what should play next
     * once a nested group finished (the source of the AutoDJ playback-continuation looping bug).
     *
     * @var Collection<int, StationPlaylistGroupMember>
     */
    #[
        ORM\OneToMany(
            targetEntity: StationPlaylistGroupMember::class,
            mappedBy: 'group',
            fetch: 'EXTRA_LAZY'
        ),
        ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])
    ]
    public private(set) Collection $group_members;

    /**
     * Raw membership rows for the Playlist Groups (clock wheels) that this playlist belongs to.
     *
     * @var Collection<int, StationPlaylistGroupMember>
     */
    #[
        ORM\OneToMany(
            targetEntity: StationPlaylistGroupMember::class,
            mappedBy: 'playlist',
            fetch: 'EXTRA_LAZY'
        )
    ]
    public private(set) Collection $group_memberships;

    public function __construct(Station $station)
    {
        $this->station = $station;

        $this->type = PlaylistTypes::default();
        $this->source = PlaylistSources::Songs;
        $this->order = PlaylistOrders::Shuffle;
        $this->remote_type = PlaylistRemoteTypes::Stream;

        $this->media_items = new ArrayCollection();
        $this->folders = new ArrayCollection();
        $this->smart_block_criteria = new ArrayCollection();
        $this->schedule_items = new ArrayCollection();
        $this->podcasts = new ArrayCollection();
        $this->group_members = new ArrayCollection();
        $this->group_memberships = new ArrayCollection();
    }

    /**
     * Indicates whether this playlist can be used as a valid source of requestable media.
     */
    public function isRequestable(): bool
    {
        return ($this->is_enabled && $this->include_in_requests);
    }

    /**
     * Indicates whether a playlist is enabled and has content which can be scheduled by an AutoDJ scheduler.
     *
     * @param bool $interrupting Whether determining "playability" for an interrupting queue or a regular one.
     */
    public function isPlayable(bool $interrupting = false): bool
    {
        if (!$this->is_enabled) {
            return false;
        }

        if ($interrupting !== $this->backendInterruptOtherSongs()) {
            return false;
        }

        if (PlaylistSources::Requests === $this->source) {
            return true;
        }

        if (PlaylistSources::Group === $this->source) {
            return $this->group_members->count() > 0;
        }

        if (PlaylistSources::Songs === $this->source) {
            return $this->media_items->count() > 0;
        }

        // Remote stream playlists aren't supported by the AzuraCast AutoDJ.
        return PlaylistRemoteTypes::Playlist === $this->remote_type;
    }

    public function __clone()
    {
        $this->played_at = null;
        $this->queue_reset_at = null;

        $this->media_items = new ArrayCollection();
        $this->folders = new ArrayCollection();
        $this->smart_block_criteria = new ArrayCollection();
        $this->schedule_items = new ArrayCollection();
        $this->podcasts = new ArrayCollection();
        $this->group_members = new ArrayCollection();
        $this->group_memberships = new ArrayCollection();
        $this->group_next_position = 0;
    }

    public function __toString(): string
    {
        return $this->station . ' Playlist: ' . $this->name;
    }

    public static function generateShortName(string $str): string
    {
        $str = File::sanitizeFileName($str);

        return (is_numeric($str))
            ? 'playlist_' . $str
            : $str;
    }
}
