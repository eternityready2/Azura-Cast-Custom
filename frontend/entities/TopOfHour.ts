export interface TopOfHourCompliance {
    tolerance_seconds: number;
    hours_with_legal_id: number;
    on_time_count: number;
    late_count: number;
    compliance_percent: number | null;
    fallback_count: number;
}

export interface TopOfHourSettings {
    top_of_hour_id_enabled: boolean;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_id_max_seconds: number;
    top_of_hour_duck_enabled: boolean;
    top_of_hour_duck_attenuation: number;
    top_of_hour_duck_delay: number;
    id_media_count?: number;
    legal_id_media_count: number;
    compliance?: TopOfHourCompliance;
}
