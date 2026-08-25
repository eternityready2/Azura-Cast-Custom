# Top-of-Hour Station ID v2

## Goal

The legal station ID belongs to the end of the outgoing hour, not the start of the next hour.

Default target behavior:

- Protect the final two minutes of the hour for the station ID.
- Prefer the ID to begin during `:58` or `:59`.
- Finish the ID before `:00`, including the configured finish buffer.
- Treat `:00` as the beginning of the next hour's programming.
- Queue exactly one legal ID per hour boundary.
- Never hard-cut a song or scheduled item as routine TOH behavior.
- Never resume a track after a TOH transition.
- Scheduled programming targets its scheduled start and has at most 60 seconds of grace; ordinary music may remain flexible when no hard scheduled boundary exists.

## Planning model

The PHP AutoDJ layer owns planning and selection:

1. During the lookahead period, reject music that cannot end before the protected ID window.
2. Prefer a naturally fitting track and existing cue/outro information.
3. Use bounded pitch-preserving stretch/squeeze when the selected track is close enough to the available duration.
4. If a near-boundary correction is still required, use a configured fade/cue-out transition rather than an abrupt skip.
5. Allow the ID to enter through the normal configured crossfade/segue behavior.
6. Queue one legal-ID row for the upcoming boundary.

The planning clock (`BuildQueue::getExpectedPlayTime()`) is used only for queue construction. Actual wall-clock timing must not be inferred from a long prebuilt queue.

## Scheduled-boundary policy

Scheduled programming is protected more strongly than ordinary music.

- A scheduled item should begin at its configured time.
- The maximum permitted grace is 60 seconds after the scheduled start.
- The planner must avoid starting music that would occupy that protected boundary.
- The real-time watchdog may report a boundary risk, but it must not issue an abrupt `skip` merely to recover timing.
- When no scheduled item exists after the ID, normal AutoDJ music may resume without forcing an exact `:00` transition.

## Hard clock and ducking

Hard-clock triggering and smart ducking remain broadcast-automation features. The legal-ID coordinator must not allow an independent hard-clock path to replay or overlay an ID that already aired for the same boundary.

A hard-clock ID fallback, if used, must be boundary-idempotent and duration-aware. It must reserve enough time for the complete selected ID and must not use the old five-second `:59:55` to `:00` source window.

Hard clock does not mean an audible hard cut. For TOH legal-ID service, advance planning, bounded stretch/squeeze and a smooth configured fade/segue are preferred. An abrupt source skip is not part of normal TOH operation.

Ducking remains available for programming elements that intentionally use a music bed. A normal legal ID is treated as a discrete program element unless explicitly configured otherwise.

## Clock wheels

A clock wheel with a mandatory legal-ID slot participates in the same boundary ownership. It substitutes for the station-wide legal ID for that boundary instead of racing it.

## Acceptance cases

- Long song proposed at `:55` that would cross the protected window is rejected before playout.
- A fitting song can play normally and hand off to the ID in `:58`/`:59`.
- A near-fit may use safe stretch/squeeze.
- A remaining small timing correction uses a fade/segue, not an abrupt skip.
- The ID finishes before `:00` whenever normal planning succeeds.
- Scheduled new-hour programming starts at its boundary or within the 60-second maximum grace.
- If nothing is scheduled, music after the ID remains flexible.
- A clock-wheel legal ID prevents a second station-wide ID.
- A hard-clock fallback cannot replay an ID already served for that boundary.
