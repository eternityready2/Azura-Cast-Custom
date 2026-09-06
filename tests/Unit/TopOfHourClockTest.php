<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourMode;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class TopOfHourClockTest extends Unit
{
    private Module $testsModule;

    private TopOfHourClock $clock;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->clock = $testsModule->container->get(TopOfHourClock::class);
    }

    public function testSoftEtmTargetsTheOpeningOfMinuteFiftyNine(): void
    {
        [$station, $media] = $this->persistStationWithId(37.825);

        try {
            $from = CarbonImmutable::parse('2026-09-06 09:50:00', 'UTC');
            $plan = $this->clock->plan($station, $from);

            self::assertNotNull($plan);
            self::assertSame(TopOfHourMode::SoftEtm, $plan->mode);
            self::assertSame('2026-09-06 10:00:00.000000', $plan->boundaryAt->format('Y-m-d H:i:s.u'));
            self::assertSame('2026-09-06 09:59:00.000000', $plan->targetStartAt->format('Y-m-d H:i:s.u'));
            self::assertSame($media->id, $plan->media->id);
            self::assertEqualsWithDelta(37.825, $plan->durationSeconds, 0.001);
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testHardTohAlsoTargetsTheOpeningOfMinuteFiftyNine(): void
    {
        [$station, $media] = $this->persistStationWithId(37.825);

        try {
            $playlist = new StationPlaylist($station);
            $playlist->name = 'Rigid 10 AM Program';

            $schedule = new StationSchedule($playlist);
            $schedule->start_time = 1000;
            $schedule->end_time = 1100;
            $schedule->strict_start = true;

            $station->playlists->add($playlist);
            $playlist->schedule_items->add($schedule);

            $from = CarbonImmutable::parse('2026-09-06 09:50:00', 'UTC');
            $plan = $this->clock->plan($station, $from);

            self::assertNotNull($plan);
            self::assertSame(TopOfHourMode::HardToh, $plan->mode);
            self::assertSame('2026-09-06 10:00:00.000000', $plan->boundaryAt->format('Y-m-d H:i:s.u'));
            self::assertSame('2026-09-06 09:59:00.000000', $plan->targetStartAt->format('Y-m-d H:i:s.u'));
            self::assertTrue($plan->isHard());
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testDisabledFeatureProducesNoPlan(): void
    {
        [$station, $media] = $this->persistStationWithId(30.0);

        try {
            $config = $station->backend_config;
            $config->top_of_hour_id_enabled = false;
            $station->backend_config = $config;

            $from = CarbonImmutable::parse('2026-09-06 09:50:00', 'UTC');
            self::assertNull($this->clock->plan($station, $from));
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    /** @return array{Station, StationMedia} */
    private function persistStationWithId(float $duration): array
    {
        $em = $this->testsModule->em;

        $station = new Station();
        $station->name = 'TOPH Broadcast Clock Test';
        $station->short_name = 'toph_clock_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $config = $station->backend_config;
        $config->top_of_hour_id_enabled = true;
        $config->top_of_hour_lookahead_minutes = 10;
        $config->top_of_hour_id_max_seconds = 60;
        $station->backend_config = $config;

        $media = new StationMedia($station->media_storage_location, '/broadcast-clock-id-' . uniqid() . '.mp3');
        $media->title = 'Station ID';
        $media->artist = 'Station';
        $media->type = StationMediaTypes::ID;
        $media->length = $duration;
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
