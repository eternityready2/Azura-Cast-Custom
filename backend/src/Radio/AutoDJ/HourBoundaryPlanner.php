<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Compatibility facade for shared hour math used by Clock Wheels, reporting and
 * scheduled-boundary protection.
 *
 * Automatic Top-of-Hour Station ID planning remains in TopOfHourClock, while
 * operational staging and exact wall-clock playout are owned by the top_of_hour
 * plugin. This compatibility class has no TOH delivery or runtime authority.
 */
final class HourBoundaryPlanner
{
    public const int HOUR_SECONDS = 3600;

    public const int DEFAULT_LOOKAHEAD_MINUTES = TopOfHourClock::DEFAULT_LOOKAHEAD_MINUTES;
    public const int DEFAULT_COMPLIANCE_TOLERANCE_SECONDS = TopOfHourClock::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS;
    public const int DEFAULT_ID_MAX_SECONDS = TopOfHourClock::DEFAULT_ID_MAX_SECONDS;

    public const int MIN_LOOKAHEAD_MINUTES = TopOfHourClock::MIN_LOOKAHEAD_MINUTES;
    public const int MAX_LOOKAHEAD_MINUTES = TopOfHourClock::MAX_LOOKAHEAD_MINUTES;
    public const int MIN_COMPLIANCE_TOLERANCE_SECONDS = TopOfHourClock::MIN_COMPLIANCE_TOLERANCE_SECONDS;
    public const int MAX_COMPLIANCE_TOLERANCE_SECONDS = TopOfHourClock::MAX_COMPLIANCE_TOLERANCE_SECONDS;
    public const int MIN_ID_MAX_SECONDS = TopOfHourClock::MIN_ID_MAX_SECONDS;
    public const int MAX_ID_MAX_SECONDS = TopOfHourClock::MAX_ID_MAX_SECONDS;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly TopOfHourClock $topOfHourClock,
    ) {
    }

    public function isTopOfHourProtectionEnabled(Station $station): bool
    {
        return $this->topOfHourClock->isEnabled($station);
    }

    public function getComplianceToleranceSeconds(Station $station): int
    {
        return $this->topOfHourClock->getComplianceToleranceSeconds($station);
    }

    public function getLookaheadMinutes(Station $station): int
    {
        return $this->topOfHourClock->getLookaheadMinutes($station);
    }

    public function getIdMaxSeconds(Station $station): int
    {
        return $this->topOfHourClock->getIdMaxSeconds($station);
    }

    /**
     * Planned position within the broadcast hour (0-3599), including rows that
     * have already been projected by the Linear Log / AutoDJ queue.
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
            if (null === $playedAt) {
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
            $queuedEndOffset = $queuedStartOffset + (int)ceil((float)($row->duration ?? 0.0));
            $seconds = max($seconds, min($queuedEndOffset, self::HOUR_SECONDS - 1));
        }

        return min(max(0, $seconds), self::HOUR_SECONDS - 1);
    }

    public function getNextTopOfHour(
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): DateTimeImmutable {
        $local = CarbonImmutable::instance($expectedPlayTime)->setTimezone($tz);
        $hourStart = $local->startOf('hour');

        return $local->greaterThan($hourStart)
            ? $hourStart->addHour()->toDateTimeImmutable()
            : $hourStart->toDateTimeImmutable();
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

        return $secondsUntil > 0
            && $secondsUntil <= ($this->getLookaheadMinutes($station) * 60);
    }

    /**
     * Retired TOH-specific music-cap hook.
     *
     * QueueBuilder still calls this compatibility method, but it intentionally
     * returns null so no legacy pre-ID music cap can execute. TOH and rigid
     * deadlines are intentionally not ordinary AutoDJ squeeze anchors; the
     * top_of_hour plugin owns the final fade, takeover and hard-boundary handoff.
     */
    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        return null;
    }

    /**
     * Legacy once-per-hour playlists are normal programming again. The rebuilt
     * station ID feature never suppresses or silently repurposes them.
     */
    public function shouldSuppressOncePerHourPlaylist(StationPlaylist $playlist): bool
    {
        return false;
    }
}
