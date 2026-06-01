# Clock Wheels (Format Clocks) – Project Document

This document describes the **targets**, **phases**, **current implementation status**, and **next steps** for the Clock Wheels feature in this AzuraCast custom codebase.

## Targets (what the feature must do)

### Broadcast / Programming goals
- **Build 60‑minute format clocks** that place content anchors inside the hour (IDs, ads, promos, sweepers, music, talk, etc.).
- **Strict structure, flexible content**:
  - Structure: anchors like TOH, :20, :35, :50.
  - Flexibility: which exact tracks/liners run varies to avoid repetition.
- **Professional on‑air output**:
  - Default behavior should **avoid cutting** songs/long-form elements mid‑play.
  - The system should use **real durations (to the second)** to choose items that fit upcoming anchors.
- **Multiple wheels** per station and **assign wheels to days/shows** using the station schedule calendar.
- **If a clock wheel cannot resolve content**, it should **fall back** to normal AzuraCast AutoDJ rotation.

### Scheduling rules (client requirement)
- **Must-schedule**: a clock wheel only runs when it has at least one schedule entry on the station calendar (inactive wheels may exist without schedules).
- **No overlaps**: if anything else is scheduled (playlist/streamer/other wheel) in that window, the clock wheel must **not** take effect.
- Clock wheels should only run in **explicitly scheduled windows** and must **respect the calendar** (dates, recurrence, overnight, play-once windows).

### Technical goals
- Delivered as **PR-ready code inside the existing Docker containers** (no external service required).
- Touches:
  - PHP (Slim + Doctrine)
  - Vue/Vite frontend
  - Schedule/calendar UI
- **PSR-compliant PHP**, follow existing `phpcs`/`phpstan` standards used by the repo.

### Acceptance (definition of done)
1. A user can:
   - Build a 60-minute wheel with anchors,
   - Save it,
   - Assign it to a station/time window,
   - Observe logs proving the correct rotation.
2. No regressions: existing AzuraCast behavior remains intact and standard tests pass.

## Phases (roadmap)

### Phase 0 — Foundations (already present in this codebase)
- Clock wheel entities + API scaffolding.
- Clock wheels appear in station UI.

### Phase 1 — Calendar dashboard + timed anchors + core playback planning (**implemented now**)
Goal: make wheels schedulable in the calendar and make playback **time-aware**.

Delivered items in this repo:
- **Timed slot anchors** (`position_seconds` 0–3599).
- **Schedule conflict prevention** (no overlapping scheduled windows).
- **Clock-wheel playback planner** that chooses items based on:
  - current second into hour
  - next anchor window
  - track duration (seconds)
  - duplicate prevention
  - algorithm choice (random/oldest/etc.)
  - optional playlist pin; **type** (required) filter on media
- **Unified schedule dashboard** that displays playlist + clock wheel events and supports creating events.

### Phase 2 — UX improvements (partially done; see v7 doc)
Goal: make “tweaks on the fly” extremely fast and reduce operator errors.

**Done in repo:**
- Hour timeline bar + table editor (`Entries.vue`) backed by `position_seconds`.
- Overlap/gap warnings in the slot editor.
- **PR7 (MVP)** — Schedule → **Live Clock Wheel** tab (`Schedule/ClockWheelLiveTab.vue`): station clock face, now playing, hour anchors + queue titles, upcoming wheels, schedule conflict warning.

**Remaining (enterprise v7 — `docs/clock-wheels-remaining-phases-v7.md`):**
- **PR12** — Next-hour preview simulator + analytics dashboard.
- Optional: full circular drag/resize wheel UI (deferred in v7 PDF).

### Phase 3 — Hardening + tests + production guardrails (**largely implemented**)
Goal: ensure reliability and prevent regressions.

**Done (PR 6):**
- Unit + functional Codeception coverage for conflict checker, planner, scheduler, API slots/schedules (see **PR 6 — Tests & regression coverage** below).
- Run in Docker: `vendor/bin/codecept run tests/Unit` (44 tests) and optionally `tests/Functional/Api_Stations_ClockWheelsCest.php`.

**Still open:**
- Wire Codeception into CI (today `composer run dev-test` is phpcs/phpstan only).
- Improve runtime fallback logging/metrics.
- Verify Docker upgrade/migration flow for existing installs.

### Phase 4 — Playback enforcement (implemented: PR8)
Two automatic layers — **no operator toggle** between “PHP only” and Liquidsoap:

