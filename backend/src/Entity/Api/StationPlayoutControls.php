<?php

declare(strict_types=1);

namespace App\Entity\Api;

use App\Entity\StationBackendConfiguration;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Api_StationPlayoutControls',
    required: ['*'],
    type: 'object'
)]
final readonly class StationPlayoutControls
{
    private const bool DEFAULT_STRETCH_SQUEEZE_ENABLED = true;

    private const float DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT = 5.0;

    #[OA\Property]
    public bool $hard_clock_enabled;

    #[OA\Property]
    public float $hard_clock_trigger_seconds;

    #[OA\Property]
    public float $hard_clock_fade_seconds;

    #[OA\Property]
    public bool $stretch_squeeze_enabled;

    #[OA\Property]
    public float $stretch_squeeze_max_percent;

    #[OA\Property]
    public bool $smart_duck_enabled;

    #[OA\Property]
    public float $smart_duck_attenuation;

    #[OA\Property]
    public float $smart_duck_delay;

    public function __construct(StationBackendConfiguration $config)
    {
        $rawConfig = $config->toArray(true) ?? [];

        $this->hard_clock_enabled = $config->top_of_hour_hard_trigger_enabled;
        $this->hard_clock_trigger_seconds = $config->top_of_hour_hard_trigger_seconds;
        $this->hard_clock_fade_seconds = $config->top_of_hour_hard_trigger_fade;
        $this->stretch_squeeze_enabled = (bool)(
            $rawConfig['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_STRETCH_SQUEEZE_ENABLED
        );
        $this->stretch_squeeze_max_percent = (float)(
            $rawConfig['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT
        );
        $this->smart_duck_enabled = $config->top_of_hour_duck_enabled;
        $this->smart_duck_attenuation = $config->top_of_hour_duck_attenuation;
        $this->smart_duck_delay = $config->top_of_hour_duck_delay;
    }
}
