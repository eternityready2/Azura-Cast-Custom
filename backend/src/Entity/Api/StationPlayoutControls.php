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
    #[OA\Property]
    public bool $hard_clock_enabled;

    #[OA\Property]
    public float $hard_clock_trigger_seconds;

    #[OA\Property]
    public float $hard_clock_fade_seconds;

    #[OA\Property]
    public bool $smart_duck_enabled;

    #[OA\Property]
    public float $smart_duck_attenuation;

    #[OA\Property]
    public float $smart_duck_delay;

    public function __construct(StationBackendConfiguration $config)
    {
        $this->hard_clock_enabled = $config->top_of_hour_hard_trigger_enabled;
        $this->hard_clock_trigger_seconds = $config->top_of_hour_hard_trigger_seconds;
        $this->hard_clock_fade_seconds = $config->top_of_hour_hard_trigger_fade;
        $this->smart_duck_enabled = $config->top_of_hour_duck_enabled;
        $this->smart_duck_attenuation = $config->top_of_hour_duck_attenuation;
        $this->smart_duck_delay = $config->top_of_hour_duck_delay;
    }
}
