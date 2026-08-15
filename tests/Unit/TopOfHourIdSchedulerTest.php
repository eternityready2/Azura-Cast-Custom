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

    public function testTopOfHourUsesInterruptingQueueOnlyAtHourBoundary(): void
    {
        [$station, $media] = $this->persistStationWithId();

        try {
            $beforeTop = CarbonImmutable::parse('2026-05-26 09:59:59', 'UTC');
            $normalEvent = new BuildQueue($station, $beforeTop, $beforeTop);
            $this->scheduler->buildTopOfHourId($normalEvent);

            self::assertSame([], $normalEvent->getNextSongs());

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

    private function removeTestEntities(Station $station, StationMedia $media): void
    {
        $em = $this->testsModule->em;
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->createQuery('DELETE FROM App\\Entity\\StationQueue sq WHERE sq.station = :station')
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
