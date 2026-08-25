# Top-of-Hour Station ID v2

## Goal

The legal station ID belongs to the end of the outgoing hour, not the start of the next hour.

Default target behavior:

- Protect the final two minutes of the hour for the station ID.
- Prefer the ID to begin during `:58` or `:59`.
- Finish the ID before `:00`, including the configured finish buffer.
- Treat `:00` as the beginning of the next hour's programming.
- Queue exactly one legal ID per hour boundary.
- Do not resume a track after an emergency interruption.

## Planning model

The PHP AutoDJ layer owns planning and selection:

1. During the lookahead period, reject music that cannot end before the protected ID window.
2. Prefer a naturally fitting track.
3. Use bounded stretch/squeeze when the selected track is close enough to the available duration.
4. Use cue-out/fade only as a safety fallback, not as the primary scheduling method.
5. Queue one legal-ID row for the upcoming boundary.

The planning clock (`BuildQueue::getExpectedPlayTime()`) is used only for queue construction. Actual wall-clock timing must not be inferred from a long prebuilt queue.

## Hard clock and ducking

Hard-clock triggering and smart ducking remain broadcast-automation features. The legal-ID coordinator must not allow an independent hard-clock path to replay or overlay an ID that already aired for the same boundary.

A hard-clock ID fallback, if used, must be boundary-idempotent and must allow the full selected ID to finish before `:00`; it must not use the old five-second `:59:55` to `:00` source window.

Ducking remains available for programming elements that intentionally use a music bed. A normal legal ID is treated as a discrete program element unless explicitly configured otherwise.

## Clock wheels

A clock wheel with a mandatory legal-ID slot participates in the same boundary ownership. It substitutes for the station-wide legal ID for that boundary instead of racing it.

## Acceptance cases

- Long song proposed at `:55` that would cross the protected window is rejected.
- A fitting song can play normally and hand off to the ID in `:58`/`:59`.
- A near-fit may use safe stretch/squeeze.
- The ID finishes before `:00`.
- New-hour programming starts at `:00` without a resumed old track.
- A clock-wheel legal ID prevents a second station-wide ID.
- A hard-clock fallback cannot replay an ID already served for that boundary.
