/** Seconds in a broadcast hour (matches backend anchor range). */
export const CLOCK_WHEEL_HOUR_SECONDS = 3600;

export interface ClockWheelTimelineEntry {
    position_seconds: number;
}

export function formatClockWheelPosition(totalSeconds: number): string {
    const clamped = Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, totalSeconds));
    const mins = Math.floor(clamped / 60);
    const secs = clamped % 60;
    return `${mins}:${String(secs).padStart(2, '0')}`;
}

export function parseClockWheelPosition(value: string): number {
    const trimmed = value.trim();
    if (trimmed === '') {
        return 0;
    }

    const parts = trimmed.split(':');
    if (parts.length !== 2) {
        return 0;
    }

    const mins = parseInt(parts[0], 10);
    const secs = parseInt(parts[1], 10);
    if (Number.isNaN(mins) || Number.isNaN(secs)) {
        return 0;
    }

    return Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, mins * 60 + secs));
}

export function sortClockWheelEntries<T extends ClockWheelTimelineEntry>(entries: T[]): void {
    entries.sort((a, b) => a.position_seconds - b.position_seconds);
}

/**
 * After drag-reorder, keep anchor times but assign them to rows in the new visual order.
 */
export function applyDragOrderToPositions<T extends ClockWheelTimelineEntry>(entries: T[]): void {
    const positions = entries.map((e) => e.position_seconds).sort((a, b) => a - b);
    entries.forEach((entry, index) => {
        entry.position_seconds = positions[index] ?? entry.position_seconds;
    });
    sortClockWheelEntries(entries);
}

export function timelinePercent(positionSeconds: number): number {
    return (Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, positionSeconds)) / CLOCK_WHEEL_HOUR_SECONDS) * 100;
}

export interface ClockWheelTimelineWarning {
    index: number;
    message: string;
}

export function getClockWheelTimelineWarnings(
    entries: ClockWheelTimelineEntry[],
    gettext: (msg: string) => string,
): ClockWheelTimelineWarning[] {
    const warnings: ClockWheelTimelineWarning[] = [];
    const sorted = [...entries].sort((a, b) => a.position_seconds - b.position_seconds);

    for (let i = 0; i < sorted.length; i++) {
        const entry = sorted[i];
        const next = sorted[i + 1];

        if (next && next.position_seconds === entry.position_seconds) {
            const index = entries.indexOf(entry);
            warnings.push({
                index,
                message: gettext('Another anchor uses the same time.'),
            });
        }

        if (next) {
            const gap = next.position_seconds - entry.position_seconds;
            if (gap > 0 && gap < 10) {
                const index = entries.indexOf(entry);
                warnings.push({
                    index,
                    message: gettext('Very little time before the next anchor.'),
                });
            }
        }
    }

    return warnings;
}

