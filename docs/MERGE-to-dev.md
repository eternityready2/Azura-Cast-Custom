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

- `6d85f2f` — **partial sync** from an older `clock-wheel-branch` tip (May 2026). See below.
- `6e4e1af` — remove “active wheel must have schedule on wheel form” (`assertActiveClockWheelHasSchedule`). **Already present** on `clock-wheel-branch` (scheduling is on the station **Schedule** calendar only).

## `6d85f2f` and future PR / merge risk

**What it is:** commit `6d85f2f` (“sync: pull clockwheel features from clock-wheel-branch to dev”) landed on `dev` *before* the full branch merge. It is an **older** clock-wheel snapshot, not your later `ce52daf` work (media toolbar, type-only slots, PR8, schedule calendar, etc.).

**Slot model difference (main conflict surface):**

| | `6d85f2f` / current `origin/dev` | `ce52daf` / `clock-wheel-branch` (restored) |
|---|----------------------------------|-----------------------------------------------|
| Wheel form | “Type **or Category**” (`slot_value`, `cat:…`) | **Type** only (`mediaTypes.ts`) |
| API save | Category-only slots allowed (`type = null`) | Type always set; `category_id` optional API-only |
| Planner | May query by category without type | Always filters `m.type` |

**`4b23c1d` regression:** merging `origin/dev` into `clock-wheel-branch` re-applied the `6d85f2f` slot UI on five files. Those files were restored from `ce52daf` (type-only slots). **Commit that restore** before opening the PR.

**Will `6d85f2f` cause conflicts again?**

| Action | Risk |
|--------|------|
| **PR `clock-wheel-branch` → `dev`** (recommended) | **Low–medium.** `dev` still has `6d85f2f`-era clock-wheel files; the PR updates them to the branch tip (~50 lines on `Entries.vue` / controller / planner). GitHub may auto-merge; if not, **keep the branch version** (type-only + full feature set). No need to replay `6d85f2f`. |
| **`git revert e1f3b8e` on `dev`, then merge branch** | **Low.** Restores merge `01d3b2a` first; second merge often “Already up to date” after the restore commit. |
| **`git merge origin/dev` on `clock-wheel-branch`** | **High — do not.** Merge commit `4b23c1d` already did this once; `e1f3b8e` revert on `dev` can delete ~2k lines of feature code even when Git reports “Automatic merge went well”. |

`6d85f2f` stays in **history** on both branches; it does not re-run. The risk is **file-level divergence** on the same paths (`ClockWheelsController`, `ClockWheelPlaybackPlanner`, `EditModal.vue`, `Entries.vue`, `clockWheelPosition.ts`) if someone merges `dev` into the branch again or resolves PR conflicts by picking the `dev` / `6d85f2f` slot UI.

## Conflict resolution notes

If someone must merge `dev` into `clock-wheel-branch` for other reasons:

1. Never accept `dev` versions that delete clock-wheel / media / migration files.
2. Keep `clock-wheel-branch` versions of: planner, conflict checker, annotator, `CreateEventModal`, `Media.vue`, `MediaToolbar.vue`, API generators, migrations.
3. For clock-wheel **slots**, keep the **`ce52daf`** model (type required in UI; not the `6d85f2f` “Type or Category” combined dropdown).
4. Do **not** re-add `frontend/components/Stations/ClockWheels/Form/Schedule.vue` — wheel air times are managed on **Schedule → Create Event** only.
