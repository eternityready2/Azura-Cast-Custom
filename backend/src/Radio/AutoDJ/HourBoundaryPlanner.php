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

    /** Preferred legal-ID window begins at :58. */
    public const int DEFAULT_ID_WINDOW_SECONDS = 120;

    public const int MIN_LOOKAHEAD_MINUTES = 1;

    public const int MAX_LOOKAHEAD_MINUTES = 30;

    public const int MIN_FINISH_BUFFER_SECONDS = 0;

    public const int MAX_FINISH_BUFFER_SECONDS = 120;

    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = 1;

    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = 60;

    public const int MIN_ID_MAX_SECONDS = 15;

    public const int MAX_ID_MAX_SECONDS = 120;

    private const float MIN_USABLE_CAP_SECONDS = 15.0;

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
     * Seconds reserved at the end of the outgoing hour for the legal ID.
     *
     * The normal window starts at :58. If the configured maximum ID length plus
     * finish buffer needs more than two minutes, the reservation expands so the
     * configured ID can still finish before :00.
     */
    public function getIdWindowLeadSeconds(Station $station): int
    {
        return max(
            self::DEFAULT_ID_WINDOW_SECONDS,
            $this->getIdMaxSeconds($station) + $this->getFinishBufferSeconds($station),
        );
    }

    /**
     * Planned position within the broadcast hour (0-3599), using expected play
     * time and already-queued items in the same hour.
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
     * Hour boundary served by a legal ID at the supplied time.
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

    public function isInTopOfHourIdWindow(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isTopOfHourProtectionEnabled($station)) {
            return false;
        }

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );

        return $secondsUntil > 0 && $secondsUntil <= $this->getIdWindowLeadSeconds($station);
    }

    /**
     * Returns whether the selected ID can finish before :00 while preserving the
     * configured finish buffer. Unknown durations use the configured maximum.
     */
    public function canLegalIdFinishBeforeTop(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        ?float $durationSeconds,
    ): bool {
        if (!$this->isInTopOfHourIdWindow($station, $expectedPlayTime)) {
            return false;
        }

        $duration = ($durationSeconds !== null && $durationSeconds > 0)
            ? $durationSeconds
            : (float)$this->getIdMaxSeconds($station);

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );
        $requiredSeconds = (int)ceil($duration) + $this->getFinishBufferSeconds($station);

        return $secondsUntil >= $requiredSeconds;
    }

    /**
     * Maximum music duration before the protected legal-ID window begins.
     * Returns null outside the lookahead window or once the ID window is open.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            return null;
        }

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );
        $maxDuration = (float)($secondsUntil - $this->getIdWindowLeadSeconds($station));

        if ($maxDuration < self::MIN_USABLE_CAP_SECONDS) {
            return null;
        }

        return $maxDuration;
    }

    /**
     * True while the outgoing hour is inside the preferred legal-ID window and
     * the boundary has not already been served.
     */
    public function isTopOfHourIdDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        if (!$this->isInTopOfHourIdWindow($station, $expectedPlayTime)) {
            return false;
        }

        $target = CarbonImmutable::instance(
            $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime),
        )->setTimezone($station->getTimezoneObject());

        return !$this->hasTopOfHourIdQueued($station, $target);
    }

    /**
     * Wall-clock fallback. It is intentionally pre-hour only; once :00 arrives,
     * the next hour's programming owns the clock.
     */
    public function isTopOfHourInterruptDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        return $this->isTopOfHourIdDue($station, $expectedPlayTime);
    }

    /**
     * When station-wide protection is enabled, legacy once-per-hour playlists
     * pinned to :00 are suppressed because the dedicated legal-ID scheduler owns
     * that boundary.
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
        $tz ??= $station->getTimezoneObject();
        $targetTimestamp = $hourStart->getTimestamp();

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

            if (!$isLegalId || $row->timestamp_cued === null) {
                continue;
            }

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
