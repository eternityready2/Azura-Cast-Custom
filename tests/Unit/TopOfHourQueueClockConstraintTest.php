<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\ResolveQueueClockConstraint;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourQueueClockConstraint;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourQueueClockConstraint.php';

final class TopOfHourQueueClockConstraintTest extends Unit
{
    private Module $testsModule;

    private TopOfHourClock $clock;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->clock = $testsModule->container->get(TopOfHourClock::class);
    }

    public function testOpenHourCutsCrossingSongAndResumesAfterIdOccupancy(): void
    {
        [$station, $media] = $this->persistStationWithId(37.825, 21);

        try {
            $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
            $naturalEnd = $start->addSeconds(275); // 23:01:15
            $event = new ResolveQueueClockConstraint(
                $station,
                $start->toDateTimeImmutable(),
                $naturalEnd->toDateTimeImmutable(),
            );

            (new TopOfHourQueueClockConstraint($this->clock))->resolve($event);

            self::assertTrue($event->hasConstraint());
            self::assertSame(
                '2026-09-07 22:59:21.000000',
                $event->getInterruptAt()?->format('Y-m-d H:i:s.u'),
            );
            self::assertSame(
                '2026-09-07 22:59:58.825000',
                $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
            );
            self::assertSame('top_of_hour_station_id', $event->getReason());

            // This is the regression from the live test: the projection must not
            // carry the natural 23:01:15 song end past the plugin-owned ID.
            self::assertNotSame(
                $naturalEnd->format('Y-m-d H:i:s.u'),
                $event->getResumeAt()?->format('Y-m-d H:i:s.u'),
            );
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testHardHourKeepsOrdinaryMusicOutUntilExactBoundary(): void
    {
        [$station, $media] = $this->persistStationWithId(37.825, 21);

        try {
            $playlist = new StationPlaylist($station);
            $playlist->name = 'Rigid 11 PM Program';

            $schedule = new StationSchedule($playlist);
            $schedule->start_time = 2300;
            $schedule->end_time = 2359;
            $schedule->strict_start = true;

            $station->playlists->add($playlist);
            $playlist->schedule_items->add($schedule);

            $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
            $event = new ResolveQueueClockConstraint(
                $station,
                $start->toDateTimeImmutable(),
                $start->addSeconds(275)->toDateTimeImmutable(),
            );

            (new TopOfHourQueueClockConstraint($this->clock))->resolve($event);

            self::assertTrue($event->hasConstraint());
            self::assertSame('2026-09-07 22:59:21.000000', $event->getInterruptAt()?->format('Y-m-d H:i:s.u'));
            self::assertSame('2026-09-07 23:00:00.000000', $event->getResumeAt()?->format('Y-m-d H:i:s.u'));
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    public function testDisabledTopOfHourLeavesOrdinaryTimelineUntouched(): void
    {
        [$station, $media] = $this->persistStationWithId(37.825, 21);

        try {
            $config = $station->backend_config;
            $config->top_of_hour_id_enabled = false;
            $station->backend_config = $config;

            $start = CarbonImmutable::parse('2026-09-07 22:56:40', 'UTC');
            $event = new ResolveQueueClockConstraint(
                $station,
                $start->toDateTimeImmutable(),
                $start->addSeconds(275)->toDateTimeImmutable(),
            );

            (new TopOfHourQueueClockConstraint($this->clock))->resolve($event);

            self::assertFalse($event->hasConstraint());
            self::assertNull($event->getInterruptAt());
            self::assertNull($event->getResumeAt());
        } finally {
            $this->removeTestEntities($station, $media);
        }
    }

    /** @return array{Station, StationMedia} */
    private function persistStationWithId(float $duration, int $startSecond): array
    {
        $em = $this->testsModule->em;

        $station = new Station();
        $station->name = 'TOH Queue Constraint Test';
        $station->short_name = 'toh_queue_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $config = $station->backend_config;
        $config->top_of_hour_id_enabled = true;
        $config->top_of_hour_lookahead_minutes = 10;
        $config->top_of_hour_id_max_seconds = 60;
        $config->fromArray([
            TopOfHourClock::CONFIG_ID_START_SECOND => $startSecond,
        ]);
        $station->backend_config = $config;

        $media = new StationMedia($station->media_storage_location, '/queue-clock-id-' . uniqid() . '.mp3');
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
