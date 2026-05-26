export interface PlaylistScheduleRow {
    start_time: number,
    end_time: number,
    start_date: string,
    end_date: string,
    days: number[],
    loop_once: boolean,
    recurrence_type: string | null,
    recurrence_interval: number,
    recurrence_monthly_pattern: string | null,
    recurrence_monthly_day: number | null,
    recurrence_monthly_week: number | null,
    recurrence_monthly_day_of_week: number | null,
    recurrence_end_type: string,
    recurrence_end_after: number | null,
    recurrence_end_date: string | null,
}

export function todayDate(): string {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function createScheduleItemDefaults(): PlaylistScheduleRow {
    return {
        start_time: 800,
        end_time: 900,
        start_date: todayDate(),
        end_date: '',
        days: [],
        loop_once: false,
        recurrence_type: null,
        recurrence_interval: 1,
        recurrence_monthly_pattern: null,
        recurrence_monthly_day: null,
        recurrence_monthly_week: null,
        recurrence_monthly_day_of_week: null,
        recurrence_end_type: 'never',
        recurrence_end_after: null,
        recurrence_end_date: null,
    };
}
