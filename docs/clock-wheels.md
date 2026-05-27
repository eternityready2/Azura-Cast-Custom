# Clock Wheels (Format Clocks) – Project Document

This document describes the **targets**, **phases**, **current implementation status**, and **next steps** for the Clock Wheels feature in this AzuraCast custom codebase.

## Targets (what the feature must do)

### Broadcast / Programming goals
- **Ordered format stacks**: each wheel is a simple list of entries (ID, music, promo, category, etc.) played **one after another**.
- **Loop** through the list for the whole scheduled block; **which track** plays in each slot still varies (algorithms, duplicate prevention).
- **Calendar duration** controls how long the wheel runs (start/end on the Schedule page); playback **cuts at block end** if a track would run past it.
- **Multiple wheels** per station, assigned on the station **Schedule** calendar.
- **If a clock wheel cannot resolve content**, it should **fall back** to normal AzuraCast AutoDJ rotation.

### Scheduling rules (client requirement)
- **Central calendar**: playlists and clock wheels are scheduled only on the station **Schedule** page (`/station/{id}/schedule`). The clock wheel and playlist editors do not include a schedule tab.
- **Create first, schedule later**: you may save an active clock wheel without calendar entries; it will not run on-air until you assign it on the Schedule page.
- **No overlaps**: if anything else is scheduled (playlist/streamer/other wheel) in that window, the clock wheel must **not** take effect.
- Clock wheels only run in **explicitly scheduled windows** and must **respect the calendar** (dates, recurrence, overnight, play-once windows).

### Technical goals
- Delivered as **PR-ready code inside the existing Docker containers** (no external service required).
- Touches:
  - PHP (Slim + Doctrine)
  - Vue/Vite frontend
  - Schedule/calendar UI
- **PSR-compliant PHP**, follow existing `phpcs`/`phpstan` standards used by the repo.

### Acceptance (definition of done)
1. A user can:
   - Build an ordered wheel (drag-reorder entries),
   - Save it,
   - Assign it on the Schedule page for any duration,
   - Observe sequential playback that loops until the block ends.
2. No regressions: existing AzuraCast behavior remains intact and standard tests pass.

## Phases (roadmap)

### Phase 0 — Foundations (already present in this codebase)
- Clock wheel entities + API scaffolding.
- Clock wheels appear in station UI.

### Phase 1 — Calendar + sequential playback (**implemented now**)
Goal: schedule wheels on the central calendar; play entries in list order.

Delivered items in this repo:
- **Ordered entries** (`slot_order`); drag-reorder in the editor.
- **Schedule conflict prevention** (no overlapping scheduled windows).
- **Sequential playback planner**:
  - advances to the next entry after each track
  - loops the list until the calendar block ends
  - cuts track length to remaining block time
  - duplicate prevention, algorithms, type/category/playlist pin
- **Unified schedule dashboard** (playlists + clock wheels, Create Event).

### Phase 2 — UX improvements (optional)
- “Preview run” / dry-run for a scheduled block.
- Per-slot “no media available” warnings in the editor.

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
- **Ordered slots** (`slot_order`); list order from the API / drag-reorder UI.
- **Clock wheel scheduling & playback**:
  - `ClockWheelPlaybackPlanner`: sequential index (loops), bounded by active calendar occurrence; cuts at block end.
  - `Scheduler::getActiveOccurrenceRange()` for start/end of the current schedule window.
  - Files:
    - `backend/src/Radio/AutoDJ/ClockWheel/ClockWheelPlaybackPlanner.php`
    - `backend/src/Radio/AutoDJ/ClockWheelScheduler.php`
    - `backend/src/Radio/AutoDJ/Scheduler.php`
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
- **Clock wheel editor**: ordered entry table (drag-reorder); scheduling only on Schedule page.
  - Files:
    - `frontend/components/Stations/ClockWheels/EditModal.vue`
    - `frontend/components/Stations/ClockWheels/Form/Entries.vue`
- **Create Event modal** supports clock wheel events.
  - File: `frontend/components/Stations/Common/CreateEventModal.vue`

### Tests (partial)
- Date range overlap helper: `tests/Unit/ScheduleConflictDateRangeTest.php`
- Clock wheel schedule activation (overnight, play-once, window boundaries): `tests/Unit/ClockWheelScheduleActivationTest.php`
- Clock wheel API (CRUD, calendar-only scheduling, overlap rejection, slots, schedule feed): `tests/Functional/Api_Stations_ClockWheelsCest.php`

## Known limitations / gaps (to address next)

- **Slot index** is inferred from queue rows since block start; very long blocks with heavy manual queue edits could desync until the next block.
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

### 3) Sequential planner — **done**
- Play entries in `slot_order`, loop until schedule block ends, cut at block end.
- Unit tests: `tests/Unit/ClockWheelPlaybackPlannerTest.php`

### 4) UX — **simplified list editor**
- Drag-reorder table; duplicate / insert-after; no timeline bar.

## Browser QA

See [clock-wheels-testing.md](./clock-wheels-testing.md) for step-by-step manual tests in the admin UI (sample hour, schedule conflicts, playback logs).

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

