export interface TopOfHourCompliance {
    tolerance_seconds: number;
    hours_with_legal_id: number;
    on_time_count: number;
    late_count: number;
    compliance_percent: number | null;
    fallback_count: number;
}

export interface TopOfHourMediaSummary {
    id: number;
    title: string | null;
    artist: string | null;
}

export interface TopOfHourNextPlan {
    mode: 'hard_toh' | 'soft_etm';
    boundary_at: string;
    target_start_at: string;
    duration_seconds: number;
    rigid_zero_event: boolean;
    media: TopOfHourMediaSummary;
}

export interface TopOfHourSettings {
    top_of_hour_id_enabled: boolean;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_id_max_seconds: number;
    id_media_count: number;
    compliance?: TopOfHourCompliance;
    next: TopOfHourNextPlan | null;
    engine: 'broadcast_clock';
}

export interface TopOfHourForm {
    top_of_hour_id_enabled: boolean;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_id_max_seconds: number;
}
