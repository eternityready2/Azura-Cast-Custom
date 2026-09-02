export interface PlayoutControlsSettings {
    scheduled_boundary_enabled: boolean;
    scheduled_boundary_window_seconds: number;
    interrupting_duck_enabled: boolean;
    interrupting_duck_attenuation: number;
    interrupting_duck_delay: number;
    boundary_transition_uses_station_crossfade?: boolean;
    ducking_requires_broadcast_restart?: boolean;
}
