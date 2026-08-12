<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use OpenApi\Attributes as OA;

/**
 * A single filter rule belonging to a Smart Block (a Songs playlist with
 * `is_smart_block = true`). The set of criteria rows on a playlist, combined via its
 * `smart_block_match_type` (ALL/ANY), determines which tracks from the station's media
 * library are automatically kept in sync as that playlist's membership.
 *
 * This is purely additive to the existing rotation model, in the same spirit as
 * {@see StationPlaylistFolder} and {@see StationPlaylistGroup}: the actual playback,
 * scheduling, rotation-goal, and duplicate-avoidance logic is entirely unchanged. Only
 * *which songs are members of the playlist* is automated, on a recurring sync task,
 * instead of managed by hand.
 */
#[
    OA\Schema(type: "object"),
    ORM\Entity,
    ORM\Table(name: 'station_playlist_smart_block_criteria'),
    Attributes\Auditable
]
final class StationPlaylistSmartBlockCriteria implements JsonSerializable, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'smart_block_criteria')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationPlaylist $playlist;

    /* TODO Remove direct identifier access. */
    #[ORM\Column(nullable: false, insertable: false, updatable: false)]
    public private(set) int $playlist_id;

    #[
        OA\Property(example: 'genre'),
        ORM\Column(type: 'string', length: 25, enumType: SmartBlockCriteriaField::class)
    ]
    public SmartBlockCriteriaField $field = SmartBlockCriteriaField::Genre;

    /**
     * Only set (and only meaningful) when {@see self::$field} is CustomField -- which
     * custom field (e.g. "BPM", "Mood", "Energy") this criterion inspects.
     */
    #[ORM\ManyToOne(fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public ?CustomField $custom_field = null;

    #[
        OA\Property(example: 'is'),
        ORM\Column(type: 'string', length: 25, enumType: SmartBlockCriteriaComparison::class)
    ]
    public SmartBlockCriteriaComparison $comparison = SmartBlockCriteriaComparison::Is;

    #[
        OA\Property(example: 'Rock'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $value = null {
        set => $this->truncateNullableString($value);
    }

    /**
     * Only used when {@see self::$comparison} is Between -- the upper bound of the range
     * (e.g. "140" when filtering BPM between "120" and "140").
     */
    #[
        OA\Property(example: '140'),
        ORM\Column(length: 255, nullable: true)
    ]
    public ?string $value2 = null {
        set => $this->truncateNullableString($value);
    }

    #[
        OA\Property(example: 0),
        ORM\Column
    ]
    public int $weight = 0;

    public function __construct(StationPlaylist $playlist)
    {
        $this->playlist = $playlist;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field->value,
            'custom_field_id' => $this->custom_field?->id,
            'custom_field_name' => $this->custom_field?->name,
            'comparison' => $this->comparison->value,
            'value' => $this->value,
            'value2' => $this->value2,
            'weight' => $this->weight,
        ];
    }
}
