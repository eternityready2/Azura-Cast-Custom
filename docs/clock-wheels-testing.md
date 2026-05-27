# Clock Wheels — Browser Testing Guide

This guide walks through manual QA of the Clock Wheels feature in the AzuraCast admin UI, with a concrete sample hour you can copy. Use it after deploying to a VPS or local Docker environment.

For feature design and implementation status, see [clock-wheels.md](./clock-wheels.md).

## Before you start

- Log in as a station administrator.
- Pick one test station (for example station `1`).
- Note the station **time zone** (shown on the Schedule page).
- Ensure the media library has files with types set (**Music**, **ID**, **Promo**, **Ad**, **Talk**) or at least one **playlist** with tracks.
- Optional: keep **Admin → System Logs** or SSH queue logs open for playback verification.

Replace `https://your-host` and `/station/1` with your real base URL and station ID.

---

## Sample format clock (ordered list)

Use this configuration for a wheel named **Test Drive Hour**:

| # | Type  | Algorithm |
|---|-------|-----------|
| 1 | ID    | Random    |
| 2 | Music | Random    |
| 3 | Ad    | Random    |
| 4 | Music | Random    |
| 5 | Promo | Random    |
| 6 | ID    | Random    |

- **Color:** any visible swatch (for example `#e87722`)
- **Active:** on (scheduling is done on the Schedule page — see Test 3)

---

## Test 1 — Create clock wheel (ordered entries)

**URL:** `https://your-host/station/1/clock-wheels`

1. Open **Add Clock Wheel**.
2. On **Basic Info**:
   - Title: `Test Drive Hour`
   - Pick a color
   - Leave **Active** enabled
3. Add the six rows from the table (**Type or Category**, **Algorithm**).
4. **Drag** a row using the `⋮⋮` handle — order changes (row numbers update).
5. Use **Duplicate** and **Insert after** on a row.
6. Save.

**Pass:** The wheel appears in the list. Reopening it shows the same entry order (no timeline bar).

---

## Test 2 — Create active wheel without calendar (allowed)

1. Create another wheel: `Unscheduled Test`, **Active** on.
2. Save (no schedule tab on this form).

**Pass:** Save succeeds. The wheel appears in the list but does **not** run on-air until Test 3.

---

## Test 3 — Schedule the wheel on the station calendar

**URL:** `https://your-host/station/1/schedule`

1. Click **Create Event**.
2. **Source:** Clock Wheel → select **Test Drive Hour** (or **Unscheduled Test**).
3. Add a window, for example:
   - **Start time:** 10:00
   - **End time:** 11:00
   - **Start date / End date:** today through +30 days
   - **Days:** weekdays you want to test (or only today’s weekday for a quick run)
   - **Repeat:** Weekly
4. Save.

**Pass:** A colored block appears on the calendar for the wheel. Clicking it opens the clock wheel editor (entries only — not scheduling).

---

## Test 4 — Schedule conflict (no overlap)

**URL:** `https://your-host/station/1/schedule`

1. Try to schedule **another** clock wheel or a **playlist** for **10:30–11:30** on the same day(s) as **Test Drive Hour**.

**Pass:** Save is **blocked** with a conflict message.

2. Schedule a playlist for **11:00–12:00** (adjacent, not overlapping).

**Pass:** Save succeeds (boundary: one event ends when the next starts).

---

## Test 5 — Media mapping (Files / library)

**URL:** `https://your-host/station/1/files`

1. Confirm the library has media matching wheel slot types (music, short IDs, promos, ads).
2. In the wheel editor, optionally set one slot to a **category** (if categories are configured) and save.

**Pass:** The slot saves. At runtime, only media matching the slot type/category (and optional playlist pin) is considered. See Test 7 logs if nothing plays.

---

## Test 6 — Wheel inactive outside its schedule

1. Ensure **Test Drive Hour** is scheduled only for a fixed window (for example 10:00–11:00).
2. Ensure no overlapping **playlist** is scheduled for the current hour when testing outside that window.
3. Outside 10:00–11:00 (station time), confirm normal AutoDJ behavior — the wheel should not override the queue.

