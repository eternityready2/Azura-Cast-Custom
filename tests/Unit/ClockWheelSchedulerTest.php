<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Entity\Song;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheelScheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;

final class ClockWheelSchedulerTest extends Unit
{
    private ClockWheelScheduler $clockWheelScheduler;

    private ReloadableEntityManagerInterface $em;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->clockWheelScheduler = $testsModule->container->get(ClockWheelScheduler::class);
        $this->em = $testsModule->em;
    }

    protected function _before(): void
    {
        $this->station = new Station();
        $this->station->name = 'Scheduler Test';
        $this->station->short_name = 'scheduler_test';
        $this->station->timezone = 'UTC';
    }

    public function testSkipsWhenNextSongsAlreadySet(): void
    {
        $event = $this->makeEvent();
        $existing = new StationQueue($this->station, Song::createFromText('Artist - Existing'));
        $event->setNextSongs($existing);

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertCount(1, $event->getNextSongs());
    }

    public function testSkipsWhenAnotherPlaylistOrStreamerScheduleIsActive(): void
    {
        $station = $this->persistStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Blocking Playlist';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 900;
        $schedule->end_time = 1700;
        $schedule->start_date = '2026-01-01';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 2, 3, 4, 5, 6, 7];

        $this->em->persist($playlist);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $event = $this->makeEventForStation($station, $this->activePlayTime());
            $this->clockWheelScheduler->buildFromClockWheel($event);

            self::assertSame([], $event->getNextSongs());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testSkipsWhenNoClockWheelScheduleIsActive(): void
    {
        $event = $this->makeEvent($this->activePlayTime());

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertSame([], $event->getNextSongs());
    }

    public function testSkipsWhenWheelIsInactive(): void
    {
        $station = $this->persistStation();

        $wheel = new StationClockWheel($station);
        $wheel->name = 'Inactive Wheel';
        $wheel->is_active = false;

        $schedule = new StationSchedule($wheel);
        $schedule->start_time = 900;
        $schedule->end_time = 1700;
        $schedule->start_date = '2026-01-01';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 2, 3, 4, 5];

        $this->em->persist($wheel);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $event = $this->makeEventForStation($station, $this->activePlayTime());
            $this->clockWheelScheduler->buildFromClockWheel($event);

            self::assertSame([], $event->getNextSongs());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testSetsNextSongWhenWheelIsActiveAndPlannerResolves(): void
    {
        $station = $this->persistStation();

        $wheel = new StationClockWheel($station);
        $wheel->name = 'Morning Wheel';
        $wheel->is_active = true;

        $slot = new StationClockWheelSlot($wheel);
        $slot->type = ClockWheelSlotTypes::Id;
        $slot->position_seconds = 0;
        $slot->slot_order = 1;
        $wheel->slots->add($slot);

        $media = new StationMedia($station->media_storage_location, '/id.mp3');
        $media->title = 'Top of Hour ID';
        $media->artist = 'Station';
        $media->type = 'id';
        $media->length = 10.0;
        $media->mtime = time();
        $media->uploaded_at = time();

        $schedule = new StationSchedule($wheel);
        $schedule->start_time = 900;
        $schedule->end_time = 1700;
        $schedule->start_date = '2026-01-01';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 2, 3, 4, 5, 6, 7];

        $this->em->persist($wheel);
        $this->em->persist($slot);
        $this->em->persist($media);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $event = $this->makeEventForStation($station, $this->activePlayTime());
            $this->clockWheelScheduler->buildFromClockWheel($event);

            self::assertCount(1, $event->getNextSongs());
            self::assertSame($wheel->id, $event->getNextSongs()[0]->clock_wheel_id);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testDoesNotSetNextSongWhenPlannerReturnsNull(): void
    {
        $station = $this->persistStation();

        $wheel = new StationClockWheel($station);
        $wheel->name = 'Empty Wheel';
        $wheel->is_active = true;

        $schedule = new StationSchedule($wheel);
        $schedule->start_time = 900;
        $schedule->end_time = 1700;
        $schedule->start_date = '2026-01-01';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 2, 3, 4, 5, 6, 7];

        $this->em->persist($wheel);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $event = $this->makeEventForStation($station, $this->activePlayTime());
            $this->clockWheelScheduler->buildFromClockWheel($event);

            self::assertSame([], $event->getNextSongs());
        } finally {
            $this->removeStation($station);
        }
    }

    private function makeEvent(?DateTimeImmutable $expectedPlayTime = null): BuildQueue
    {
        return $this->makeEventForStation($this->station, $expectedPlayTime);
    }

    private function makeEventForStation(Station $station, ?DateTimeImmutable $expectedPlayTime): BuildQueue
    {
        return new BuildQueue(
            $station,
            $expectedPlayTime,
            $expectedPlayTime,
        );
    }

    private function activePlayTime(): DateTimeImmutable
    {
        return CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC');
    }

    private function persistStation(): Station
    {
        $station = new Station();
        $station->name = 'Scheduler DB Test';
        $station->short_name = 'sched_db_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $this->em->persist($station->media_storage_location);
        $this->em->persist($station->recordings_storage_location);
        $this->em->persist($station->podcasts_storage_location);
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    private function removeStation(Station $station): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationSchedule ssc
                WHERE ssc.playlist IN (
                    SELECT sp FROM App\Entity\StationPlaylist sp WHERE sp.station = :station
                )
            DQL
        )->setParameter('station', $station)->execute();

        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationSchedule ssc
                WHERE ssc.clock_wheel IN (
                    SELECT scw FROM App\Entity\StationClockWheel scw WHERE scw.station = :station
                )
            DQL
        )->setParameter('station', $station)->execute();

        $this->em->createQuery('DELETE FROM App\Entity\StationClockWheelSlot scws WHERE scws.clock_wheel IN (
            SELECT scw FROM App\Entity\StationClockWheel scw WHERE scw.station = :station
        )')->setParameter('station', $station)->execute();

        $this->em->createQuery('DELETE FROM App\Entity\StationClockWheel scw WHERE scw.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->em->createQuery('DELETE FROM App\Entity\StationMedia sm WHERE sm.storage_location = :storage')
            ->setParameter('storage', $station->media_storage_location)
            ->execute();

        $this->em->createQuery('DELETE FROM App\Entity\StationPlaylist sp WHERE sp.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->em->remove($station);
        $this->em->remove($station->media_storage_location);
        $this->em->remove($station->recordings_storage_location);
        $this->em->remove($station->podcasts_storage_location);
        $this->em->flush();
        $this->em->clear();
    }
}
