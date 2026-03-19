/**
 * ISO weekdays 1 = Monday … 7 = Sunday (AzuraCast / station_schedules).
 * Coerces form/API values to unique sorted integers so schedule matching works
 * (avoids string/number mismatches and duplicates).
 */
export default function normalizeStationScheduleDays(days: unknown): number[] {
    if (!Array.isArray(days)) {
        return [];
    }
    const nums = days
        .map((d) => Number(d))
        .filter((d) => Number.isFinite(d) && d >= 1 && d <= 7);
    return [...new Set(nums)].sort((a, b) => a - b);
}
