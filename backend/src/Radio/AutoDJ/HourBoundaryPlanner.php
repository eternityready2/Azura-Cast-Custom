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

    /** Preferred legal-ID planning window begins at :58. */
    public const int DEFAULT_ID_WINDOW_SECONDS = 120;

    /**
     * Normal music should only reserve the last minute by default. The wider
     * :58/:59 window remains available to the TOH coordinator for scheduled
     * boundaries and fallback handling, but ordinary music is no longer forced
     * to end at :58 when the next hour is otherwise open.
     */
    public const int DEFAULT_MUSIC_PROTECTION_SECONDS = 60;

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
     * Seconds reserved at the end of the outgoing hour for legal-ID planning.
     * The planning window begins at :58 by default and expands when configuration
     * needs more time for the ID plus finish buffer.
     */
    public function getIdWindowLeadSeconds(Station $station): int
    {
        return max(
            self::DEFAULT_ID_WINDOW_SECONDS,
            $this->getIdMaxSeconds($station) + $this->getFinishBufferSeconds($station),
        );
    }

    public function getMusicProtectionLeadSeconds(Station $station): int
    {
        return max(
            self::DEFAULT_MUSIC_PROTECTION_SECONDS,
            $this->getFinishBufferSeconds($station),
        );
    }

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

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );
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
     * Maximum music duration before the late-hour protection reserve begins.
     * Returns null outside the lookahead window or once the reserve is open.
     *
     * The full :58/:59 ID planning window is intentionally not used here: doing
     * so caused ordinary music to be shortened at :58 even when the next hour
     * had no scheduled content. Runtime TOH ownership still uses the wider window.
     */
    public function secondsAvailableForMusicBeforeTopOfHour(
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

        return max(
            0.0,
            (float)($secondsUntil - $this->getMusicProtectionLeadSeconds($station)),
        );
    }

    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        $availableSeconds = $this->secondsAvailableForMusicBeforeTopOfHour(
            $station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds || $availableSeconds < self::MIN_USABLE_CAP_SECONDS) {
            return null;
        }

        return $availableSeconds;
    }

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

    public function isTopOfHourInterruptDue(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        return $this->isTopOfHourIdDue($station, $expectedPlayTime);
    }

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