1. **PHP (always):** duration-aware track selection in `ClockWheelPlaybackPlanner` (fit before next anchor, strict vs flexible rules).
2. **AutoDJ / Liquidsoap (fallback):** when selection alone cannot guarantee timing, the planner sets `clock_wheel_enforce_cap` on the queue row and `ClockWheelAnnotator` applies **`cue_out`** through the normal annotation path (no manual `ls_config`).

**When the fallback runs**

| Situation | `clock_wheel_enforce_cap` |
|-----------|---------------------------|
| Schedule **strict** | Yes — hard stop at anchor |
| Short-form slot (ID / promo / ad / slot `duration_seconds`) | Yes |
| Schedule **flexible** + music/talk that **fits** the window | No — play naturally |
| Schedule **flexible** + music/talk with **no fitting track** (shortest overflow) | Yes — cut at anchor |

**Per schedule row** (`clock_wheel_mode` on clock wheel calendar events only):

- `flexible`: prefer full songs; overflow + short slots trigger fallback caps.
- `strict`: must fit at selection time; fallback cap at every anchor for safety.

**Not used:** playlist `loop_once` on clock wheel schedules; no `loop_once` UI for clock wheels.

### PR8 Phase 2 — playback paths, monitoring, and deferred liq work

#### How audio reaches the listener

Clock wheels are **not** generated in Liquidsoap config. Playback is always orchestrated in PHP, then delivered to Liquidsoap through the normal AutoDJ API.

```text
BuildQueue (sync) → ClockWheelScheduler → ClockWheelPlaybackPlanner → station_queue
Liquidsoap nextsong → AnnotateNextSong (asAutoDj) → ClockWheelAnnotator (cue_out when capped)
```

| Path | Clock wheel metadata | PR8 duration cap |
|------|----------------------|------------------|
| **AutoDJ queue → `nextsong`** (designed path) | Yes (`clock_wheel_id` on queue/history) | Yes — `clock_wheel_enforce_cap` + `autocue_cue_out` |
| **Liquidsoap fallback** when queue is empty (`autodj_fallback`, `standard_playlists`, `playlist_default`) | No | No |
| **Static playlist `.m3u`** (`PlaylistFileWriter`, `asAutoDj: false`) | No | No |

The overview **Now** badge reflects the **schedule** only. Confirm the **Upcoming Queue** lists wheel rows and Liquidsoap `nextsong` returns **200** during the window.

#### Short-form slots near the next anchor (implemented)

When an ID / promo / ad slot has **less time left than any file length** (e.g. 18 seconds until the next anchor, 4-minute promo on disk), the planner now behaves like flexible music overflow:

- Picks the **shortest** candidate of the correct type.
- Sets **`clock_wheel_enforce_cap`** so `ClockWheelAnnotator` applies **`cue_out`** at the anchor.
- Under **strict** schedule mode, if nothing fits and strict rules apply, the slot may still return no track (no overflow).

#### Production monitoring (recommended)

Watch during active wheel windows:

| Signal | Meaning |
|--------|---------|
| `Clock Wheel "…" is active` in `app_nowplaying-*.log` | Scheduler engaged |
| `Clock Wheel resolved track` | Planner queued a wheel row |
| `Queue builder error` / `SQLSTATE` | Queue build failed — fix before relying on wheel |
| Liquidsoap `Queue is empty!` on every `nextsong` | No queue rows — likely fallback to default playlist |
| `Switch to standard_playlists` / `playlist_default` in LS logs | On-air from static rotation, not AutoDJ wheel |
| Feedback with only `playlist_id`, no wheel in queue UI | Fallback or normal AutoDJ, not wheel |

Log files (inside container): `/var/azuracast/www_tmp/app_nowplaying-YYYY-MM-DD.log`, station Liquidsoap logs under `/var/azuracast/stations/`.

#### Deferred (optional — not required for launch)

| Item | Status | Notes |
|------|--------|-------|
| **`ConfigWriter` `max_duration`** for clock wheels | **Not implemented** | Entity doc mentions a future liq generator; there is no clock-wheel block in `.liq` today. PR8 uses PHP `cue_out` on the AutoDJ path instead. Rule if added later: only when `duration_seconds` is set on the slot; never on flexible music. |
| **`PlaylistFileWriter` clock-wheel-aware** | **Not needed** for the designed path | Only relevant if you must cap tracks played from static `.m3u` during a wheel window. |
| **Block Liquidsoap fallback during wheel** | **Not implemented** | Would need liq/config changes to prefer empty air over default playlist when queue is empty. |
| **Planner drift vs wall clock** | **Known edge** | Rebuild interval and metadata length can shift anchor adherence slightly. |

