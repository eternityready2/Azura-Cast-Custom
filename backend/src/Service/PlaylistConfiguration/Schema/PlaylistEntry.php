<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration\Schema;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Enums\SmartBlockType;
use App\Utilities\Types;
use JsonSerializable;

final class PlaylistEntry implements JsonSerializable
{
    /**
     * @param string[] $backendOptions
     * @param PlaylistFolderEntry[] $folders
     * @param PlaylistMediaEntry[] $media
     * @param PlaylistScheduleEntry[] $schedules
     * @param PlaylistMemberEntry[] $members
     * @param PlaylistSmartBlockCriterionEntry[] $smartBlockCriteria
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly PlaylistTypes $type,
        public readonly PlaylistSources $source,
        public readonly PlaylistOrders $order,
        public readonly int $weight,
        public readonly bool $isEnabled,
        public readonly bool $isJingle,
        public readonly bool $avoidDuplicates,
        public readonly bool $includeInRequests,
        public readonly bool $includeInOnDemand,
        public readonly int $playPerSongs,
        public readonly int $playPerMinutes,
        public readonly int $playPerHourMinute,
        public readonly array $backendOptions,
        public readonly ?string $remoteUrl,
        public readonly ?PlaylistRemoteTypes $remoteType,
        public readonly int $remoteBuffer,
        public readonly ?string $description,
        public readonly ?int $rotationGoalDays = null,
        public readonly ?int $agingThresholdDays = null,
        public readonly ?string $crossfadeProfile = null,
        public readonly bool $isSponsor = false,
        public readonly ?string $sponsorName = null,
        public readonly ?int $sponsorGuaranteedPlaysPerDay = null,
        public readonly ?string $sponsorContractStart = null,
        public readonly ?string $sponsorContractEnd = null,
        public readonly bool $isSmartBlock = false,
        public readonly SmartBlockMatchType $smartBlockMatchType = SmartBlockMatchType::All,
        public readonly ?int $smartBlockLimit = null,
        public readonly SmartBlockLimitType $smartBlockLimitType = SmartBlockLimitType::Tracks,
        public readonly SmartBlockType $smartBlockType = SmartBlockType::Dynamic,
        public readonly SmartBlockSortOrder $smartBlockSortOrder = SmartBlockSortOrder::Random,
        public readonly bool $smartBlockAvoidDuplicates = true,
        public array $folders = [],
        public array $media = [],
        public array $schedules = [],
        public array $members = [],
        public array $smartBlockCriteria = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $config = Types::array($data['config'] ?? []);
        $remoteType = Types::stringOrNull($config['remote_type'] ?? null);

        return new self(
            ref: Types::string($data['ref'] ?? null),
            name: Types::string($data['name'] ?? null),
            type: PlaylistTypes::from(Types::string($config['type'] ?? null)),
            source: PlaylistSources::from(Types::string($config['source'] ?? null)),
            order: PlaylistOrders::from(Types::string($config['order'] ?? null)),
            weight: Types::int($config['weight'] ?? null),
            isEnabled: Types::bool($config['is_enabled'] ?? null),
            isJingle: Types::bool($config['is_jingle'] ?? null),
            avoidDuplicates: Types::bool($config['avoid_duplicates'] ?? null),
            includeInRequests: Types::bool($config['include_in_requests'] ?? null),
            includeInOnDemand: Types::bool($config['include_in_on_demand'] ?? null),
            playPerSongs: Types::int($config['play_per_songs'] ?? null),
            playPerMinutes: Types::int($config['play_per_minutes'] ?? null),
            playPerHourMinute: Types::int($config['play_per_hour_minute'] ?? null),
            backendOptions: array_map('strval', Types::array($config['backend_options'] ?? [])),
            remoteUrl: Types::stringOrNull($config['remote_url'] ?? null),
            remoteType: ($remoteType !== null) ? PlaylistRemoteTypes::tryFrom($remoteType) : null,
            remoteBuffer: Types::int($config['remote_buffer'] ?? null),
            description: Types::stringOrNull($config['description'] ?? null),
            rotationGoalDays: Types::intOrNull($config['rotation_goal_days'] ?? null),
            agingThresholdDays: Types::intOrNull($config['aging_threshold_days'] ?? null),
            crossfadeProfile: Types::stringOrNull($config['crossfade_profile'] ?? null),
            isSponsor: Types::bool($config['is_sponsor'] ?? false),
            sponsorName: Types::stringOrNull($config['sponsor_name'] ?? null),
            sponsorGuaranteedPlaysPerDay: Types::intOrNull($config['sponsor_guaranteed_plays_per_day'] ?? null),
            sponsorContractStart: Types::stringOrNull($config['sponsor_contract_start'] ?? null),
            sponsorContractEnd: Types::stringOrNull($config['sponsor_contract_end'] ?? null),
            isSmartBlock: Types::bool($config['is_smart_block'] ?? false),
            smartBlockMatchType: SmartBlockMatchType::tryFrom(Types::string($config['smart_block_match_type'] ?? 'all'))
                ?? SmartBlockMatchType::All,
            smartBlockLimit: Types::intOrNull($config['smart_block_limit'] ?? null),
            smartBlockLimitType: SmartBlockLimitType::tryFrom(Types::string($config['smart_block_limit_type'] ?? 'tracks'))
                ?? SmartBlockLimitType::Tracks,
            smartBlockType: SmartBlockType::tryFrom(Types::string($config['smart_block_type'] ?? 'dynamic'))
                ?? SmartBlockType::Dynamic,
            smartBlockSortOrder: SmartBlockSortOrder::tryFrom(Types::string($config['smart_block_sort_order'] ?? 'random'))
                ?? SmartBlockSortOrder::Random,
            smartBlockAvoidDuplicates: Types::bool($config['smart_block_avoid_duplicates'] ?? true),
            folders: array_map(
                static fn(mixed $item): PlaylistFolderEntry => PlaylistFolderEntry::fromArray(Types::array($item)),
                Types::array($data['folders'] ?? [])
            ),
            media: array_map(
                static fn(mixed $item): PlaylistMediaEntry => PlaylistMediaEntry::fromArray(Types::array($item)),
                Types::array($data['media'] ?? [])
            ),
            schedules: array_map(
                static fn(mixed $item): PlaylistScheduleEntry => PlaylistScheduleEntry::fromArray(Types::array($item)),
                Types::array($data['schedules'] ?? [])
            ),
            members: array_map(
                static fn(mixed $item): PlaylistMemberEntry => PlaylistMemberEntry::fromArray(Types::array($item)),
                Types::array($data['members'] ?? [])
            ),
            smartBlockCriteria: array_map(
                static fn(mixed $item): PlaylistSmartBlockCriterionEntry => PlaylistSmartBlockCriterionEntry::fromArray(Types::array($item)),
                Types::array($data['smart_block_criteria'] ?? [])
            ),
        );
    }

    public function jsonSerialize(): mixed
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'config' => [
                'type' => $this->type->value,
                'source' => $this->source->value,
                'order' => $this->order->value,
                'weight' => $this->weight,
                'is_enabled' => $this->isEnabled,
                'is_jingle' => $this->isJingle,
                'avoid_duplicates' => $this->avoidDuplicates,
                'include_in_requests' => $this->includeInRequests,
                'include_in_on_demand' => $this->includeInOnDemand,
                'play_per_songs' => $this->playPerSongs,
                'play_per_minutes' => $this->playPerMinutes,
                'play_per_hour_minute' => $this->playPerHourMinute,
                'backend_options' => array_values(array_filter($this->backendOptions)),
                'remote_url' => $this->remoteUrl,
                'remote_type' => $this->remoteType?->value,
                'remote_buffer' => $this->remoteBuffer,
                'description' => $this->description,
                'rotation_goal_days' => $this->rotationGoalDays,
                'aging_threshold_days' => $this->agingThresholdDays,
                'crossfade_profile' => $this->crossfadeProfile,
                'is_sponsor' => $this->isSponsor,
                'sponsor_name' => $this->sponsorName,
                'sponsor_guaranteed_plays_per_day' => $this->sponsorGuaranteedPlaysPerDay,
                'sponsor_contract_start' => $this->sponsorContractStart,
                'sponsor_contract_end' => $this->sponsorContractEnd,
                'is_smart_block' => $this->isSmartBlock,
                'smart_block_match_type' => $this->smartBlockMatchType->value,
                'smart_block_limit' => $this->smartBlockLimit,
                'smart_block_limit_type' => $this->smartBlockLimitType->value,
                'smart_block_type' => $this->smartBlockType->value,
                'smart_block_sort_order' => $this->smartBlockSortOrder->value,
                'smart_block_avoid_duplicates' => $this->smartBlockAvoidDuplicates,
            ],
            'folders' => $this->folders,
            'media' => $this->media,
            'schedules' => $this->schedules,
            'members' => $this->members,
            'smart_block_criteria' => $this->smartBlockCriteria,
        ];
    }
}
