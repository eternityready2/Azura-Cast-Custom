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
    #[OA\Property(description: 'Whether Scheduled Boundary Protection is enabled.')]
    public bool $scheduled_boundary_enabled;

    #[OA\Property(description: 'How many seconds before a strict scheduled start boundary protection may intervene.')]
    public float $scheduled_boundary_window_seconds;

    #[OA\Property(description: 'Whether interrupting audio ducks the underlying program audio.')]
    public bool $interrupting_duck_enabled;

    #[OA\Property(description: 'Program-audio level while interrupting audio is playing, from 0.0 to 1.0.')]
    public float $interrupting_duck_attenuation;

    #[OA\Property(description: 'Ducking fade time in seconds.')]
    public float $interrupting_duck_delay;

    #[OA\Property(description: 'Whether scheduled-boundary intervention uses the station crossfade transition.')]
    public bool $boundary_transition_uses_station_crossfade;

    #[OA\Property(description: 'Whether ducking changes require a broadcasting restart to reach Liquidsoap.')]
    public bool $ducking_requires_broadcast_restart;

    public function __construct(StationBackendConfiguration $config)
    {
        $this->scheduled_boundary_enabled = $config->top_of_hour_hard_trigger_enabled;
        $this->scheduled_boundary_window_seconds = $config->top_of_hour_hard_trigger_seconds;
        $this->interrupting_duck_enabled = $config->top_of_hour_duck_enabled;
        $this->interrupting_duck_attenuation = $config->top_of_hour_duck_attenuation;
        $this->interrupting_duck_delay = $config->top_of_hour_duck_delay;
        $this->boundary_transition_uses_station_crossfade = true;
        $this->ducking_requires_broadcast_restart = true;
    }
}