## What is implemented *right now* (in `Azura-Cast-Custom-GitRepo`)

### Backend
- **Migration**: added `station_clock_wheel_slots.position_seconds`.
  - File: `backend/src/Entity/Migration/Version20260519120000.php`
  - Backfill behavior: spreads legacy slots at ~5-minute intervals using `slot_order * 300`.
- **Entity updates**:
  - `StationClockWheelSlot` now has `position_seconds` and slots are ordered by `position_seconds, slot_order`.
  - Files:
    - `backend/src/Entity/StationClockWheelSlot.php`
    - `backend/src/Entity/StationClockWheel.php`
- **Clock wheel scheduling & playback**:
  - `ClockWheelPlaybackPlanner` chooses the active slot by second-in-hour and resolves a track that fits before the next anchor.
  - Files:
    - `backend/src/Radio/AutoDJ/ClockWheel/ClockWheelPlaybackPlanner.php`
    - `backend/src/Radio/AutoDJ/ClockWheelScheduler.php`
- **Calendar conflict prevention**:
  - A conflict checker prevents overlap between scheduled playlists/streamers/clock wheels.
  - Integrated into schedule writes.
  - Files:
    - `backend/src/Radio/Schedule/ScheduleConflictChecker.php`
    - `backend/src/Entity/Repository/StationScheduleRepository.php`
- **Clock wheel schedule feed now supports calendar click-to-edit**:
  - Adds `edit_url` to the clock wheel schedule events.
  - File: `backend/src/Controller/Api/Stations/ClockWheels/ClockWheelsController.php`

### Playback metadata (station UI + API)
- Queue rows and song history store `clock_wheel_id` when AutoDJ picks a track from a clock wheel.
- Now Playing, Upcoming Queue, and Reports → Timeline show **Clock Wheel: {name}** in the source/metadata area (same pattern as playlist).
- Station profile **Scheduled** panel lists upcoming clock wheel windows with type **Clock Wheel**.

### Frontend
- **Unified Schedule page** that shows multiple sources (playlists + clock wheels).
  - File: `frontend/components/Stations/Schedule.vue`
- **ScheduleCalendar component** supporting multiple event sources + create button.
  - File: `frontend/components/Stations/Common/ScheduleCalendar.vue`
- **Clock wheel editor** supports schedule items and timed anchors.
  - Files:
    - `frontend/components/Stations/ClockWheels/EditModal.vue`
    - `frontend/components/Stations/ClockWheels/Form/Entries.vue`
    - `frontend/components/Stations/ClockWheels/Form/Schedule.vue`
- **Create Event modal** supports clock wheel events with **Flexible / Strict** (`clock_wheel_mode`); playlist-only **Flexible / Strict / Loop Once** remain for playlists.
  - File: `frontend/components/Stations/Common/CreateEventModal.vue`
- **PR8 fallback caps** via `ClockWheelAnnotator` when `station_queue.clock_wheel_enforce_cap` is set (no admin mode switch).
  - Files: `ClockWheelPlaybackPlanner.php`, `ClockWheelAnnotator.php`

### Tests (PHPUnit / Codeception)

See **PR 6 — Tests & regression coverage** for the full matrix, run commands, and reviewer notes.

**Harness (not product tests):** `backend/src/Tests/Module.php`, `backend/src/Tests/Connector.php` — Codeception bootstrap only.

**Manual smoke (not automated):** `util/test_clock_wheels.sh` — curl against a live station; no assertions; not part of `composer run dev-test`.

## Known limitations / gaps (to address next)

- **Planner timeline** now uses `expectedPlayTime` plus unplayed queue rows in the same hour (`getPlannedSecondsIntoHour`). Remaining edge case: actual on-air drift vs metadata duration until the next queue rebuild.
  - This is good enough for basic anchors but needs refinement if you want strict adherence across drift scenarios.
- **Schedule conflict detection** currently uses a **fixed validation window** (90 days) for recurrence expansion.
  - This is intentional for performance but should be configurable and well-tested.
- **Front-end typed schedule row import**: the clock wheel edit modal currently reuses the playlist schedule row type.
  - This is fine for now but should be cleaned up for long-term maintainability.
- **CI** does not run Codeception on PRs yet (image build only); run `vendor/bin/codecept` in Docker before release (see PR 6 section).
- **Still light coverage:** Liquidsoap fallback paths, full end-to-end on-air proof in CI, frontend hour-timeline UI (no JS test suite; backend anchors covered in `ClockWheelPlaybackPlannerTest`).

## Required next steps (recommended order)

