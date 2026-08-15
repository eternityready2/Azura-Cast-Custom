<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Radio\AutoDJ\HourBoundaryLegalIdResolver;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class HourBoundaryLegalIdResolverTest extends Unit
{
    private Module $testsModule;

    private HourBoundaryLegalIdResolver $resolver;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->resolver = $testsModule->container->get(HourBoundaryLegalIdResolver::class);
    }

    public function testOverlongTopOfHourIdStoresItsEffectiveCappedDuration(): void
    {
        [$station, $media] = $this->persistStationWithLongId();

        try {
            $queueEntry = $this->resolver->resolveMandatoryLegalId(
                $station,
                [],
                CarbonImmutable::parse('2026-05-26 09:58:45', 'UTC'),
            );

            self::assertNotNull($queueEntry);
            self::assertSame($media->id, $queueEntry->media?->id);
            self::assertTrue($queueEntry->top_of_hour_legal_id);
            self::assertTrue($queueEntry->hour_boundary_enforce_cap);
            self::assertSame(60, $queueEntry->hour_boundary_max_play_seconds);
            self::assertSame(60.0, $queueEntry->duration);
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    /** @return array{Station, StationMedia} */
    private function persistStationWithLongId(): array
    {
        $em = $this->testsModule->em;

        $station = new Station();
        $station->name = 'TOPH Legal ID Resolver Test';
        $station->short_name = 'toph_id_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $backendConfig = $station->backend_config;
        $backendConfig->top_of_hour_id_enabled = true;
        $backendConfig->top_of_hour_id_max_seconds = 60;
        $station->backend_config = $backendConfig;

        $media = new StationMedia($station->media_storage_location, '/long-id-' . uniqid() . '.mp3');
        $media->title = 'Long Station ID';
        $media->artist = 'Station';
        $media->type = StationMediaTypes::ID;
        $media->length = 90.0;
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
