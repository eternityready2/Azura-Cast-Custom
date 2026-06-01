<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log for clock wheel scheduling decisions (PR11).
 *
 * Used for ops dashboards (PR12) and post-mortems. Rows are not shown in the
 * calendar; schedule authority remains {@see StationSchedule}.
 */
#[
    ORM\Entity(repositoryClass: Repository\ClockWheelEventRepository::class),
    ORM\Table(name: 'clock_wheel_events'),
    ORM\Index(name: 'idx_cwe_station_timestamp', columns: ['station_id', 'event_timestamp']),
    ORM\Index(name: 'idx_cwe_wheel_timestamp', columns: ['clock_wheel_id', 'event_timestamp']),
]
final class ClockWheelEvent implements IdentifiableEntityInterface, StationAwareInterface
{
    use Traits\HasAutoIncrementId;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'clock_wheel_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationClockWheel $clock_wheel = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $clock_wheel_id = null;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationClockWheelSlot $slot = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $slot_id = null;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')
    ]
    public ?StationMedia $media = null;

    #[ORM\Column(nullable: true, insertable: false, updatable: false)]
    public private(set) ?int $media_id = null;

    #[ORM\Column(type: 'string', length: 32, enumType: ClockWheelEventKind::class)]
    public ClockWheelEventKind $event_kind;

    #[ORM\Column(type: 'string', length: 48, nullable: true, enumType: ClockWheelFallbackReason::class)]
    public ?ClockWheelFallbackReason $fallback_reason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $event_timestamp;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $expected_play_at = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?DateTimeImmutable $actual_play_at = null;

    /** Seconds between expected anchor and playhead position when the event was recorded. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    public ?int $drift_seconds = null;

    /** Slot content type at decision time (music, id, talk, etc.). */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $anchor_type = null;

    #[ORM\Column]
    public bool $separation_relaxed = false;

    #[ORM\Column]
    public bool $burn_rate_warning = false;

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }
}