### 1) Fix and harden current changes
- Run PHP lint/format/static analysis (`composer run dev-test`: phpcs, phpstan).
- Run Codeception in Docker: `vendor/bin/codecept run tests/Unit` and functional clock-wheel Cest (see **PR 6** below).

### 2) Improve conflict checker correctness — **done**
- Unit: `tests/Unit/ScheduleConflictCheckerTest.php`, `tests/Unit/ScheduleConflictDateRangeTest.php`
- Functional overlap on create: `Api_Stations_ClockWheelsCest::clockWheelScheduleOverlapIsRejected`

### 3) Improve planner (professionalism) — **done (core)**
- Fixed anchor math: position within hour (0–3599), not seconds since midnight.
- Planned timeline: `getPlannedSecondsIntoHour()` uses `expectedPlayTime` + queued items in the same hour.
- Per-slot minimum window before deferring (music/talk/short-form).
- Unit tests: `tests/Unit/ClockWheelPlaybackPlannerTest.php`

### 4) UX upgrade for fast edits — **timeline list done**
- Hour timeline bar with clickable anchors (`frontend/components/Stations/ClockWheels/Form/Entries.vue`).
- Drag-to-reorder rows (preserves anchor times, reassigns to new order).
- Duplicate / insert-after actions; overlap/gap warnings.
- Optional later: full circular drag/resize wheel UI (same `position_seconds` backend).

## Operational validation (how to check it works)
- Create a wheel with anchors:
  - 0:00 ID
  - 20:00 Ad
  - 35:00 Promo
  - 50:00 ID
- Set **Type** on media files (ID / promo / ad / music) on the Music Files page.
- Schedule the wheel on **Schedule → Create Event** (not only “Active” on the wheel form).
- Schedule it for a station window where nothing else is scheduled.
- Confirm **Upcoming Queue** shows wheel rows during the window.
- Watch `app_nowplaying-*.log` (see **PR8 Phase 2 — production monitoring** above):
  - “Clock Wheel … is active”
  - “Clock Wheel slot selection … seconds_into_hour … available_seconds …”
  - “Clock Wheel resolved track … effective_length …”
  - No repeating `Queue builder error` or Liquidsoap `Queue is empty!`
- Create an overlapping playlist schedule in the same window:
  - verify the save is **blocked**
  - and/or at runtime the wheel is **skipped** and normal AutoDJ runs

## PR 6 — Tests & regression coverage

This section maps the **PR 6 spec** to what exists in the repo today. It is intended for reviewers who asked for “greenfield” clock-wheel tests.

### Status summary

| PR 6 requirement | Status | Primary test file(s) |
|------------------|--------|----------------------|
| Schedule conflict matrix (same-day, partial, overnight, recurring) | **Done** | `tests/Unit/ScheduleConflictCheckerTest.php`, `ScheduleConflictDateRangeTest.php` |
| Planner / hour timeline (slot resolution, window fit, fallback) | **Done** (no class named `HourTimelineTest`) | `tests/Unit/ClockWheelPlaybackPlannerTest.php` |
| Scheduler (playlist blocks wheel, active window, null fallback) | **Done** | `tests/Unit/ClockWheelSchedulerTest.php` |
| Schedule activation (windows, overnight, play-once) | **Done** | `tests/Unit/ClockWheelScheduleActivationTest.php` |
| API PUT `/slots` + `position_seconds` | **Done** | `tests/Functional/Api_Stations_ClockWheelsCest.php` → `clockWheelSlotsAndScheduleFeed` |
| Conflicting schedule save rejected | **Done** (HTTP **400**, not 422) | Same Cest → `clockWheelScheduleOverlapIsRejected` |
| PR8 `cue_out` enforcement | **Done** (bonus) | `tests/Unit/ClockWheelAnnotatorTest.php` |
| Manual curl smoke | **Exists, not CI** | `util/test_clock_wheels.sh` |

The statement *“there are no clock wheel tests anywhere”* applied to an earlier revision; it **no longer applies**.

### Unit tests (`tests/Unit/`)

Run all unit tests (44 total in full suite; includes non–clock-wheel tests):

```bash
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Unit"
```

Clock-wheel–related files only:

```bash
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Unit/ClockWheel* tests/Unit/ScheduleConflict*"
```

