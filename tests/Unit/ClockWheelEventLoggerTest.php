<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\ClockWheelEvent;
use App\Entity\Enums\ClockWheelEventKind;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Tests\Module;
use Codeception\Test\Unit;
use DateTimeImmutable;

final class ClockWheelEventLoggerTest extends Unit
{
    private Module $testsModule;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
    }

    protected function _before(): void
    {
        $this->station = $this->persistStation($this->testsModule->em);
    }

    protected function _after(): void
    {
        $em = $this->testsModule->em;
        $em->createQuery('DELETE FROM App\Entity\ClockWheelEvent e WHERE e.station = :station')
            ->setParameter('station', $this->station)
            ->execute();
        $em->remove($this->station);
        $em->remove($this->station->media_storage_location);
        $em->remove($this->station->recordings_storage_location);
        $em->remove($this->station->podcasts_storage_location);
        $em->flush();
    }

    public function testRecordTrackQueuedPersistsAuditRow(): void
    {
        $em = $this->testsModule->em;
        $logger = new ClockWheelEventLogger($em);

        $wheel = new StationClockWheel($this->station);
        $wheel->name = 'Test Wheel';
        $em->persist($wheel);

        $slot = new StationClockWheelSlot($wheel);
        $slot->type = ClockWheelSlotTypes::Music;
        $slot->position_seconds = 120;
        $em->persist($slot);

        $media = new StationMedia($this->station->media_storage_location, '/audit_test.mp3');
        $media->title = 'Audit Track';
        $media->artist = 'Artist';
        $media->type = 'music';
        $media->length = 180.0;
        $media->mtime = time();
        $media->uploaded_at = time();
        $em->persist($media);
        $em->flush();

        $expected = new DateTimeImmutable('2026-05-29 10:05:00', $this->station->getTimezoneObject());
        $logger->recordTrackQueued($this->station, $wheel, $slot, $media, $expected, 185);
        $em->flush();

        $rows = $em->getRepository(ClockWheelEvent::class)->findBy(['station' => $this->station]);
        self::assertCount(1, $rows);

        $event = $rows[0];
        self::assertSame(ClockWheelEventKind::TrackQueued, $event->event_kind);
        self::assertSame($wheel->id, $event->clock_wheel_id);
        self::assertSame($slot->id, $event->slot_id);
        self::assertSame($media->id, $event->media_id);
        self::assertSame('music', $event->anchor_type);
        self::assertSame(65, $event->drift_seconds);
        self::assertFalse($event->separation_relaxed);
        self::assertFalse($event->burn_rate_warning);
    }

    public function testRecordFallbackPersistsReason(): void
    {
        $em = $this->testsModule->em;
        $logger = new ClockWheelEventLogger($em);

        $expected = new DateTimeImmutable('now', $this->station->getTimezoneObject());
        $logger->recordFallback(
            $this->station,
            null,
            null,
            $expected,
            ClockWheelFallbackReason::ScheduleConflict,
        );
        $em->flush();

        $event = $em->getRepository(ClockWheelEvent::class)->findOneBy(['station' => $this->station]);
        self::assertInstanceOf(ClockWheelEvent::class, $event);
        self::assertSame(ClockWheelEventKind::Fallback, $event->event_kind);
        self::assertSame(ClockWheelFallbackReason::ScheduleConflict, $event->fallback_reason);
    }

    private function persistStation(\App\Doctrine\ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Audit Logger Test';
        $station->short_name = 'audit_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->flush();

        return $station;
    }
}
