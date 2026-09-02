<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistTypes;
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

        if ($secondsIntoHour > $this->getComplianceToleranceSeconds($station)) {
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
     * Preferred final-music duration before the legal ID.
     *
     * The target is exactly the start of minute :59. QueueBuilder uses this as
     * a scoring target rather than forcing every song to end before :59.
     */
    public function preferredMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        $window = $this->resolveMusicBacktimeWindow($station, $expectedPlayTime);

        return $window['preferred_seconds'] ?? null;
    }

    /**
     * Latest safe duration for a final music selection before the legal ID.
     *
     * Music may finish during minute :59 or inside the configured post-:00
     * compliance grace. This prevents the previous failure mode where a song
     * ending at :58:34 left a tiny unfillable gap, causing AutoDJ to launch a
     * full song that carried past the hour and skipped the ID entirely.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        $window = $this->resolveMusicBacktimeWindow($station, $expectedPlayTime);

        return $window['latest_seconds'] ?? null;
    }

    /**
     * @return array{preferred_seconds: float, latest_seconds: float}|null
     */
    private function resolveMusicBacktimeWindow(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?array {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return null;
        }

        $tz = $station->getTimezoneObject();
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $nextTop = CarbonImmutable::instance($this->getNextTopOfHour($expectedPlayTime, $tz));
        $preferredStart = $nextTop->subMinute();
        $latestStart = $nextTop->addSeconds($this->getComplianceToleranceSeconds($station));

        $preferredSeconds = $preferredStart->getTimestamp() - $local->getTimestamp();
        $latestSeconds = $latestStart->getTimestamp() - $local->getTimestamp();
        $lookaheadSeconds = $this->getLookaheadMinutes($station) * 60;

        if (
            $preferredSeconds <= 0
            || $preferredSeconds > $lookaheadSeconds
            || $latestSeconds < self::MIN_USABLE_CAP_SECONDS
        ) {
            return null;
        }

        return [
            'preferred_seconds' => (float)$preferredSeconds,
            'latest_seconds' => (float)$latestSeconds,
        ];
    }

    // Small last-resort early window. Normal backtiming should land the final
    // music inside minute :59; this only prevents an orphaned few seconds from
    // causing a full song to be launched across the boundary.
    private const int ID_EARLY_ALLOWANCE_SECONDS = 30;

    private const float MIN_USABLE_CAP_SECONDS = 15.0;

    /**
     * True when AutoDJ should queue the mandatory legal ID for this projected slot.
     *
     * The normal target is minute :59, with a 30-second early allowance to
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
        $secondsIntoHour = $local->getTimestamp() - $hourStart->getTimestamp();

        if ($minute === 58 && $second >= 60 - self::ID_EARLY_ALLOWANCE_SECONDS) {
            $targetTop = $hourStart->addHour();
        } elseif ($minute === 59) {
            $targetTop = $hourStart->addHour();
        } elseif ($secondsIntoHour <= $this->getComplianceToleranceSeconds($station)) {
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

        if ($this->queueRepo->hasTopOfHourLegalIdForBoundary($station, $hourStart->toDateTimeImmutable())) {
            return true;
        }

        // Compatibility for TOH rows created before exact boundary identity existed.
        $historyWindowStart = $hourStart->subMinutes(70)->toDateTimeImmutable();
        $historyWindowEnd = $hourStart->addMinutes(70)->toDateTimeImmutable();

        foreach (
            $this->queueRepo->getRecentlyPlayedTopOfHourLegalIds(
                $station,
                $historyWindowStart,
                $historyWindowEnd,
            ) as $cuedAt
        ) {
            $servedBoundary = $this->resolveLegacyTopOfHourBoundary($station, $cuedAt);

            if ($servedBoundary->getTimestamp() === $targetTimestamp) {
                return true;
            }
        }

        foreach ($this->queueRepo->getUnplayedQueue($station) as $row) {
            if (!$row->top_of_hour_legal_id || null !== $row->top_of_hour_expected_at) {
                continue;
            }

            $servedBoundary = $this->resolveLegacyTopOfHourBoundary($station, $row->timestamp_cued);

            if ($servedBoundary->getTimestamp() === $targetTimestamp) {
                return true;
            }
        }

        return false;
    }

    private function resolveLegacyTopOfHourBoundary(
        Station $station,
        DateTimeImmutable $referenceTime,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($referenceTime)->setTimezone($station->getTimezoneObject());
        $hourStart = $local->startOf('hour');
        $nextHour = $hourStart->addHour();

        $served = abs($local->getTimestamp() - $hourStart->getTimestamp())
            <= abs($nextHour->getTimestamp() - $local->getTimestamp())
            ? $hourStart
            : $nextHour;

        return $served->toDateTimeImmutable();
    }

    private function clampInt(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
