<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Entity\Enums\PlaylistGroupAllowedRequests;
use App\Utilities\Types;
use JsonSerializable;

final class PlaylistMemberEntry implements JsonSerializable
{
    public function __construct(
        public readonly string $playlistRef,
        public readonly int $weight,
        public readonly int $consecutivePlays,
        public readonly bool $playFullCycle,
        public readonly PlaylistGroupAllowedRequests $allowedRequests,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            playlistRef: Types::string($data['playlist_ref'] ?? null),
            weight: Types::int($data['weight'] ?? null),
            consecutivePlays: Types::int($data['consecutive_plays'] ?? null),
            playFullCycle: Types::bool($data['play_full_cycle'] ?? false),
            allowedRequests: PlaylistGroupAllowedRequests::tryFrom(
                Types::string($data['allowed_requests'] ?? null)
            ) ?? PlaylistGroupAllowedRequests::Any,
        );
    }

    public function jsonSerialize(): mixed
    {
        return [
            'playlist_ref' => $this->playlistRef,
            'weight' => $this->weight,
            'consecutive_plays' => $this->consecutivePlays,
            'play_full_cycle' => $this->playFullCycle,
            'allowed_requests' => $this->allowedRequests->value,
        ];
    }
}
