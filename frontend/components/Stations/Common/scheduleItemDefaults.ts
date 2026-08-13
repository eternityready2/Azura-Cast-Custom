export interface PlaylistScheduleRow {
    start_time: number,
    end_time: number,
    start_date: string,
    end_date: string,
    days: number[],
    loop_once: boolean,
    /** When true, this window overrides clock wheel AutoDJ. */
    is_emergency?: boolean,
    /** Playlist schedule only: holds rigidly to start time, cutting the current track if needed. */
    strict_start?: boolean,
    /** Clock wheel schedule only: flexible | strict (not loop_once). */
    clock_wheel_mode?: 'flexible' | 'strict',
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
    const startDate = todayDate();

    return {
        start_time: 800,
        end_time: 900,
        start_date: startDate,
        // One-time events are locked to a single day; default End Date to match
        // Start Date immediately rather than leaving it blank until something
        // else (e.g. buildSchedulePayload's fallback) patches it in later.
        end_date: startDate,
        days: [],
        loop_once: false,
        is_emergency: false,
        strict_start: false,
        clock_wheel_mode: 'flexible',
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