**Pass:** The clock wheel does not take over outside its scheduled window.

---

## Test 7 — Wheel active inside schedule (playback)

During **10:00–11:00** in the station time zone:

1. Remove or avoid overlapping **playlist** or **streamer** schedules for that hour (the wheel defers when they are active).
2. Restart the station or reload AutoDJ if the UI offers it, so the queue rebuilds.
3. Check logs on the server:

```bash
cd /var/azuracast
docker compose exec web sh -lc 'grep -i "clock wheel" /var/azuracast/www_tmp/*.log 2>/dev/null | tail -30'
```

**Pass — log lines may include:**

- `Clock Wheel "Test Drive Hour" is active`
- `Clock Wheel sequential slot selection` with `play_index`, `active_slot_order`, `remaining_block_seconds`
- `Clock Wheel resolved track` with `play_length`

4. Over several queue builds, entries should follow list order (1 → 2 → … → 6 → 1 …) until the schedule block ends.

**Pass:** Sequential rotation; playback stops when the calendar block ends; if no media fits, logs show fallback to normal AutoDJ.

---

## Test 8 — Playlist priority over wheel

1. Schedule a **playlist** for the same hour as the clock wheel (for example 10:00–11:00).

   Note: saving overlapping items should be **blocked** at the API (Test 4). To observe runtime deferral, use a window where a playlist is legitimately active per your schedule rules, or verify the log message when a playlist is active.

2. When a non-clock-wheel schedule is active, check logs for:

   `Clock Wheel skipped: another scheduled playlist or streamer is active.`

**Pass:** Scheduled playlists/streamers take priority; the wheel does not override them.

---

## Test 9 — Regression smoke test

| Area              | URL                          | Check                          |
|-------------------|------------------------------|--------------------------------|
| Playlists         | `/station/1/playlists`       | Create/edit still works        |
| Schedule playlists| `/station/1/schedule`      | Playlist events still display  |
| Media             | `/station/1/files`           | Upload/play still works        |
| Public player     | Public station page          | Stream still plays             |

---

## Quick checklist (30 minutes)

| # | Test                         | Expected result                    |
|---|------------------------------|------------------------------------|
| 1 | Create wheel + 6 entries     | Saves; drag order preserved        |
| 2 | Active, no schedule          | Saves (schedule on calendar later) |
| 3 | Schedule 10:00–11:00         | Calendar event; click opens edit |
| 4 | Overlapping event            | Conflict error                     |
| 5 | Media types in library       | Tracks resolve (see logs)          |
| 6 | Outside scheduled window     | Normal AutoDJ                      |
| 7 | Inside window, no playlist   | Wheel logs + sequential picks      |
| 8 | Playlist active same hour    | Wheel skipped in logs              |

---

## Sample “play block” script (operator)

For a schedule block **10:00–11:00** (any length works the same way):

| Order in wheel | Expect (repeats until 11:00) |
|----------------|------------------------------|
| 1 ID           | ID / sweeper                 |
| 2 Music        | Music track (cut if block ends mid-track) |
| 3 Ad           | Ad                           |
| …              | …                            |
| 6 ID           | ID, then back to row 1       |

Timing depends on track length and AutoDJ queue interval. Logs show `play_index` and `active_slot_order`.

---

## Troubleshooting

| Symptom                         | What to check |
|---------------------------------|---------------|
| Wheel never runs                | Wheel **active**? Schedule covers **now** in station TZ? Overlapping playlist/streamer? |
| Save blocked                    | Schedule conflict on calendar Create Event |
| Wrong entry order               | Drag rows before save; reopen wheel to verify `slot_order` |
| No ID/promo in rotation         | Library missing that **type**; empty slot → fallback (see logs) |
| `azuracast_update` DB timeout   | Run `docker compose exec web azuracast_update` on the **running** container, not `docker compose run` while the main container is up |

---

## Related docs

- [clock-wheels.md](./clock-wheels.md) — feature goals, phases, and implementation notes
- [DEPLOYMENT.md](../DEPLOYMENT.md) — release and production update flow
