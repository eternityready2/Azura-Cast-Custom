<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Utilities\Types;
use JsonSerializable;

/**
 * Portable representation of one Smart Block criterion.
 *
 * Custom fields are referenced by name rather than database ID so exports can
 * be moved between installations where custom-field IDs are different.
 */
final class PlaylistSmartBlockCriterionEntry implements JsonSerializable
{
    public function __construct(
        public readonly string $field,
        public readonly string $comparison,
        public readonly ?string $value,
        public readonly ?string $value2,
        public readonly int $weight,
        public readonly ?string $customFieldName = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            field: Types::string($data['field'] ?? null),
            comparison: Types::string($data['comparison'] ?? null),
            value: Types::stringOrNull($data['value'] ?? null),
            value2: Types::stringOrNull($data['value2'] ?? null),
            weight: Types::int($data['weight'] ?? 0),
            customFieldName: Types::stringOrNull($data['custom_field_name'] ?? null),
        );
    }

    public function jsonSerialize(): mixed
    {
        return [
            'field' => $this->field,
            'comparison' => $this->comparison,
            'value' => $this->value,
            'value2' => $this->value2,
            'weight' => $this->weight,
            'custom_field_name' => $this->customFieldName,
        ];
    }
}
