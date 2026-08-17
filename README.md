# AzuraCast top-of-hour: lookahead, fading, hard clock trigger, ducking, linear log

## Apply the changes

**Option A — patch file** (from your repo root):
```
git apply azuracast-toph-full-fixes.patch
```
or if that complains about context/whitespace:
```
patch -p1 < azuracast-toph-full-fixes.patch
```

**Option B — raw files**: copy the `backend/`, `frontend/`, `tests/`, and
`util/` folders from this zip on top of your checkout, overwriting matches.

Both give the same result. If you have local edits to any of these files,
the patch method will warn about conflicts instead of silently overwriting.

## What's in this round

**1. Hard-timed clock trigger** — `util/docker/stations/liquidsoap/azuracast.liq`
(`azuracast.top_of_hour_hard_trigger_predicate` / `apply_top_of_hour_hard_trigger`).
A pure wall-clock `switch()`, independent of the AutoDJ queue entirely — it
doesn't care what AzuraCast thinks should be playing, it just watches
`time()` and force-switches to a safety ID source in the last N seconds of
every hour, with a real `fade.in`/`fade.out` transition (not metadata).
This is the backstop underneath everything else: even a completely broken
queue can't cause dead air or a missed ID at :00.

**2. Advanced fading** — two layers, working together:
- Soft path (from the previous round): `HourBoundaryAnnotator::applyTopOfHourPreIdFade`
  caps and fades the outgoing track's own cue-out point using AzuraCast's
  duration/annotation system, when a track is chosen that would otherwise run
  past :00.
- Hard path (new): the clock trigger above does a guaranteed `fade.in`/`fade.out`
  crossfade at the wall-clock boundary itself, regardless of what the
  annotation layer decided.

**3. Smart ducking** — `azuracast.duck()` in the same `.liq` file, using
`smooth_add` with `amplify`. Legal IDs/promos lower the music bed by a
configurable amount instead of hard-replacing it, then swell it back up.

**4. Duration-aware lookahead across every playback path** — verified (not
new code) that `QueueBuilder`'s track-selection paths all funnel through the
same duration-cap+fade backstop added last round:
- Songs playlists, any order (Random/Sequential/Shuffle/SmartShuffle) → via
  `applyHourBoundarySelection()` (prefers a shorter track outright) then
  `makeQueueFromApi()` (caps+fades as a backstop if nothing shorter exists).
- Full-cycle/backend-merge rotation playlists → same `makeQueueFromApi()`
  backstop.
- Playlist Groups → recurse into the same per-playlist selection logic above,
  so they're covered too.
- Clock Wheels → structurally boundary-aware already (each slot's
  `resolveMaxDuration`/stretch calculator is computed against the wheel's own
  anchors), so this was never the gap.

So: every path AzuraCast can pull a track from now either prefers a track
that fits before :00, or caps+fades the one it picked if nothing shorter was
available -- and the wall-clock trigger is there in case all of that somehow
still fails.

## New UI (Station → Broadcasting → Top of Hour ID page)

Two new sections were added below the existing settings:
- **Hard Clock Trigger**: enable + trigger window (seconds) + fade duration
- **Smart Ducking**: enable + music bed level while ducked + fade time

Both default to off, matching the Liquidsoap-side defaults, so nothing
changes for existing stations until you turn them on.

## Deploy steps

Same as before, run from your CLI container:

```bash
# 1. Migration (only needed if you haven't already run last round's migration)
bin/console azuracast:migrate

# 2. Rebuild frontend
npm install && npm run build
# or on Docker:
docker compose build --no-cache web

# 3. Rebuild/restart backend
docker compose build --no-cache
docker compose up -d

# 4. Clear cache
bin/console cache:clear
docker compose restart web cli station

# 5. Regenerate each station's Liquidsoap config + restart AutoDJ
#    (required this time -- the .liq template itself changed)
bin/console azuracast:radio:restart
```

Step 5 matters more than usual this round: the actual `.liq` script template
changed, so every station needs its Liquidsoap config regenerated and
Liquidsoap restarted before the hard trigger or ducking will exist in the
running process at all, even if you don't turn either one on yet.

## Turning it on

Station → Broadcasting → Top of Hour ID:
- Toggle **Enable hard clock trigger** — leave the defaults (3s window, 3s
  fade) unless you know you want a wider/narrower margin.
- Toggle **Enable smart ducking** — defaults to -14dB-ish (0.2) attenuation
  and a 3s fade; raise the attenuation value toward 1.0 for a subtler duck,
  lower it toward 0 for a harder duck.

Both require the fallback/safety file mentioned in the `.liq` comments to
exist (your station's configured dead-air fallback file) for the hard
trigger specifically -- it plays that file, annotated to skip autocue, as
the guaranteed-available safety source.
