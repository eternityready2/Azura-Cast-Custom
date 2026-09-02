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

    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = 10;

    public const int DEFAULT_ID_MAX_SECONDS = 60;

    public const int MIN_LOOKAHEAD_MINUTES = 1;

    public const int MAX_LOOKAHEAD_MINUTES = 30;

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
     * Max music duration before the legal-ID window begins.
     *
     * The ID window opens five seconds before minute :59. Selection tries to
     * land there naturally; if no media can fit, callers may let the current
     * song finish rather than truncating listener-facing audio.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return null;
        }

        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $nextTop = CarbonImmutable::instance($this->getNextTopOfHour($expectedPlayTime, $tz));
        $windowStart = $nextTop->subMinute()->subSeconds(self::ID_EARLY_ALLOWANCE_SECONDS);
        $secondsUntilWindow = $windowStart->getTimestamp() - $local->getTimestamp();
        $lookaheadSeconds = $this->getLookaheadMinutes($station) * 60;

        if ($secondsUntilWindow <= 0 || $secondsUntilWindow > $lookaheadSeconds) {
            return null;
        }

        return $secondsUntilWindow >= self::MIN_USABLE_CAP_SECONDS
            ? (float)$secondsUntilWindow
            : null;
    }

    private const int ID_EARLY_ALLOWANCE_SECONDS = 5;

    private const float MIN_USABLE_CAP_SECONDS = 15.0;

    /**
     * True when AutoDJ should queue the mandatory legal ID for this projected slot.
     *
     * The normal target is minute :59, with a five-second early allowance to
     * absorb crossfade/prefetch drift. A small post-:00 grace window uses the
     * station's compliance tolerance, but still queues normally and never
     * interrupts a song already on air.
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
        $hourStart = $local->startOf('hour');
        $minute = (int)$local->format('i');
        $second = (int)$local->format('s');

        if ($minute === 58 && $second >= 60 - self::ID_EARLY_ALLOWANCE_SECONDS) {
            $targetTop = $hourStart->addHour();
        } elseif ($minute === 59) {
            $targetTop = $hourStart->addHour();
        } elseif ($minute === 0 && $second <= $this->getComplianceToleranceSeconds($station)) {
            $targetTop = $hourStart;
        } else {
            return false;
        }

        return !$this->hasTopOfHourIdQueued($station, $targetTop, $tz);
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
        // without this check a later queue build whose expected play time still
        // resolves to this boundary could wrongly conclude nothing has been queued
        // yet and insert a duplicate ID for the same hour.
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
