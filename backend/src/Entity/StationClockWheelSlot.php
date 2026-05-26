<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single content block inside a StationClockWheel.
 *
 * Each slot occupies a position in the broadcast hour and tells Liquidsoap
 * which category of content to play and how to select a track from it.
 *
 * Design decisions:
 *
 * - `clock_wheel` ManyToOne with CASCADE on the DB side (via the parent's
 *   OneToMany cascade + orphanRemoval) — no duplicate cascade needed here.
 *
 * - `type` (ClockWheelSlotTypes enum): the content category.
 *   Stored as a string enum so the DB column is human-readable and does not
 *   break when new types are added (unlike integer-mapped enums).
 *
 * - `playlist` (nullable ManyToOne to StationPlaylist): optionally pins a slot
 *   to a specific AzuraCast playlist. When null the generator picks from all
 *   playlists that match the slot's type, preserving flexible rotation.
 *   SET NULL on delete: if the playlist is deleted the slot survives, it just
 *   becomes type-based rather than playlist-specific.
 *
 * - `algorithm` (ClockWheelSlotAlgorithms enum): controls track selection order.
 *   Defaults to Random, which is the safest choice for live broadcast because
 *   it cannot get "stuck" on a single track if the playlist runs short.
 *
 * - `position_seconds` (0–3599): anchor time within the broadcast hour (seconds
 *   from top of hour). The generator selects the active slot as the latest
 *   anchor whose position_seconds is less than or equal to the current second
 *   into the hour.
 *
 * - `slot_order` (smallint, default 0): tie-breaker when two slots share the
 *   same position_seconds. The API accepts the full ordered list and writes new
 *   order values on save.
 *
 * - `duration_seconds` (nullable smallint): the intended length of this slot in
 *   seconds. NULL means "no hard limit — play one track and move on". When set,
 *   the Liquidsoap generator wraps the slot source in a `max_duration` so that
 *   the clock stays on schedule even if the content runs long.
 *   Radio reality: ID/Promo/Ad slots have known fixed durations; music slots
 *   are usually left null so a song plays to its natural end.
 *
 * - No `station_id` denormalisation: the station is always reachable via
 *   clock_wheel.station, keeping the schema normalised. If a query needs to
 *   filter by station it JOINs through clock_wheels — this is the same pattern
 *   used by StationPlaylistMedia (via StationPlaylist).
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_wheel_slots')
]
final class StationClockWheelSlot implements IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    // ------------------------------------------------------------------
    // Parent relationship
    // ------------------------------------------------------------------

    #[
        ORM\ManyToOne(targetEntity: StationClockWheel::class, inversedBy: 'slots'),
        ORM\JoinColumn(name: 'clock_wheel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public StationClockWheel $clock_wheel;

    /** Raw FK — read-only for API serialisation. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $clock_wheel_id;

    // ------------------------------------------------------------------
    // Optional playlist pin
    // ------------------------------------------------------------------

    /**
     * When set, this slot always draws from this specific playlist.
     * When null, the Liquidsoap generator selects from all playlists whose
     * purpose matches the slot type (e.g. all "ID" playlists for an Id slot).
     */
    #[
        ORM\ManyToOne(targetEntity: StationPlaylist::class),
        ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationPlaylist $playlist = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $playlist_id = null;

    // ------------------------------------------------------------------
    // Content type & selection algorithm
    // ------------------------------------------------------------------

    #[
        OA\Property(example: 'music', nullable: true),
        ORM\Column(type: 'string', length: 20, nullable: true, enumType: ClockWheelSlotTypes::class)
    ]
    public ?ClockWheelSlotTypes $type = ClockWheelSlotTypes::Music;

    /**
     * Optional media category filter. When set, only tracks assigned
     * to this category (and matching the type) will be considered.
     */
    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationMediaCategory $category = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $category_id = null;

    #[
        OA\Property(example: 'random'),
        ORM\Column(type: 'string', length: 30, enumType: ClockWheelSlotAlgorithms::class)
    ]
    public ClockWheelSlotAlgorithms $algorithm = ClockWheelSlotAlgorithms::Random;

    // ------------------------------------------------------------------
    // Scheduling fields
    // ------------------------------------------------------------------

    /**
     * Seconds from the top of the hour (0–3599) when this slot's content should start.
     */
    #[
        OA\Property(example: 0),
        ORM\Column(type: 'smallint', options: ['unsigned' => true]),
        Assert\Range(min: 0, max: 3599)
    ]
    public int $position_seconds = 0;

    /**
     * Tie-breaker when multiple slots share the same position_seconds.
     */
    #[
        OA\Property(example: 0),
        ORM\Column(type: 'smallint')
    ]
    public int $slot_order = 0;

    /**
     * Soft duration cap in seconds.
     * NULL = play one complete track, no time constraint.
     * Positive value = the generator wraps this slot in a max_duration operator
     * so it yields back to the next slot after at most this many seconds.
     *
     * Practical values:
     *   - Music block:  null (let the song finish)
     *   - ID/Sweeper:   15–30
     *   - Promo:        30–60
     *   - Ad:           30–60
     */
    #[
        OA\Property(example: 30, nullable: true),
        ORM\Column(type: 'smallint', nullable: true),
        Assert\PositiveOrZero
    ]
    public ?int $duration_seconds = null;

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function __construct(StationClockWheel $clockWheel)
    {
        $this->clock_wheel = $clockWheel;
    }
}
