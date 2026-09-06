# Top-of-Hour Station ID — Broadcast Clock Engine

This document describes the rebuilt station-wide Top-of-Hour Station ID feature.
It supersedes the earlier scheduler/interrupt-queue implementation.

## Design contract

The feature has one master switch: `top_of_hour_id_enabled`.

When it is disabled, no automatic Top-of-Hour ID timing rule is applied. Music,
Clock Wheels, scheduled programming, internet-radio operation, stretch/squeeze,
and the Linear Log continue under their normal rules.

When it is enabled, the automatic Station ID targets the opening of minute `:59`
every hour. HARD TOH and SOFT ETM describe what owns the following `:00`
boundary; they never move the ID away from `:59`.

The automatic ID uses the same broadcast-clock timeline as scheduled programming
and the 24-hour Linear Log. It does not create a second Liquidsoap source,
interrupt queue, delayed `source.skip()`, or wall-clock retry.

Only media explicitly tagged as Station ID is eligible. Promos, liners,
commercials, and generic filler are never silently substituted for a missing ID.

## Operating modes

### HARD TOH

HARD TOH is selected automatically when a rigid event owns `:00`.

Examples include a strict-start playlist, emergency/interrupting scheduled
program, or strict Clock Wheel scheduled on the boundary.

The Station ID still targets `:59:00` exactly. The shared broadcast-clock timing
layer plans preceding music around that anchor. The rigid `:00` event remains
authoritative and must still start on time.

For example, with a 37.825-second ID:

```text
ID target:      09:59:00.000
ID natural end: 09:59:37.825
program target: 10:00:00.000
```

This engine does not invent an ad, promo, or commercial to fill the remainder of
the minute. Any dedicated filler policy is a separate programming decision.

### SOFT ETM

SOFT ETM applies when no rigid event owns `:00`.

The Station ID also targets `:59:00`. After it finishes, normal AutoDJ continuity
continues. The engine does not insert an ad or promo merely to fill the remainder
of minute `:59`.

## Timing and stretch/squeeze

`TopOfHourClock` exposes the exact `:59:00` target to
`BroadcastClockPlanner`. That means the existing broadcast-clock timing layer,
including `BroadcastClockQueueTimingSubscriber`, `StretchSqueezeQueueTiming`,
queue projection, and the 24-hour Linear Log, all plan against the same anchor.

The station ID itself is protected content. Preceding music may be selected for a
better fit, safely stretched/squeezed within station limits, or faded through the
existing shared playout path when it cannot fit. The rebuilt feature has no
separate hard-cut timer.

## Exactly-once ownership

Each automatic Station ID queue row records `top_of_hour_boundary_at`.

A database unique key on `(station_id, top_of_hour_boundary_at)` makes boundary
ownership explicit and prevents repeated queue-building passes from creating two
automatic IDs for the same hour.

The queue row is persisted only after `BuildQueue` accepts it.

## Clock Wheel ownership

A Clock Wheel with a mandatory position-zero ID/legal-ID slot may own the hour.
The station-wide producer yields when that wheel is already active or begins at
the target boundary. This prevents back-to-back duplicate IDs.

## Priority rules

1. When enabled, the automatic Station ID owns the `:59:00` target.
2. Rigid scheduled `:00` programming keeps its exact wall-clock priority.
3. An explicit Clock Wheel position-zero mandatory ID may own the boundary and
   suppress the duplicate station-wide ID for that hour.
4. Ordinary music, Smart Blocks, requests, and rotation content are planned
   around the shared broadcast-clock anchor according to their existing rules.

## Failure behavior

- **Feature disabled:** no automatic TOH behavior.
- **No eligible Station ID:** do not substitute a promo/commercial; surface the
  missing-ID state on the Top-of-Hour page and leave normal programming intact.
- **Late boundary:** do not insert a stale automatic ID after `:00` when that
  would delay the new hour.
- **Repeated queue build:** the per-boundary database key prevents duplicate
  automatic IDs.
- **Clock Wheel owns boundary:** station-wide producer yields.

## Operator controls

The rebuilt page exposes only settings used by the new engine:

- Enable automatic Top-of-Hour Station ID
- Broadcast-clock lookahead (1–30 minutes)
- Maximum automatic Station ID length (15–60 seconds)
- Compliance reporting tolerance (1–60 seconds)

The page also shows the next clock decision, selected Station ID, planned start,
boundary, operating mode, ID-library readiness, and seven-day compliance data.
