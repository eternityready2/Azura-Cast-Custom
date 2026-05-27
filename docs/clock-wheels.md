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
  - optional playlist pin, type/category filter
- **Unified schedule dashboard** that displays playlist + clock wheel events and supports creating events.

### Phase 2 — UX improvements (recommended next)
Goal: make “tweaks on the fly” extremely fast and reduce operator errors.
- Visual “wheel” editor (drag/resize blocks) backed by `position_seconds`.
- Per-slot validation + warnings:
  - overlapping anchors, impossible windows, too-small gaps
  - “no media fits this slot” preview warnings
- Better “preview hour” / “dry run” screen.

### Phase 3 — Hardening + tests + production guardrails (required before wide deployment)
Goal: ensure reliability and prevent regressions.
- Automated tests for:
  - conflict checker (weekly/monthly/overnight)
  - planner slot selection and “fit-to-window” behavior
- Improve runtime fallback logging/metrics.
- Verify Docker upgrade/migration flow for existing installs.

### Phase 4 — Optional Liquidsoap-level strictness (only if needed)
Goal: only if precision handoffs require it beyond AutoDJ queue planning.
- Consider max-duration enforcement for **short** items (IDs/ads/promos) where appropriate.
- Avoid hard cutting of music by default (professionalism requirement).

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
- **Create Event modal** supports clock wheel events.
  - File: `frontend/components/Stations/Common/CreateEventModal.vue`

### Tests (partial)
- Date range overlap helper: `tests/Unit/ScheduleConflictDateRangeTest.php`
- Clock wheel schedule activation (overnight, play-once, window boundaries): `tests/Unit/ClockWheelScheduleActivationTest.php`
- Clock wheel API (CRUD, must-schedule, overlap rejection, slots, schedule feed): `tests/Functional/Api_Stations_ClockWheelsCest.php`

## Known limitations / gaps (to address next)

- **Planner timeline** now uses `expectedPlayTime` plus unplayed queue rows in the same hour (`getPlannedSecondsIntoHour`). Remaining edge case: actual on-air drift vs metadata duration until the next queue rebuild.
  - This is good enough for basic anchors but needs refinement if you want strict adherence across drift scenarios.
- **Schedule conflict detection** currently uses a **fixed validation window** (90 days) for recurrence expansion.
  - This is intentional for performance but should be configurable and well-tested.
- **Front-end typed schedule row import**: the clock wheel edit modal currently reuses the playlist schedule row type.
  - This is fine for now but should be cleaned up for long-term maintainability.
- **No full Codeception/API tests** yet for:
  - “overlap save is rejected”
  - “clock wheel runs only when window is free”
  - “fallback occurs cleanly”

## Required next steps (recommended order)

### 1) Fix and harden current changes
- Run PHP lint/format/static analysis in the container CI environment (`phpcs`, `phpstan`).
- API/schedule tests added (see Tests section above); run `composer run codeception` in Docker to verify.

### 2) Improve conflict checker correctness — **done**
- Dedicated unit suite: `tests/Unit/ScheduleConflictCheckerTest.php`
  - weekly vs monthly recurrence
  - overnight windows
  - boundary behavior (end == start)
  - “play once” items
  - cross-entity conflicts (playlist vs clock wheel)

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
- Schedule it for a station window where nothing else is scheduled.
- Watch the queue build logs:
  - “Clock Wheel … is active”
  - “Clock Wheel slot selection … seconds_into_hour … available_seconds …”
  - “Clock Wheel resolved track … effective_length …”
- Create an overlapping playlist schedule in the same window:
  - verify the save is **blocked**
  - and/or at runtime the wheel is **skipped** and normal AutoDJ runs

