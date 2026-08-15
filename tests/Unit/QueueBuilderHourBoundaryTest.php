<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\QueueBuilder;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use ReflectionMethod;

final class QueueBuilderHourBoundaryTest extends Unit
{
    private Module $testsModule;

    private QueueBuilder $queueBuilder;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->queueBuilder = $testsModule->container->get(QueueBuilder::class);
    }

    public function testTopOfHourDoesNotCapNormalQueueBeforeInterrupt(): void
    {
        [$station, $playlist, $media, $spm] = $this->persistPlaylistWithMedia(300.0);

        try {
            $expectedPlayTime = CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC');
            $queueEntry = $this->makeQueueFromApi($playlist, $media, $spm, $expectedPlayTime);

            self::assertFalse($queueEntry->hour_boundary_enforce_cap);
            self::assertNull($queueEntry->hour_boundary_max_play_seconds);
            self::assertSame(300.0, $queueEntry->duration);
        } finally {
            $this->removeTestEntities($station, $playlist, $media, $spm);
        }
    }

    public function testQueueDurationIsNotChangedOutsideTopOfHourLookahead(): void
    {
        [$station, $playlist, $media, $spm] = $this->persistPlaylistWithMedia(300.0);

        try {
            $expectedPlayTime = CarbonImmutable::parse('2026-05-26 09:40:00', 'UTC');
            $queueEntry = $this->makeQueueFromApi($playlist, $media, $spm, $expectedPlayTime);

            self::assertFalse($queueEntry->hour_boundary_enforce_cap);
            self::assertNull($queueEntry->hour_boundary_max_play_seconds);
            self::assertSame(300.0, $queueEntry->duration);
        } finally {
            $this->removeTestEntities($station, $playlist, $media, $spm);
        }
    }

    /**
     * @return array{Station, StationPlaylist, StationMedia, StationPlaylistMedia}
     */
    private function persistPlaylistWithMedia(float $mediaLength): array
    {
        $em = $this->testsModule->em;

        $station = new Station();
        $station->name = 'TOPH Queue Builder Test';
        $station->short_name = 'toph_queue_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $backendConfig = $station->backend_config;
        $backendConfig->top_of_hour_id_enabled = true;
        $backendConfig->top_of_hour_lookahead_minutes = 10;
        $backendConfig->top_of_hour_finish_buffer_seconds = 15;
        $backendConfig->top_of_hour_id_max_seconds = 60;
        $station->backend_config = $backendConfig;

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Music';
        $station->playlists->add($playlist);

        $media = new StationMedia($station->media_storage_location, '/toph-test-' . uniqid() . '.mp3');
        $media->title = 'Long Track';
        $media->artist = 'Test Artist';
        $media->length = $mediaLength;
        $media->updateMetaFields();

        $spm = new StationPlaylistMedia($playlist, $media);

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->persist($playlist);
        $em->persist($media);
        $em->persist($spm);
        $em->flush();

        return [$station, $playlist, $media, $spm];
    }

    private function makeQueueFromApi(
        StationPlaylist $playlist,
        StationMedia $media,
        StationPlaylistMedia $spm,
        CarbonImmutable $expectedPlayTime,
    ): StationQueue {
        $selectedTrack = new StationPlaylistQueue();
        $selectedTrack->media_id = $media->id;
        $selectedTrack->spm_id = $spm->id;
        $selectedTrack->song_id = $media->song_id;

        $method = new ReflectionMethod(QueueBuilder::class, 'makeQueueFromApi');
        $queueEntry = $method->invoke($this->queueBuilder, $selectedTrack, $playlist, $expectedPlayTime);

        self::assertInstanceOf(StationQueue::class, $queueEntry);
        return $queueEntry;
    }

    private function removeTestEntities(
        Station $station,
        StationPlaylist $playlist,
        StationMedia $media,
        StationPlaylistMedia $spm,
    ): void {
        $em = $this->testsModule->em;
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->createQuery('DELETE FROM App\\Entity\\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->removeIfManaged($em, $spm);
        $this->removeIfManaged($em, $playlist);
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