| File | What it asserts |
|------|-----------------|
| `ScheduleConflictCheckerTest.php` | Different weekdays OK; batch overlap rejected; adjacent boundaries (non-touching); overnight overlap; monthly date patterns; play-once overlap; existing wheel blocks new wheel; playlist blocks wheel; biweekly alternation |
| `ScheduleConflictDateRangeTest.php` | `DateRange::isWithin` overlap helpers |
| `ClockWheelPlaybackPlannerTest.php` | DQL uses `m.storage_location` (not invalid `sl.stations`); resolve slot by type; planned seconds within hour + queue advance; short-form shortest overflow; strict empty window; `shouldEnforcePlaybackCap`; active slot by `position_seconds` |
| `ClockWheelSchedulerTest.php` | Skip if next songs set; **scheduled playlist blocks wheel**; no wheel schedule; inactive wheel; **active wheel resolves track**; **planner null → no next song** |
| `ClockWheelScheduleActivationTest.php` | In/out of window; overnight after midnight; play-once 15-minute window |
| `ClockWheelAnnotatorTest.php` | Skip non-AutoDJ / no cap flag; apply `autocue_cue_out`; cap ≤ media length |

Planner tests cover **backend** anchor behavior. The Vue hour timeline bar (`Entries.vue`) has no JS test suite.

### Functional tests (`tests/Functional/`)

Requires database + full app bootstrap (Codeception `App\Tests\Module`):

```bash
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Functional/Api_Stations_ClockWheelsCest.php"
```

| Scenario | Method |
|----------|--------|
| CRUD clock wheels | `manageClockWheels` |
| Active wheel without schedule rows | `activeClockWheelMayExistWithoutSchedule` |
| Overlapping wheel schedules rejected | `clockWheelScheduleOverlapIsRejected` → **400** + `conflict` |
| POST/PUT slots with `position_seconds`, schedule feed | `clockWheelSlotsAndScheduleFeed` |

### HTTP status: 400 vs 422

Schedule validation failures throw `App\Exception\ValidationException` with **default code 400**. Functional tests assert **400**, not 422. If API consumers require 422, that is a separate contract change.

### CI vs local

| Command | Runs Codeception? |
|---------|-------------------|
| `composer run dev-test` | **No** (phpcs, phpstan, phplint) |
| `composer run codeception` | **Yes** (full suite + coverage) |
| `vendor/bin/codecept run tests/Unit` | **Yes** (recommended pre-merge for clock wheels) |

Production Docker images built with `composer install --no-dev` do not include `codecept` until dev dependencies are installed (e.g. `composer install` in the container for test runs).

### Logs for manual validation (not assertions)

During active wheel windows, see **PR8 Phase 2 — production monitoring**. PHP logs: `/var/azuracast/www_tmp/app_nowplaying-YYYY-MM-DD.log`.

```bash
docker compose exec web bash -c \
  "grep -i 'clock wheel' /var/azuracast/www_tmp/app_nowplaying-$(date -u +%Y-%m-%d).log | tail -30"
```

### PR 11 — Audit events (`clock_wheel_events`)

AutoDJ and the clock wheel scheduler append rows when they queue, defer, or fall back. Use for ops forensics and PR12 analytics.

| Column / concept | Meaning |
|------------------|---------|
| `event_kind` | `track_queued`, `deferred`, `fallback` |
| `fallback_reason` | e.g. `schedule_conflict`, `no_media_candidates`, `deferred_insufficient_window` |
| `drift_seconds` | Seconds into the hour minus slot `position_seconds` at decision time |
| `separation_relaxed` / `burn_rate_warning` | Reserved for PR9 (currently always false) |

Migration: `Version20260529120000`. Retention helper: `ClockWheelEventRepository::deleteOlderThan()` (30 days default; not wired to cron yet).

### Remaining enterprise phases (PR7–PR13)

Full breakdown: **`docs/clock-wheels-remaining-phases-v7.md`** (from `docs/AzuraCast_ClockWheel_Remaining_Phases_v7.pdf`).

| PR | Summary |
|----|---------|
| PR7 | **Done (MVP)** — Schedule → Live Clock Wheel tab (`queue` + now playing + `/schedule`) |
| PR9 | Separation rules + burn rate in planner |
| PR10 | Daypart templates / inheritance |
| PR11 | **Done** — `clock_wheel_events` audit table + planner/scheduler hooks |
| PR12 | Preview API + analytics + `fill_strategy` |
| PR13 | `is_emergency` schedule override (optional) |

### Remaining gaps (honest)

- Codeception not wired into GitHub Actions for this fork.
- No dedicated `HourTimelineTest` class name (behavior covered under planner tests).
- Limited functional coverage of every recurrence type via HTTP (unit tests cover more).
- No automated test for Liquidsoap `standard_playlists` fallback during a wheel window.
- `util/test_clock_wheels.sh` remains optional manual smoke only.

