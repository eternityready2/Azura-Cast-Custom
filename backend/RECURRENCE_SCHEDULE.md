# Advanced Recurrence Schedule Implementation

This document describes the backend-only recurrence feature for AzuraCast schedule items (playlists and streamers).

## Database

Run the migration to add recurrence columns to `station_schedules`:

```bash
docker exec -it azuracast bash
cd /var/eternityready2/Azura-Cast-Custom_src
php bin/console migrations:migrate --no-interaction
```

Migration: `Version20250315120000` adds:

- `recurrence_type` (nullable: weekly, biweekly, monthly, custom)
- `recurrence_interval` (int, default 1 – e.g. 2 = every 2 weeks)
- `recurrence_monthly_pattern` (nullable: date, day_of_week)
- `recurrence_monthly_day` (1–31 for date pattern)
- `recurrence_monthly_week` (1–4 or 5 for “last”)
- `recurrence_monthly_day_of_week` (1=Mon … 7=Sun)
- `recurrence_end_type` (never, after, on_date)
- `recurrence_end_after` (int, when end_type = after)
- `recurrence_end_date` (Y-m-d, when end_type = on_date)

## API: Schedule Item Payload

When creating/updating a playlist or streamer, `schedule_items` can include recurrence fields. Existing fields (`start_time`, `end_time`, `start_date`, `end_date`, `days`, `loop_once`) are unchanged.

### Example: Bi-weekly every Saturday at 14:00

```json
{
  "start_time": 1400,
  "end_time": 1400,
  "start_date": "2025-03-15",
  "days": [6],
  "recurrence_type": "biweekly",
  "recurrence_interval": 2,
  "recurrence_end_type": "never"
}
```

### Example: Every other weekend (Sat + Sun every 2 weeks)

```json
{
  "start_time": 0,
  "end_time": 2359,
  "start_date": "2025-03-22",
  "days": [6, 7],
  "recurrence_type": "biweekly",
  "recurrence_interval": 2,
  "recurrence_end_type": "never"
}
```

### Example: 1st and 3rd Monday at 19:00

```json
{
  "start_time": 1900,
  "end_time": 1900,
  "recurrence_type": "monthly",
  "recurrence_monthly_pattern": "day_of_week",
  "recurrence_monthly_week": 1,
  "recurrence_monthly_day_of_week": 1,
  "recurrence_end_type": "never"
}
```

(Repeat with `recurrence_monthly_week`: 3 for 3rd Monday, or send two schedule items.)

### Example: 15th of every month at noon

```json
{
  "start_time": 1200,
  "end_time": 1200,
  "recurrence_type": "monthly",
  "recurrence_monthly_pattern": "date",
  "recurrence_monthly_day": 15,
  "recurrence_end_type": "never"
}
```

### Example: Every 3 weeks on Tue/Thu, end after 10 times

```json
{
  "start_time": 800,
  "end_time": 1000,
  "start_date": "2025-03-18",
  "days": [2, 4],
  "recurrence_type": "custom",
  "recurrence_interval": 3,
  "recurrence_end_type": "after",
  "recurrence_end_after": 10
}
```

### Example: End on a specific date

```json
{
  "recurrence_end_type": "on_date",
  "recurrence_end_date": "2025-12-31"
}
```

## Backward Compatibility

- If `recurrence_type` is omitted or null, behavior is unchanged (weekly on `days`, optional `start_date`/`end_date`).
- Existing schedule rows get NULL/defaults for new columns and continue to work.

## Testing Checklist

1. **Bi-weekly Saturday** – Create schedule with `recurrence_type: "biweekly"`, `start_date` on a Saturday, `days: [6]`. Check API schedule and AutoDJ only play on alternate Saturdays.
2. **Every other weekend** – Same with `days: [6, 7]`, confirm both days every 2 weeks.
3. **1st/3rd Monday** – Monthly day_of_week pattern; confirm only 1st and 3rd Mondays.
4. **15th of month** – Monthly date pattern; confirm only on 15th (and last day for short months when day > 28).
5. **Every 3 weeks** – Custom interval; confirm correct spacing from `start_date`.
6. **End after N** – Set `recurrence_end_after: 5`; confirm only 5 occurrences.
7. **End on date** – Set `recurrence_end_date`; confirm no occurrences after that date.
8. **Existing schedules** – Ensure existing playlists/streamers without recurrence still play as before.
9. **Liquidsoap** – Restart backend after changing recurrence; confirm scheduled play matches API.

## Admin Frontend

The AzuraCast admin UI includes recurrence in the schedule form for both **Playlists** and **Streamers**:

- **Playlists:** `frontend/components/Stations/Playlists/Form/Schedule.vue`, `ScheduleRow.vue`; load normalization in `EditModal.vue`.
- **Streamers:** `frontend/components/Stations/Streamers/Form/Schedule.vue`, `ScheduleRow.vue`; load normalization in `EditModal.vue`.

Each schedule item has a "Recurrence" section: type (Weekly / Bi-weekly / Monthly / Custom), optional interval, monthly pattern (day of month or weekday), and end condition (Never / After N / On date). New items default to Weekly; existing items with `null` recurrence are normalized to Weekly when loaded.

## Files Touched (Backend)

- `src/Entity/Migration/Version20250315120000.php` – migration
- `src/Entity/StationSchedule.php` – new properties + enums
- `src/Entity/Enums/RecurrenceType.php`, `RecurrenceMonthlyPattern.php`, `RecurrenceEndType.php`
- `src/Utilities/ScheduleRecurrence.php` – occurrence calculation
- `src/Radio/AutoDJ/Scheduler.php` – uses recurrence for “play on date”
- `src/Entity/Repository/StationScheduleRepository.php` – setScheduleItems, getUpcomingSchedule, validation
- `src/Controller/Api/Traits/HasScheduleDisplay.php` – getEvents uses recurrence
- `src/Radio/Backend/Liquidsoap/ConfigWriter.php` – recurrence → timestamp windows for Liquidsoap

## Timezone

All recurrence and schedule logic uses the **station timezone** (e.g. America/Chicago). Start/end times and dates are interpreted in that zone.
