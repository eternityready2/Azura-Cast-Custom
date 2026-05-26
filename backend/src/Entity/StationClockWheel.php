<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Attributes\Auditable;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use App\Entity\StationSchedule;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Clock Wheel defines a named, reusable template that describes what content
 * should play and in what order during a given programming segment (typically one hour).
 *
 * Design decisions:
 *
 * - One wheel per station: the station_id FK with CASCADE delete ensures wheels
 *   are cleaned up automatically when a station is removed, matching the pattern
 *   used by StationPlaylist and StationPodcast.
 *
 * - `name` (max 100 chars): short display identifier, e.g. "Morning Drive".
 *   Intentionally shorter than StationPlaylist (200) because clock wheel names
 *   appear as labels in scheduler views where space is tight.
 *
 * - `color` (7-char hex): stored as the canonical CSS hex string (#rrggbb).
 *   This lets the frontend render a colour swatch without any conversion.
 *   Validated by a Regex constraint so bad data cannot reach the DB.
 *
 * - `is_active`: mirrors the Active flag on StationPlaylist. An inactive wheel
 *   is preserved in the database (history, reactivation) but is excluded from
 *   Liquidsoap generation and the scheduler's available-wheels list.
 *
 * - `slots` (OneToMany, ordered by `slot_order`): the ordered list of content
 *   blocks that make up the wheel. Cascade PERSIST/REMOVE so that saving or
 *   deleting a wheel also saves/removes its slots in one transaction.
 *   `orphanRemoval: true` ensures slots deleted from the collection are actually
 *   removed from the DB — no orphan rows.
 */
#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_clock_wheels'),
    ORM\HasLifecycleCallbacks,
    Auditable
]
final class StationClockWheel implements
    Stringable,
    StationAwareInterface,
    IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    // ------------------------------------------------------------------
    // Station relationship
    // ------------------------------------------------------------------

    #[
        ORM\ManyToOne(inversedBy: 'clock_wheels'),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    /** Raw FK column — read-only, used by API serialisation only. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    // ------------------------------------------------------------------
    // Core fields
    // ------------------------------------------------------------------

    #[
        OA\Property(example: 'Morning Drive'),
        ORM\Column(length: 100),
        Assert\NotBlank,
        Assert\Length(max: 100)
    ]
    public string $name {
        set => $this->truncateString(trim($value), 100);
    }

    /**
     * Colour swatch displayed in the UI and optionally in the schedule grid.
     * Stored as a 7-character lowercase hex string, e.g. "#e87722".
     * Defaults to a vibrant orange that is clearly visible on dark backgrounds.
     */
    #[
        OA\Property(example: '#e87722'),
        ORM\Column(length: 7),
        Assert\NotBlank,
        Assert\Regex(
            pattern: '/^#[0-9a-fA-F]{6}$/',
            message: 'Color must be a valid 6-digit hex color, e.g. #e87722.'
        )
    ]
    public string $color = '#e87722';

    /**
     * Whether the wheel is available for scheduling and Liquidsoap generation.
     * Inactive wheels remain in the database so their configuration is not lost.
     */
    #[
        OA\Property(example: true),
        ORM\Column
    ]
    public bool $is_active = true;

    // ------------------------------------------------------------------
    // Slots collection
    // ------------------------------------------------------------------

    /**
     * Ordered list of content slots that make up this wheel.
     *
     * Using `orderBy: ['slot_order' => 'ASC']` on the ORM side means that
     * any code fetching the wheel's slots always gets them in broadcast order
     * without needing an explicit ORDER BY in every query.
     */
    #[
        ORM\OneToMany(
            targetEntity: StationClockWheelSlot::class,
            mappedBy: 'clock_wheel',
            cascade: ['persist', 'remove'],
            orphanRemoval: true,
            fetch: 'EXTRA_LAZY'
        ),
        ORM\OrderBy(['position_seconds' => 'ASC', 'slot_order' => 'ASC'])
    ]
    public private(set) Collection $slots;

    /**
     * Station schedule items that assign this wheel to time slots.
     */
    #[
        ORM\OneToMany(
            targetEntity: StationSchedule::class,
            mappedBy: 'clock_wheel',
            cascade: ['remove'],
            orphanRemoval: true,
            fetch: 'EXTRA_LAZY'
        )
    ]
    public private(set) Collection $schedule_items;

    public function __construct(Station $station)
    {
        $this->station         = $station;
        $this->slots           = new ArrayCollection();
        $this->schedule_items  = new ArrayCollection();
    }

    // ------------------------------------------------------------------
    // Slot management helpers
    // ------------------------------------------------------------------

    public function addSlot(StationClockWheelSlot $slot): void
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->clock_wheel = $this;
        }
    }

    public function removeSlot(StationClockWheelSlot $slot): void
    {
        $this->slots->removeElement($slot);
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return $this->name;
    }
}
