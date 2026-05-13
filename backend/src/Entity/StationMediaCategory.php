<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use App\Entity\Interfaces\StationAwareInterface;
use Doctrine\ORM\Mapping as ORM;
use OpenApi\Attributes as OA;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

#[
    OA\Schema(type: 'object'),
    ORM\Entity,
    ORM\Table(name: 'station_media_categories')
]
final class StationMediaCategory implements
    Stringable,
    StationAwareInterface,
    IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne,
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    public Station $station;

    public function __construct(Station $station)
    {
        $this->station = $station;
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $station_id;

    #[
        OA\Property(example: 'Cinematic'),
        ORM\Column(length: 100, nullable: false),
        Assert\NotBlank,
        Assert\Length(max: 100)
    ]
    public string $name = '' {
        set => $this->truncateString($value, 100);
    }

    #[
        OA\Property(example: '#e87722'),
        ORM\Column(length: 7, nullable: false, options: ['default' => '#6366f1']),
        Assert\NotBlank,
        Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')
    ]
    public string $color = '#6366f1' {
        set => $this->truncateString($value, 7);
    }

    public function __toString(): string
    {
        return 'StationMediaCategory: ' . $this->name;
    }
}
