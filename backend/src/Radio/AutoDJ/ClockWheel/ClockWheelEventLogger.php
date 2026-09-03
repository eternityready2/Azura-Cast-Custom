<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Service\StationDiagnostics;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists clock wheel audit rows (PR11). Callers flush the entity manager.
 */
final class ClockWheelEventLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public function recordTrackQueued(
        Station $station,
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        StationMedia $media,
        DateTimeImmutable $expectedPlayAt,
        int $secondsIntoHour,
        bool $separationRelaxed = false,
        bool $burnRateWarning = false,
        ?StationQueue $queueRow = null,
        ?DateTimeImmutable $legalIdExpectedPlayAt = null,
    ): void {
        $logExpectedPlayAt = $legalIdExpectedPlayAt ?? $expectedPlayAt;
        $event = $this->createBase($station, ClockWheelEventKind::TrackQueued, $logExpectedPlayAt);
        $event->clock_wheel = $wheel;
        $event->slot = $slot;
        $event->media = $media;
        $event->station_queue = $queueRow;
        $event->anchor_type = $slot->type?->value;

        if ($legalIdExpectedPlayAt instanceof DateTimeImmutable) {
            $event->drift_seconds = 0;
        } else {
            $event->drift_seconds = $this->computeDriftSeconds($secondsIntoHour, $slot->position_seconds);
        }

        $event->separation_relaxed = $separationRelaxed;
        $event->burn_rate_warning = $burnRateWarning;

        if ($separationRelaxed || $burnRateWarning) {
            $this->diagnostics->warning(
                $station,
                'Clock Wheels',
                'Clock Wheel queued media with a scheduling safeguard relaxed.',
                [
                    'wheel' => $wheel->name,
                    'anchor' => $slot->type?->value,
                    'separation_relaxed' => $separationRelaxed,
                    'burn_rate_warning' => $burnRateWarning,
                    'expected_play_at' => $logExpectedPlayAt->format(DATE_ATOM),
                ]
            );
        }

        $this->em->persist($event);
    }

    public function recordDeferred(
        Station $station,
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        DateTimeImmutable $expectedPlayAt,
        ClockWheelFallbackReason $reason,
        int $secondsIntoHour,
    ): void {
        $event = $this->createBase($station, ClockWheelEventKind::Deferred, $expectedPlayAt);
        $event->clock_wheel = $wheel;
        $event->slot = $slot;
        $event->fallback_reason = $reason;
        $event->anchor_type = $slot->type?->value;
        $event->drift_seconds = $this->computeDriftSeconds($secondsIntoHour, $slot->position_seconds);

        $this->diagnostics->warning(
            $station,
            'Clock Wheels',
            'Clock Wheel slot was deferred.',
            [
                'wheel' => $wheel->name,
                'anchor' => $slot->type?->value,
                'reason' => $reason->value,
                'expected_play_at' => $expectedPlayAt->format(DATE_ATOM),
            ]
        );

        $this->em->persist($event);
    }

    public function recordTopOfHourLegalIdQueued(
        Station $station,
        StationMedia $media,
        DateTimeImmutable $expectedPlayAt,
        ?StationQueue $queueRow = null,
    ): void {
        $event = $this->createBase($station, ClockWheelEventKind::TrackQueued, $expectedPlayAt);
        $event->media = $media;
        $event->station_queue = $queueRow;
        $event->anchor_type = 'legal_id';
        $event->drift_seconds = 0;

        $this->em->persist($event);
    }

    public function recordTopOfHourFallback(
        Station $station,
        DateTimeImmutable $expectedPlayAt,
        ClockWheelFallbackReason $reason,
    ): void {
        $event = $this->createBase($station, ClockWheelEventKind::Fallback, $expectedPlayAt);
        $event->fallback_reason = $reason;
        $event->anchor_type = 'legal_id';

        $this->diagnostics->warning(
            $station,
            'Clock Wheels',
            'Top-of-hour legal ID used fallback behavior.',
            [
                'reason' => $reason->value,
                'expected_play_at' => $expectedPlayAt->format(DATE_ATOM),
            ]
        );

        $this->em->persist($event);
    }

    public function recordFallback(
        Station $station,
        ?StationClockWheel $wheel,
        ?StationClockWheelSlot $slot,
        DateTimeImmutable $expectedPlayAt,
        ClockWheelFallbackReason $reason,
        ?int $secondsIntoHour = null,
    ): void {
        $event = $this->createBase($station, ClockWheelEventKind::Fallback, $expectedPlayAt);
        $event->clock_wheel = $wheel;
        $event->slot = $slot;
        $event->fallback_reason = $reason;

        if ($slot !== null) {
            $event->anchor_type = $slot->type?->value;
            if ($secondsIntoHour !== null) {
                $event->drift_seconds = $this->computeDriftSeconds($secondsIntoHour, $slot->position_seconds);
            }
        }

        $this->diagnostics->warning(
            $station,
            'Clock Wheels',
            'Clock Wheel used fallback behavior.',
            [
                'wheel' => $wheel?->name,
                'anchor' => $slot?->type?->value,
                'reason' => $reason->value,
                'expected_play_at' => $expectedPlayAt->format(DATE_ATOM),
            ]
        );

        $this->em->persist($event);
    }

    private function createBase(
        Station $station,
        ClockWheelEventKind $kind,
        DateTimeImmutable $expectedPlayAt,
    ): ClockWheelEvent {
        $event = new ClockWheelEvent();
        $event->setStation($station);
        $event->event_kind = $kind;
        $event->event_timestamp = new DateTimeImmutable('now', $station->getTimezoneObject());
        $event->expected_play_at = $expectedPlayAt;

        return $event;
    }

    private function computeDriftSeconds(int $secondsIntoHour, int $anchorPositionSeconds): int
    {
        return $secondsIntoHour - $anchorPositionSeconds;
    }
}
