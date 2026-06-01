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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists clock wheel audit rows (PR11). Callers flush the entity manager.
 */
final class ClockWheelEventLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function recordTrackQueued(
        Station $station,
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        StationMedia $media,
        DateTimeImmutable $expectedPlayAt,
        int $secondsIntoHour,
    ): void {
        $event = $this->createBase($station, ClockWheelEventKind::TrackQueued, $expectedPlayAt);
        $event->clock_wheel = $wheel;
        $event->slot = $slot;
        $event->media = $media;
        $event->anchor_type = $slot->type?->value;
        $event->drift_seconds = $this->computeDriftSeconds($secondsIntoHour, $slot->position_seconds);

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
