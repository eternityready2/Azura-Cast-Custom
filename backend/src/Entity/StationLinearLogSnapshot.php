<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Last successfully built Linear Log snapshot for a station.
 *
 * The station association is the primary key because there is exactly one
 * durable snapshot per station. Transient queued/building/failed state remains
 * cache backed; this row is only replaced after a successful build.
 */
#[
    ORM\Entity,
    ORM\Table(name: 'station_linear_log_snapshots'),
]
final class StationLinearLogSnapshot
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $snapshot = [];

    #[ORM\Column]
    public int $updated_at = 0;

    public function __construct(Station $station)
    {
        $this->station = $station;
    }
}
