export type LinearLogStatus = "idle" | "queued" | "building" | "ready" | "failed";

export interface LinearLogItem {
    id: string;
    queue_id: number;
    song_id: string;
    played_at: number | null;
    cued_at: number;
    duration: number;
    title: string | null;
    artist: string | null;
    album: string | null;
    text: string | null;
    playlist: string | null;
    playlist_id: number | null;
    playlist_chain: string[] | null;
    clock_wheel: string | null;
    clock_wheel_id: number | null;
    media_type: string;
    source_type: string;
    is_request: boolean;
    is_live_queue: boolean;
    sent_to_autodj: boolean;
    top_of_hour_legal_id: boolean;
    autodj_custom_uri: string | null;
    clock_wheel_schedule_mode: string | null;
    clock_wheel_enforce_cap: boolean;
    clock_wheel_stretch_ratio: number | null;
    clock_wheel_legal_id_substitute: boolean;
    hour_boundary_enforce_cap: boolean;
    hour_boundary_max_play_seconds: number | null;
    top_of_hour_pre_id_fade: boolean;
}

export interface LinearLogGap {
    started_at: number;
    duration: number;
    reason: string;
}

export interface LinearLogAiDjShift {
    schedule_id: number;
    schedule_name: string;
    dj_id: number;
    dj_name: string;
    starts_at: number;
    ends_at: number;
}

export interface LinearLogResponse {
    status: LinearLogStatus;
    enabled: boolean;
    hours: number;
    configured_hours: number;
    built_at: number | null;
    coverage_start: number | null;
    coverage_end: number | null;
    entries: LinearLogItem[];
    gaps: LinearLogGap[];
    ai_dj_shifts: LinearLogAiDjShift[];
    error: string | null;
}

export interface LinearLogHourGroup {
    epochHour: number;
    label: string;
    isCurrent: boolean;
    items: LinearLogItem[];
    totalDurationFormatted: string;
    hasId: boolean;
}
