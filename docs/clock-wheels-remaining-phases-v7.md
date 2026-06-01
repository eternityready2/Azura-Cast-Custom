# Clock Wheels — Remaining Phases (v7 PDF)

Source: `docs/AzuraCast_ClockWheel_Remaining_Phases_v7.pdf`  
**Scope:** PR7 and PR9–PR13 only. PR1–PR8 are treated as complete in that document.

This file maps each remaining phase to **this repo**, notes **gaps vs the PDF**, and defines a **implementation order** while waiting for client feedback on PR1 (migrations) and PR6 (tests).

---

## Foundation already in this repo (do not break)

| Area | Status | Key files |
|------|--------|-----------|
| Schema + `position_seconds` | Done | `Version20260519120000`, `StationClockWheelSlot` |
| Schedule conflicts | Done | `ScheduleConflictChecker`, calendar + API |
| Hour timeline / planner | Done | `ClockWheelPlaybackPlanner`, `Entries.vue` timeline bar |
| Scheduler + AutoDJ queue | Done | `ClockWheelScheduler`, `ClockWheelAnnotator` |
| Tests (PR6) | Done | `tests/Unit/ClockWheel*`, `ScheduleConflictCheckerTest`, functional Cest |
| PR8 enforcement | Done | `clock_wheel_enforce_cap`, `cue_out` via `ClockWheelAnnotator` |

See `docs/clock-wheels.md` for operational detail, tests, and monitoring.

---

## PDF vs repo — honesty check

| PDF claim | This repo |
|-----------|-----------|
| PR1–PR6 complete | **Mostly true** — tests exist; PR1 `position_seconds` restored by `Version20260519120000` (see client thread) |
| PR8 complete | **True** |
| PR7 “zero backend changes” | **Partially true** — tab can use existing queue/nowplaying/schedule APIs; PDF also references **`GET .../preview`** and **`clock_wheel_events`** which **do not exist yet** (PR12 / PR11) |
| PR5 preview endpoint | **Not found** in routes — no `/clock-wheel/.../preview` |
| PR11 `clock_wheel_events` | **Implemented** — `clock_wheel_events` table + `ClockWheelEventLogger` hooks |
| PR9 `SeparationRulesChecker` | **Not implemented** (duplicate prevention exists globally, not wheel-specific separation/burn rate) |
| PR10 `ClockTemplate` / `ClockInstance` / `Daypart` | **Not implemented** |
| PR13 `is_emergency` | **Not implemented** |

**Architectural rule from PDF:** Do not change PR1–PR8 core behavior (fit-to-window, calendar authority, AutoDJ fallback). New work **extends** on top.

---

## Remaining phases (summary)

| PR | Name | Priority | Size | Backend | Frontend |
|----|------|----------|------|---------|----------|
| **7** | Schedule page — Live Clock Wheel tab | High | Medium | None required for MVP | New tab on `Schedule.vue` |
| **9** | Separation rules + burn rate | High | Medium | `SeparationRulesChecker` + planner hook | Config UI (wheel/template later) |
| **10** | Daypart clock inheritance | High | Medium | New entities + migrations + APIs | Templates / dayparts / instances UI |
| **11** | Audit event database | High | Small | **Done** — `clock_wheel_events` + `ClockWheelEventLogger` | — |
| **12** | Preview simulator + analytics | Medium | Medium | Preview API + metrics queries | Dashboard + fill_strategy |
| **13** | Emergency override (optional) | Optional | Small | `is_emergency` on `StationSchedule` | Checkbox in `CreateEventModal` |

---

## Dependency order (from PDF)

```text
PR7  ─────────────────────────────┐
PR9  ─────────────────────────────┤  parallel (after PR1–8 stable)
PR10 ─────────────────────────────┤
PR13 ─────────────────────────────┘  optional, anytime after PR6

PR11 ──► PR12   (audit table before analytics/preview)
```

**Recommended implementation sequence:**

1. **PR7 (MVP)** — Done.
2. **PR11** — Done (`clock_wheel_events` + logger hooks).
3. **PR9** — Extends `ClockWheelPlaybackPlanner` candidate filtering.
4. **PR12** — Preview endpoint + analytics UI (depends on PR11).
5. **PR10** — Largest product surface (templates/dayparts); can start DB/API in parallel with PR9 if staffed.
6. **PR13** — Quick win when needed for ops.

---

## PR7 — Schedule page: Live Clock Wheel tab

**Goal:** Read-only broadcast ops view on the Schedule page: circular clock face, now playing, current-hour slots with **real track names**, next hour / future wheels, conflict heads-up.

### APIs available today (no new backend)

| Data | API / source |
|------|----------------|
| Now playing (title, artist, clock wheel name) | Station now playing / public NP (`Profile/NowPlayingPanel.vue` patterns) |
| Upcoming queue rows | `GET /api/station/{id}/queue` (`Queue.vue`) |
| Calendar events (playlists + wheels) | `GET .../playlists/schedule`, `GET .../clock-wheels/schedule` (`Schedule.vue`) |
| Wheel detail + slots | `GET .../clock-wheel/{id}`, slots sub-resource |
| Active wheel on schedule | Compare current time to schedule feed + `Scheduler` rules (client-side or reuse schedule JSON) |

### PDF dependencies not in repo yet

| PDF reference | Workaround for PR7 MVP |
|---------------|-------------------------|
| `GET .../preview` (PR5/PR12) | Show **queued** `StationQueue` rows for the hour; label “planned” vs “projected” |
| `clock_wheel_events` (PR11) | Omit “pre-generated audit” list until PR11; use queue only |
| Conflict from PR2 | Derive from schedule feed overlaps or dedicated conflict endpoint if added later |

