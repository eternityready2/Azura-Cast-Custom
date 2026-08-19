<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared hour-boundary math for station-wide top-of-hour protection.
 */
final class HourBoundaryPlanner
{
    public const int HOUR_SECONDS = 3600;

    public const int DEFAULT_LOOKAHEAD_MINUTES = 10;

    public const int DEFAULT_FINISH_BUFFER_SECONDS = 15;

    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = 10;

    public const int DEFAULT_ID_MAX_SECONDS = 60;

    public const int MIN_LOOKAHEAD_MINUTES = 1;

    public const int MAX_LOOKAHEAD_MINUTES = 30;

    public const int MIN_FINISH_BUFFER_SECONDS = 0;

    public const int MAX_FINISH_BUFFER_SECONDS = 120;

    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = 1;

    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = 60;

    public const int MIN_ID_MAX_SECONDS = 15;

    public const int MAX_ID_MAX_SECONDS = 120;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public function isTopOfHourProtectionEnabled(Station $station): bool
    {
        return $station->backend_config->top_of_hour_id_enabled;
    }

    public function getComplianceToleranceSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_compliance_tolerance_seconds,
            self::MIN_COMPLIANCE_TOLERANCE_SECONDS,
            self::MAX_COMPLIANCE_TOLERANCE_SECONDS,
            self::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
        );
    }

    public function getLookaheadMinutes(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_lookahead_minutes,
            self::MIN_LOOKAHEAD_MINUTES,
            self::MAX_LOOKAHEAD_MINUTES,
            self::DEFAULT_LOOKAHEAD_MINUTES,
        );
    }

    public function getFinishBufferSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_finish_buffer_seconds,
            self::MIN_FINISH_BUFFER_SECONDS,
            self::MAX_FINISH_BUFFER_SECONDS,
            self::DEFAULT_FINISH_BUFFER_SECONDS,
        );
    }

    public function getIdMaxSeconds(Station $station): int
    {
        return $this->clampInt(
            $station->backend_config->top_of_hour_id_max_seconds,
            self::MIN_ID_MAX_SECONDS,
            self::MAX_ID_MAX_SECONDS,
            self::DEFAULT_ID_MAX_SECONDS,
        );
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour.
     */
    public function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        ?DateTimeZone $tz = null,
    ): int {
        $tz ??= $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $seconds = $local->getTimestamp() - $hourStart->getTimestamp();

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $playedAt = $row->timestamp_played;
            if ($playedAt === null) {
                continue;
            }

            $queuedLocal = CarbonImmutable::instance($playedAt)->setTimezone($tz);
            if ($queuedLocal->format('Y-m-d H') !== $local->format('Y-m-d H')) {
                continue;
            }

            if ($queuedLocal->greaterThanOrEqualTo($local)) {
                continue;
            }

            $queuedHourStart = $queuedLocal->startOf('hour');
            $queuedStartOffset = $queuedLocal->getTimestamp() - $queuedHourStart->getTimestamp();
            $queuedEndOffset = $queuedStartOffset + (int)ceil((float)($row->duration ?? 0));

            $seconds = max($seconds, min($queuedEndOffset, self::HOUR_SECONDS - 1));
        }

        return min(max(0, $seconds), self::HOUR_SECONDS - 1);
    }

    /**
     * Expected wall-clock time for the next mandatory top-of-hour legal ID.
     */
    public function resolveTopOfHourExpectedPlayAt(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): DateTimeImmutable {
        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $secondsIntoHour = $local->getTimestamp() - $hourStart->getTimestamp();

        if ($secondsIntoHour > 30) {
            return $hourStart->addHour()->toDateTimeImmutable();
        }

        return $hourStart->toDateTimeImmutable();
    }

    public function getNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');

        if ($local->greaterThan($hourStart)) {
            return $hourStart->addHour()->toDateTimeImmutable();
        }

        return $hourStart->toDateTimeImmutable();
    }

    public function secondsUntilNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $nextTop = CarbonImmutable::instance($this->getNextTopOfHour($expectedPlayTime, $tz));

        return max(0, $nextTop->getTimestamp() - $local->getTimestamp());
    }

    public function isInLookaheadZone(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $lookaheadSeconds = $this->getLookaheadMinutes($station) * 60;

        return $secondsUntil > 0 && $secondsUntil <= $lookaheadSeconds;
    }

    /**
     * Max music duration (seconds) so playback finishes before `:00` with finish buffer + ID headroom.
     * Returns null when protection is off or outside the lookahead window.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            return null;
        }

        $tz = $station->getTimezoneObject();
        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $buffer = $this->getFinishBufferSeconds($station) + $this->getIdMaxSeconds($station);

        $maxDuration = (float)($secondsUntil - $buffer);

        // If there isn't at least a minimally useful amount of room left before the
        // boundary, don't force a cap at all -- returning null here means "let normal
        // selection proceed uncapped" rather than clamping to an unplayably tiny value
        // (previously floored at 1.0 second). A 1-second cap can never be satisfied by
        // a real track, so it forced every caller into the "shortest non-recent track"
        // fallback -- during a deep multi-hour build (e.g. the 24-hour linear log,
        // which crosses many real hour boundaries in a single pass) this repeatedly
        // picked the same one or two shortest songs in the library, which then failed
        // DMCA's rolling-window repeat check every time. Below this threshold we're
        // already inside the finish-buffer/ID-max window anyway, where the mandatory
        // legal ID itself (not a capped music track) is what's supposed to be queued.
        if ($maxDuration < self::MIN_USABLE_CAP_SECONDS) {
            return null;
        }

        return $maxDuration;
    }

    private const float MIN_USABLE_CAP_SECONDS = 15.0;

    /**
     * True when AutoDJ should queue the mandatory legal ID for this build tick.
     */
    public function isTopOfHourIdDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $targetHourStart = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $targetLocal = CarbonImmutable::instance($targetHourStart)->setTimezone($tz);

        $secondsUntil = $this->secondsUntilNextTopOfHour($expectedPlayTime, $tz);
        $buffer = $this->getFinishBufferSeconds($station) + $this->getIdMaxSeconds($station);

        // Trigger ID when expected play time falls in the buffer window before
        // the hour boundary. This prevents dead air between music ending and
        // the old :00-only trigger. E.g. with buffer=120s, ID fires at :58.
        if ($secondsUntil > $buffer || $secondsUntil > self::HOUR_SECONDS / 2) {
            return false;
        }

        return !$this->hasTopOfHourIdQueued($station, $targetLocal, $tz);
    }

    /**
     * True only just after an hour boundary, when the interrupting Liquidsoap queue
     * may preempt normal playback for the mandatory legal ID.
     */
    public function isTopOfHourInterruptDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');
        $secondsAfterTop = $local->getTimestamp() - $hourStart->getTimestamp();
        $tolerance = $this->getComplianceToleranceSeconds($station);

        // Fire BEFORE the hour, not only after it.
        //
        // This is the core timing fix. Every other path that tries to place the
        // ID (QueueBuilder's cap at selection time, HourBoundaryAnnotator's
        // safety net at annotation time) computes against a clock that is not
        // the track's real airtime: selection happens when the queue is built
        // (up to the AutoDJ time-lookahead ahead of play, so its projection
        // drifts), and annotation happens roughly one track before the track
        // actually airs. A song can therefore be selected AND annotated while
        // both checks honestly see "plenty of room before :00", then actually
        // start at :58 and run straight through the boundary.
        //
        // This task, by contrast, runs once a minute against real wall-clock
        // time -- it is the only place in the system that knows what time it
        // actually is right now. So it is the right place to guarantee the ID.
        // Previously it only looked at [:00:00, :00+tolerance], which meant it
        // could only ever react AFTER the boundary was already missed, landing
        // the ID at :00 on top of a song that was still playing (where Smart
        // Ducking overlays it and the song resumes underneath, instead of the
        // song ending and the ID taking over cleanly).
        //
        // Now it also fires during the finish-buffer window before :00 -- the
        // same buffer the rest of the system already reserves for the ID -- so
        // the ID is pushed while there is still room for it to complete before
        // the hour turns. The post-:00 tolerance window is kept as a last-ditch
        // catch for the case where even this was missed.
        $nextTop = CarbonImmutable::instance(
            $this->getNextTopOfHour($expectedPlayTime, $tz)
        )->setTimezone($tz);
        $secondsUntilNextTop = $nextTop->getTimestamp() - $local->getTimestamp();
        $preWindow = $this->getFinishBufferSeconds($station) + $this->getIdMaxSeconds($station);

        if ($secondsUntilNextTop > 0 && $secondsUntilNextTop <= $preWindow) {
            // Inside the pre-:00 window: the ID we care about is the one for the
            // UPCOMING hour boundary, not the one that already passed.
            return !$this->hasTopOfHourIdQueued($station, $nextTop, $tz);
        }

        if ($secondsAfterTop < 0 || $secondsAfterTop > $tolerance) {
            return false;
        }

        // Use the rollover-aware check rather than a naive "was an ID cued
        // between :00:00 and :00:+tolerance" window.
        //
        // A correctly-scheduled ID for the 7:00 boundary AIRS BEFORE 7:00 -- at
        // :59:00 or so, by design, because the whole point of the finish buffer
        // is that the ID has completed by the time the hour turns. A window
        // anchored at [hourStart, hourStart+tolerance] can therefore never see
        // it: an on-time ID is, by wall clock, in the *previous* hour. The old
        // check only appeared to work when IDs were drifting late into
        // :00-:00:10 -- i.e. precisely when they were NOT on time. Once
        // top-of-hour timing was tightened up so IDs reliably land at :59, this
        // check began firing a duplicate interrupting ID every single hour,
        // cutting into whatever song had started after the real one.
        //
        // hasTopOfHourIdQueued() resolves each candidate through
        // resolveTopOfHourExpectedPlayAt(), so a :59 play is correctly
        // attributed to the boundary it actually serves, and it checks both
        // already-aired history and the still-unplayed queue.
        return !$this->hasTopOfHourIdQueued($station, $hourStart, $tz);
    }

    /**
     * When station-wide top-of-hour protection is on, legacy once-per-hour playlists
     * pinned to minute :00 are suppressed — {@see TopOfHourIdScheduler} queues legal_id instead.
     */
    public function shouldSuppressOncePerHourPlaylist(StationPlaylist $playlist): bool
    {
        if (!$this->isTopOfHourProtectionEnabled($playlist->station)) {
            return false;
        }

        return $playlist->type === PlaylistTypes::OncePerHour
            && $playlist->play_per_hour_minute === 0;
    }

    public function hasTopOfHourIdQueued(
        Station $station,
        CarbonImmutable $hourStart,
        ?DateTimeZone $tz = null,
    ): bool {
        $targetTimestamp = $hourStart->getTimestamp();

        // Check playback history first: if the mandatory ID for this hour has
        // ALREADY AIRED, it's no longer in the unplayed queue scanned below, so
        // without this check a later re-evaluation (e.g. the once-a-minute
        // interrupt-fallback tick, or a queue slot whose expected-play-time still
        // resolves to this same boundary) would wrongly conclude nothing has been
        // queued yet and insert a second, duplicate ID for the same hour.
        //
        // Window is +/-70 minutes around the boundary: wide enough to catch an
        // on-time ID that aired up to ~10 minutes early (the lookahead window) in
        // the *previous* wall-clock hour, without pulling in the adjacent hours'
        // own IDs. Each candidate is then re-resolved through the exact same
        // resolveTopOfHourExpectedPlayAt() rollover math used for unplayed rows
        // below, so a :58/:59 play is correctly attributed to the hour it serves.
        $historyWindowStart = $hourStart->subMinutes(70)->toDateTimeImmutable();
        $historyWindowEnd = $hourStart->addMinutes(70)->toDateTimeImmutable();

        foreach (
            $this->queueRepo->getRecentlyPlayedTopOfHourLegalIds(
                $station,
                $historyWindowStart,
                $historyWindowEnd,
            ) as $playedAt
        ) {
            $servedBoundary = $this->resolveTopOfHourExpectedPlayAt($station, $playedAt);

            if ($servedBoundary->getTimestamp() === $targetTimestamp) {
                return true;
            }
        }

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            $isLegalId = $row->top_of_hour_legal_id;

            if (!$isLegalId) {
                $media = $row->media;
                $isLegalId = $media !== null && StationMediaTypes::isStationId($media->type);
            }

            if (!$isLegalId) {
                continue;
            }

            // timestamp_played is null while a row is unplayed, so it cannot be
            // used to locate an already-queued ID. Derive the boundary this ID
            // serves from its cue time instead: a top-of-hour ID is cued to air
            // within ~a minute of the :00 it protects. Reusing
            // resolveTopOfHourExpectedPlayAt() keeps this in lockstep with the
            // boundary the scheduler is currently targeting.
            $servedBoundary = $this->resolveTopOfHourExpectedPlayAt($station, $row->timestamp_cued);

            if ($servedBoundary->getTimestamp() === $targetTimestamp) {
                return true;
            }
        }

        return false;
    }

    private function clampInt(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
