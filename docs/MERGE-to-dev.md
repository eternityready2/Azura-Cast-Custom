# Merging `clock-wheel-branch` into `dev`

## Current situation (May 2026)

| Branch | State |
|--------|--------|
| `clock-wheel-branch` | **Source of truth** — all clock wheel, media type/category, schedule calendar, and PR8 work. |
| `origin/dev` | Contains merge `01d3b2a` then **revert** `e1f3b8e`, which removed that work from the tree. |

**Do not** run `git merge origin/dev` on `clock-wheel-branch`. That replays the revert and deletes ~2k lines of feature code.

## Safe way to update `dev`

On `dev` (after pulling latest):

```bash
git checkout dev
git pull origin dev

# Undo the revert (restores the merged clock-wheel tree)
git revert e1f3b8e -m 1

# Bring in any newer commits from clock-wheel-branch (if needed)
git merge origin/clock-wheel-branch
```

After `git revert e1f3b8e`, the tree should already match `clock-wheel-branch` at tip; the second merge may report “Already up to date.”

Verify key areas:

- `docs/clock-wheels.md`
- `backend/src/Radio/Schedule/ScheduleConflictChecker.php`
- `backend/src/Radio/AutoDJ/ClockWheel/ClockWheelAnnotator.php`
- `frontend/functions/mediaTypes.ts`
- `frontend/components/Stations/Media/MediaToolbar.vue` (one-touch bulk type/category)
- Migrations `Version20260519120000`, `Version20260527120000`, `Version20260528120000`

Then run `azuracast_update` and rebuild frontend assets on the target environment.

## What was on `dev` besides the revert

- `6d85f2f` — partial sync (superseded by full branch).
- `6e4e1af` — remove “active wheel must have schedule on wheel form” (`assertActiveClockWheelHasSchedule`). **Already present** on `clock-wheel-branch` (scheduling is on the station **Schedule** calendar only).

## Conflict resolution notes

If someone must merge `dev` into `clock-wheel-branch` for other reasons:

1. Never accept `dev` versions that delete clock-wheel / media / migration files.
2. Keep `clock-wheel-branch` versions of: planner, conflict checker, annotator, `CreateEventModal`, `Media.vue`, `MediaToolbar.vue`, API generators, migrations.
3. Do **not** re-add `frontend/components/Stations/ClockWheels/Form/Schedule.vue` — wheel air times are managed on **Schedule → Create Event** only.
