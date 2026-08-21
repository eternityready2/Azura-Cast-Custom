# Top-of-Hour ID Fix — What Changed & How to Deploy

## What was actually wrong (confirmed against your 13-day timeline log)

1. **Duplicate ID plays.** `hasTopOfHourIdQueued()` only checked the *unplayed*
   queue. Once an ID actually aired, it fell out of that check, so a later
   re-evaluation (interrupt-fallback tick, etc.) thought nothing had been
   queued yet and queued a second ID for the same hour. Matches rows like
   your 8/10 3:58pm → 3:59pm (News) → 4:01pm → 4:02pm (ID again).

2. **ID timing drifting between :57 and :08.** The song *before* the ID only
   gets shortened/faded if it happens to be selected while already inside the
   10-minute top-of-hour lookahead window. If several songs get queued at once
   (e.g. right after a restart) and one lands just outside that window, it can
   run long and push the ID's actual airtime anywhere from several minutes
   early to several minutes late — with no correction once it's queued.

3. **Legal ID playing at random times mid-hour** (9:36am, 12:38pm, etc. — no
   relation to :00). Nothing stopped `id`/`legal_id`-typed media from being
   selected by ordinary rotation playlists (General Mix, Promos/Ads). It's
   only supposed to be picked by the dedicated top-of-hour/clock-wheel
   resolvers.

4. **News sometimes plays before the ID.** Your AI news bulletin is pushed via
   a Liquidsoap `cron.add` straight into the `requests` queue, which sits
   *above* the normal AutoDJ queue in priority but doesn't know anything about
   the PHP-side ID scheduler. When the preceding song overran (bug #2), the ID
   was still waiting in the low-priority AutoDJ queue when news's cron fired,
   so news won the race. Fixing #2 is what actually fixes this: once the ID
   reliably starts on time, it's already playing when news's cron fires, and
   Liquidsoap's `track_sensitive=true` correctly waits for it to finish before
   cutting to news.

## Files changed (5 files, diffs included)

- `backend/src/Radio/AutoDJ/HourBoundaryPlanner.php` — fixes duplicate IDs (#1)
- `backend/src/Radio/AutoDJ/HourBoundaryAnnotator.php` — live safety-net cap (#2) + true crossfade into the ID (#5, see below)
- `backend/src/Entity/Repository/StationQueueRepository.php` — new query used by #1
- `backend/src/Entity/Repository/StationPlaylistMediaRepository.php` — excludes ID media from normal rotation (#3)
- `backend/src/Radio/AutoDJ/ClockWheel/ClockWheelAnnotator.php` — same crossfade fix as #5, kept in sync for Clock Wheel-driven IDs

Each `.diff` file shows exactly what changed if you want to review before pasting.

## New: real crossfade into the ID (not a hard cut)

Previously the outgoing song faded to silence, then the ID started at full
volume with zero fade-in — a hard cut, even though the song faded out gently.
The system already had a proper crossfade field (`autocue_start_next`) sitting
right there, unused — a previous version had deliberately disabled it for the
ID with a comment calling it a "quick cut."

Now: the outgoing song's fade-out and the ID's fade-in overlap for the same
window (matched to your station's normal crossfade duration setting), so the
ID audibly rises in underneath the tail of the song instead of cutting in.
The ID's own *ending* is untouched — still a clean cut into whatever plays
next, since that wasn't part of what you asked for.

## After deploying: set these on your Top of Hour admin page

Your legal ID is 37 seconds. To land it consistently starting at **:59:00**:

- **Finish buffer (seconds):** `20`
- **ID max seconds:** `40`

(20 + 40 = 60 seconds before the hour → the preceding song is capped to end at
:59:00, the ID plays :59:00–:59:37ish, leaving ~20s before :00.)

- **Compliance tolerance (seconds):** `15–20` (safety net only, rarely used now)

Leave your AI News "top of hour" minute setting alone — it's already set to
:59, which is correct now that the ID will reliably already be playing when
that cron fires.

---

## Deploy steps

### 1. SSH into your server

```bash
ssh youruser@your-server-ip
```

### 2. Find your AzuraCast install path and shell into the web container

```bash
cd /var/azuracast   # or wherever your docker-compose.yml lives
docker compose ps   # confirm container names, usually "web"
```

### 3. Create empty files with nano, then paste each one in

Run these one at a time. For each, paste the full contents of the matching
file from the download, then save (`Ctrl+O`, Enter) and exit (`Ctrl+X`).

```bash
nano /tmp/HourBoundaryPlanner.php
nano /tmp/HourBoundaryAnnotator.php
nano /tmp/StationQueueRepository.php
nano /tmp/StationPlaylistMediaRepository.php
nano /tmp/ClockWheelAnnotator.php
```

### 4. Copy each into the running container at its correct path

```bash
docker cp /tmp/HourBoundaryPlanner.php $(docker compose ps -q web):/var/azuracast/www/backend/src/Radio/AutoDJ/HourBoundaryPlanner.php

docker cp /tmp/HourBoundaryAnnotator.php $(docker compose ps -q web):/var/azuracast/www/backend/src/Radio/AutoDJ/HourBoundaryAnnotator.php

docker cp /tmp/StationQueueRepository.php $(docker compose ps -q web):/var/azuracast/www/backend/src/Entity/Repository/StationQueueRepository.php

docker cp /tmp/StationPlaylistMediaRepository.php $(docker compose ps -q web):/var/azuracast/www/backend/src/Entity/Repository/StationPlaylistMediaRepository.php

docker cp /tmp/ClockWheelAnnotator.php $(docker compose ps -q web):/var/azuracast/www/backend/src/Radio/AutoDJ/ClockWheel/ClockWheelAnnotator.php
```

> If your compose service isn't named `web`, swap it in — check with
> `docker compose ps`. If AzuraCast is running from a git checkout on the
> host and bind-mounted into the container instead, you can skip `docker cp`
> entirely and just save the files directly to the host paths.

### 5. Clear PHP cache (no frontend/Vite changes here, so no Node/Vite rebuild needed)

```bash
docker compose exec web azuracast_cli cache:clear
```

### 6. Restart AzuraCast so the AutoDJ/Liquidsoap workers pick up the change

```bash
docker compose restart
```

(A full `restart` is safest here since `HourBoundaryAnnotator`'s constructor
changed — a plain cache clear won't re-wire that service's dependencies on
its own.)

### 7. Verify

- Watch the station log for `[TOPH DEBUG]` lines around the next couple of
  hour boundaries.
- Cross-check against a fresh timeline export in a day or two: the legal ID
  should now cluster tightly at :59, with no more stray mid-hour plays and no
  duplicates.

---

## One thing I did *not* touch

Your Clock Wheel scheduler runs at a higher priority than the top-of-hour ID
scheduler, so if a clock wheel is active for a given hour but doesn't have its
own legal-ID slot, it could in theory silently take that hour's ID slot. Your
timeline shows clock wheels are barely in use (`test-daypart 09:00` only), so
I left this alone rather than risk an untested change to that interaction —
but flagging it in case you build out clock wheels further.