### Implementation sketch

- **Done (MVP):** Tabbed UI on `Schedule.vue`, `Schedule/ClockWheelLiveTab.vue`, `useClockWheelLiveData.ts`.
- New component e.g. `frontend/components/Stations/Schedule/ClockWheelLiveTab.vue`:
  - CSS clock hand from station TZ + `position_seconds` on active wheel slots.
  - Poll now playing + queue every ~10s.
  - Resolve active wheel from schedule + `is_active`.
- **Do not** change `ClockWheels/EditModal` or slot editor.

### Done when (PDF)

Live hand in station TZ; now playing shows track + artist; current hour shows queued titles; next/future wheels listed; conflict warning when playlist/streamer wins.

---

## PR9 — Separation rules + burn rate protection

**Goal:** `App\Radio\AutoDJ\SeparationRulesChecker` — artist/title/category/tempo/gender/decade separation + playlist burn rate; daypart overrides (ties to PR10); relaxation cascade with logging.

**Integration point:** After `filterByDuration` / before algorithm in `ClockWheelPlaybackPlanner::resolveSlot` (or shared candidate pipeline).

**Uses:** `StationQueueRepository::getRecentlyPlayedByTimeRange()` with extended lookback.

**Depends on PR11 for:** `separation_relaxed`, `burn_rate_warning` flags on audit rows.

**Tests:** Unit tests for separation windows, cascade order, burn deprioritization, never-empty fallback.

---

## PR10 — Daypart clock inheritance

**Goal:** `ClockTemplate`, `ClockInstance`, `Daypart` entities — edit one template, propagate to hours; instance overrides; daypart auto-generates hour instances.

**Largest remaining schema/UI effort.** Independent of planner math but changes how wheels are authored.

**Migrations:** New tables + FKs to `station`, `station_clock_wheels` (or replace direct wheel-per-hour model — design carefully to avoid breaking existing `StationClockWheel` API).

---

## PR11 — Audit event database

**Status: Done**

**Goal:** Table `clock_wheel_events` with columns from PDF (timestamp, wheel_id, slot_id, media_id, expected/actual time, drift_seconds, anchor_type, fallback_reason, separation_relaxed, burn_rate_warning).

**Implemented:**

| Piece | Location |
|-------|----------|
| Migration | `Version20260529120000` → table `clock_wheel_events` |
| Entity | `App\Entity\ClockWheelEvent` |
| Logger | `App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger` |
| Hooks | `ClockWheelPlaybackPlanner` (queue / defer / slot fallbacks), `ClockWheelScheduler` (schedule conflict, inactive wheel) |
| Tests | `tests/Unit/ClockWheelEventLoggerTest.php` |

`separation_relaxed` / `burn_rate_warning` default to `false` until PR9. `actual_play_at` reserved for a future playback hook (PR12).

**Verify on server:**

```sql
SELECT event_kind, fallback_reason, anchor_type, drift_seconds, event_timestamp
FROM clock_wheel_events
WHERE station_id = YOUR_STATION_ID
ORDER BY id DESC
LIMIT 20;
```

**Unblocks:** PR12 analytics, richer PR7 live tab, PR9/PR13 logging flags.

---

## PR12 — Preview simulator + analytics dashboard

**Goal:**

- `GET .../preview` — next-hour projection (titles, drift, separation/burn warnings).
- Analytics dashboard from `clock_wheel_events` (accuracy, missed hard anchors, fallbacks, drift, burn rate, etc.).
- Wheel-level `fill_strategy` enum: `conservative` | `aggressive`.

**Note:** PDF scopes preview to **next hour only** (not full week).

---

## PR13 — Emergency override (optional)

**Goal:** `is_emergency` on `StationSchedule`; `ClockWheelScheduler` yields when emergency schedule active; log `fallback_reason = emergency_override`; UI checkbox with warning.

**PDF:** Boolean only — no numeric priority stack.

---

## What not to build (PDF — do not override without approval)

- Hard-cut music mid-song (use cue_out / defer / strict rules already in PR8).
- Same-hour slot wrap (fall through to AutoDJ).
- Numeric priority stacks (use PR13 boolean).
- Multi-station live clock sharing (export/import copies only).
- Reopen Liquidsoap unless PR12 proves drift in production.
- Sub-second sync guarantees.

---

## Suggested work while waiting for PR1 & PR6 client feedback

| Safe to start now | Wait for client sign-off |
|-------------------|---------------------------|
| PR7 UI spike (mock data → wire APIs) | PR1 migration narrative on prod |
| PR11 schema + migration draft | PR6 “tests sufficient” for merge |
| PR13 design (single column + scheduler guard) | Any PR10 entity design approval |
| Doc + API inventory for PR12 preview | |

**Avoid starting** PR10 full implementation until inheritance model is agreed (biggest data model change).

---

## Verification commands (existing)

```bash
# Unit tests (clock wheel + conflicts)
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Unit/ClockWheel* tests/Unit/ScheduleConflict*"

# Functional clock wheel API
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Functional/Api_Stations_ClockWheelsCest.php"

# Confirm position_seconds column
docker compose exec web bash -c \
  "mysql -e \"SHOW COLUMNS FROM station_clock_wheel_slots LIKE 'position_seconds';\" azuracast"
```

---

## Client messaging (remaining phases)

> PR1–PR8 foundation is in branch with automated tests (PR6) and migration `Version20260519120000` for `position_seconds`. We have reviewed **Remaining Phases v7** and queued **PR7 → PR11 → PR9 → PR12 → PR10 → PR13**. PR7 can start as frontend-only against existing queue/nowplaying/schedule APIs; preview/audit features land with PR11/PR12 per the PDF dependency order.
