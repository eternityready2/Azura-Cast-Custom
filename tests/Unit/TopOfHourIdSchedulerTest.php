<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\TopOfHourIdScheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class TopOfHourIdSchedulerTest extends Unit
{
    private Module $testsModule;

    private TopOfHourIdScheduler $scheduler;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->scheduler = $testsModule->container->get(TopOfHourIdScheduler::class);
    }

    public function testTopOfHourAdvanceQueuingFiresWithinBufferWindow(): void
    {
        [$station, $media] = $this->persistStationWithId();

        try {
            // Well outside the pre-:00 buffer window (finish_buffer=15s + id_max=60s = 75s
            // by default) -- normal (non-interrupting) advance queuing should not fire yet.
            $tooEarly = CarbonImmutable::parse('2026-05-26 09:30:00', 'UTC');
            $tooEarlyEvent = new BuildQueue($station, $tooEarly, $tooEarly);
            $this->scheduler->buildTopOfHourId($tooEarlyEvent);

            self::assertSame([], $tooEarlyEvent->getNextSongs());

            // Inside the buffer window, normal (non-interrupting) advance queuing is what
            // actually places the ID into the ordinary AutoDJ queue ahead of time -- this
            // is the path that makes top-of-hour compliance work at all; relying solely on
            // the narrow interrupting-queue window below is what caused it to be unreliable.
            $withinBuffer = CarbonImmutable::parse('2026-05-26 09:59:59', 'UTC');
            $normalEvent = new BuildQueue($station, $withinBuffer, $withinBuffer);
            $this->scheduler->buildTopOfHourId($normalEvent);

            self::assertCount(1, $normalEvent->getNextSongs());
            $selected = $normalEvent->getNextSongs()[0];
            self::assertTrue($selected->top_of_hour_legal_id);
            self::assertSame($media->id, $selected->media?->id);
            self::assertSame(1, $this->countLegalIdQueueRows($station));
            self::assertSame(1, $this->countLegalIdAuditRows($station));
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testRejectedSameSongLegalIdLeavesNoGhostQueueRowOrAuditEvent(): void
    {
        [$station, $media] = $this->persistStationWithId();

        try {
            // Reproduce the midnight failure mode from Sept. 6: the mandatory ID
            // resolver returns the only legal ID, but BuildQueue rejects it because
            // Liquidsoap reports that same song as the immediately previous item.
            $atTop = CarbonImmutable::parse('2026-09-06 00:00:00', 'UTC');
            $event = new BuildQueue(
                $station,
                $atTop,
                $atTop,
                $media->song_id,
            );

            $this->scheduler->buildTopOfHourId($event);

            self::assertSame([], $event->getNextSongs());

            // A rejected candidate never became playable, so it must not survive as
            // a protected legal-ID row or a "queued" audit event. Ghost rows here can
            // outrank/restrict a rigid scheduled programme at the same clock boundary.
            self::assertSame(0, $this->countLegalIdQueueRows($station));
            self::assertSame(0, $this->countLegalIdAuditRows($station));
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testTopOfHourInterruptingFallbackFiresAtHourBoundary(): void
    {
        [$station, $media] = $this->persistStationWithId();

        try {
            // Safety-net path: used when advance queuing above didn't already place the ID
            // (e.g. the queue was empty going into the hour, or the station just started).
            $atTop = CarbonImmutable::parse('2026-05-26 10:00:05', 'UTC');
            $interruptingEvent = new BuildQueue($station, $atTop, $atTop, null, true);
            $this->scheduler->buildTopOfHourId($interruptingEvent);

            self::assertCount(1, $interruptingEvent->getNextSongs());
            $selected = $interruptingEvent->getNextSongs()[0];
            self::assertTrue($selected->top_of_hour_legal_id);
            self::assertSame($media->id, $selected->media?->id);
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testInterruptingTopOfHourDoesNotFireOutsideTolerance(): void
    {
        [$station, $media] = $this->persistStationWithId();

        try {
            $late = CarbonImmutable::parse('2026-05-26 10:00:11', 'UTC');
            $event = new BuildQueue($station, $late, $late, null, true);
            $this->scheduler->buildTopOfHourId($event);

            self::assertSame([], $event->getNextSongs());
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    /** @return array{Station, StationMedia} */
    private function persistStationWithId(): array
    {
        $em = $this->testsModule->em;

        $station = new Station();
        $station->name = 'TOPH Scheduler Test';
        $station->short_name = 'toph_scheduler_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $backendConfig = $station->backend_config;
        $backendConfig->top_of_hour_id_enabled = true;
        $backendConfig->top_of_hour_compliance_tolerance_seconds = 10;
        $station->backend_config = $backendConfig;

        $media = new StationMedia($station->media_storage_location, '/scheduler-id-' . uniqid() . '.mp3');
        $media->title = 'Station ID';
        $media->artist = 'Station';
        $media->type = StationMediaTypes::ID;
        $media->length = 30.0;
        $media->updateMetaFields();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->persist($media);
        $em->flush();

        return [$station, $media];
    }

    private function countLegalIdQueueRows(Station $station): int
    {
        return (int)$this->testsModule->em->createQuery(
            <<<'DQL'
                SELECT COUNT(q.id)
                FROM App\Entity\StationQueue q
                WHERE q.station = :station
                AND q.top_of_hour_legal_id = true
            DQL
        )->setParameter('station', $station)
            ->getSingleScalarResult();
    }

    private function countLegalIdAuditRows(Station $station): int
    {
        return (int)$this->testsModule->em->createQuery(
            <<<'DQL'
                SELECT COUNT(e.id)
                FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station
                AND e.anchor_type = :anchor
            DQL
        )->setParameter('station', $station)
            ->setParameter('anchor', 'legal_id')
            ->getSingleScalarResult();
    }

    private function removeTestEntities(Station $station, StationMedia $media): void
    {
        $em = $this->testsModule->em;
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->createQuery('DELETE FROM App\\Entity\\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();
        $em->createQuery('DELETE FROM App\\Entity\\ClockWheelEvent e WHERE e.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->removeIfManaged($em, $media);
        $this->removeIfManaged($em, $station);
        $this->removeIfManaged($em, $station->media_storage_location);
        $this->removeIfManaged($em, $station->recordings_storage_location);
        $this->removeIfManaged($em, $station->podcasts_storage_location);
        $em->flush();
    }

    private function removeIfManaged(ReloadableEntityManagerInterface $em, object $entity): void
    {
        if ($em->contains($entity)) {
            $em->remove($entity);
        }
    }
}
