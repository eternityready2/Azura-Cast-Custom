# Top-of-Hour Station ID — Exact Wall-Clock Engine

## Contract

`top_of_hour_id_enabled` is the master switch. When disabled, no automatic TOH
playout rule is applied.

When enabled, the station-wide Station ID owns an operator-selected second inside
minute `:59`. The default is `:59:00`; the operator may choose any second from
`:59:00` through `:59:59` on the Top of Hour page.

Only media explicitly tagged as Station ID is eligible. Promos, commercials,
liners and other filler are never substituted automatically.

## Runtime architecture

The AutoDJ/broadcast-clock planner still sees the upcoming ID target and tries to
make the approach clean. That planning is advisory; it is not the final clock.

A once-per-minute PHP staging task resolves the selected Station ID during the
configured lookahead window (default 10 minutes), creates the visible Upcoming
Queue row, records the expected compliance event, and pushes the resolved request
into a dedicated Liquidsoap request queue.

Liquidsoap owns the exact playout deadline. PHP converts the station-local
`:59:ss` target and the following `:00` boundary to absolute epoch timestamps, so
server timezone and DST cannot move the event.

The staged TOH row is deliberately excluded from the normal AutoDJ queue cursor.
It is visible/auditable, but it cannot become the ordinary "next song" or distort
music queue timing.

## If music is still playing

The outgoing station source is dynamically faded to silence during the configured
pre-ID fade window (default 5 seconds). At the exact target epoch, Liquidsoap uses
a `track_sensitive=false` switch to take the Station ID immediately; it does not
wait for the music track boundary.

The ID itself does not fade in late. It starts at full program level at the exact
clock deadline. After the ID, an interrupted AutoDJ track is skipped so it cannot
resume from the middle. A live DJ is not skipped; the live source may resume after
the ID.

## Rigid program at :00

A rigid scheduled program has absolute priority at `:00:00`.

The ID still starts at the operator-selected `:59:ss`; HARD mode does not secretly
move the ID later. If the configured ID start leaves insufficient time for the
selected ID to finish, the rigid program cuts the remaining ID audio at `:00`.
The Top of Hour page warns about this condition and shows a recommended latest
whole-second start based on the selected ID length.

Example with a 37.825-second ID:

- `:59:00` start -> finishes about `:59:37.825`.
- `:59:22` start -> finishes about `:59:59.825`.
- A rigid `:00` program always starts exactly at `:00:00`.

## Open hour and AI News

If no rigid program owns `:00`, the Station ID is allowed to finish naturally.
Normal AutoDJ continuity then resumes.

The legacy direct top-hour AI News cron is suppressed while automatic TOH ID is
enabled so it cannot race the ID at `:59`. If top-hour AI News is enabled, the
runtime queues that bulletin after the ID on an open hour. Bottom-hour AI News is
unchanged. When TOH is disabled, normal AI News behavior is restored.

## Clock Wheels

A strict Clock Wheel that already contains a mandatory position-zero legal-ID/ID
slot owns that boundary. The station-wide TOH staging task yields so two IDs are
not stacked.

## Exactly-once and compliance

Each station-wide automatic ID row records `top_of_hour_boundary_at`; the database
unique key `(station_id, top_of_hour_boundary_at)` prevents duplicate ownership of
the same hour.

The staging task creates one compliance event tied to that queue row with
`expected_play_at` equal to the real configured ID target. Liquidsoap feedback via
`sq_id` records the actual on-air time, so the seven-day TOH compliance panel
measures drift against the correct `:59:ss` target.

## Operator controls

The Top of Hour page exposes:

- Enable/disable automatic TOH ID.
- Exact ID start second inside minute `:59` (`0..59`).
- Slow pre-ID fade (`1..10` seconds).
- Staging/lookahead (`1..30` minutes).
- Maximum eligible ID length (`15..60` seconds).
- Compliance reporting tolerance (`1..60` seconds).

The page also shows selected ID, target start, `:00` boundary, ID length, whether
the ID has already been staged into Upcoming Queue, HARD/open-hour status, a HARD
cut warning, and seven-day compliance.
