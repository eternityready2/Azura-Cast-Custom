<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Station;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Measures station-wide legal-ID compliance against the protected end-of-hour window.
 */
final class TopOfHourComplianceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    /**
     * @return array{
     *     tolerance_seconds: int,
     *     hours_with_legal_id: int,
     *     on_time_count: int,
     *     late_count: int,
     *     compliance_percent: float|null,
     *     late_events: array<int, array{
     *         expected_play_at: string,
     *         actual_play_at: string|null,
     *         drift_seconds: int|null,
     *         media_id: int|null
     *     }>,
     *     fallback_count: int
     * }
     */
    public function getSummary(
        Station $station,
        DateTimeImmutable $since,
        int $toleranceSeconds = 10,
    ): array {
        $toleranceSeconds = max(0, min(60, $toleranceSeconds));

        /** @var ClockWheelEvent[] $events */
        $events = $this->em->createQuery(
            <<<'DQL'
                SELECT e, m, q FROM App\Entity\ClockWheelEvent e
                LEFT JOIN e.media m
                LEFT JOIN e.station_queue q
                WHERE e.station = :station
                AND e.clock_wheel IS NULL
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
                AND e.actual_play_at IS NOT NULL
                ORDER BY e.actual_play_at DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('kind', ClockWheelEventKind::TrackQueued)
            ->getResult();

        $onTime = 0;
        $late = 0;
        $lateEvents = [];
        $windowLead = $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station);
        $finishBuffer = $this->hourBoundaryPlanner->getFinishBufferSeconds($station);
        $defaultDuration = $this->hourBoundaryPlanner->getIdMaxSeconds($station);

        foreach ($events as $event) {
            $expected = $event->expected_play_at;
            $actual = $event->actual_play_at;
            if (!$expected instanceof DateTimeImmutable || !$actual instanceof DateTimeImmutable) {
                continue;
            }

            $duration = $event->station_queue?->duration
                ?? $event->media?->getCalculatedLength()
                ?? (float)$defaultDuration;
            $durationSeconds = max(1, (int)ceil((float)$duration));

            $windowStart = $expected->sub(new DateInterval('PT' . $windowLead . 'S'));
            $latestSafeStart = $expected->sub(
                new DateInterval('PT' . ($durationSeconds + $finishBuffer) . 'S')
            );
            $earliestAccepted = $windowStart->sub(
                new DateInterval('PT' . $toleranceSeconds . 'S')
            );

            $actualTs = $actual->getTimestamp();
            $earliestTs = $earliestAccepted->getTimestamp();
            $latestTs = $latestSafeStart->getTimestamp();

            if ($actualTs >= $earliestTs && $actualTs <= $latestTs) {
                $onTime++;
                continue;
            }

            $late++;
            if (count($lateEvents) >= 50) {
                continue;
            }

            $windowDrift = $actualTs < $earliestTs
                ? $actualTs - $earliestTs
                : $actualTs - $latestTs;

            $lateEvents[] = [
                'expected_play_at' => $expected->format(DateTimeImmutable::ATOM),
                'actual_play_at' => $actual->format(DateTimeImmutable::ATOM),
                'drift_seconds' => $windowDrift,
                'media_id' => $event->media_id,
            ];
        }

        $fallbackCount = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id) FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.clock_wheel IS NULL
                AND e.event_timestamp >= :since
                AND e.anchor_type = :anchor
                AND e.event_kind = :kind
            DQL
        )->setParameter('station', $station)
            ->setParameter('since', $since)
            ->setParameter('anchor', 'legal_id')
            ->setParameter('kind', ClockWheelEventKind::Fallback)
            ->getSingleScalarResult();

        $total = $onTime + $late;

        return [
            'tolerance_seconds' => $toleranceSeconds,
            'hours_with_legal_id' => $total,
            'on_time_count' => $onTime,
            'late_count' => $late,
            'compliance_percent' => $total > 0
                ? round(($onTime / $total) * 100, 1)
                : null,
            'late_events' => $lateEvents,
            'fallback_count' => $fallbackCount,
        ];
    }
}
