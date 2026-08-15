<?php

declare(strict_types=1);

namespace PureUnit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Entity\StorageLocation;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

final class StationPlaylistMediaRepositoryTest extends TestCase
{
    public function testIdempotentAddDoesNotDuplicateSequentialDirectMembership(): void
    {
        $station = new Station();
        $playlist = new StationPlaylist($station);
        $playlist->name = 'Sequential';
        $playlist->order = PlaylistOrders::Sequential;

        $storageLocation = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );
        $media = new StationMedia($storageLocation, 'song.mp3');
        $existing = new StationPlaylistMedia($playlist, $media);

        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with([
                'media' => $media,
                'playlist' => $playlist,
                'folder' => null,
            ])
            ->willReturn($existing);

        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em->expects(self::never())->method('persist');

        $repository = new StationPlaylistMediaRepository(
            new StationQueueRepository()
        );
        $repository->setEntityManager($em);

        self::assertFalse($repository->addMediaToPlaylistIfMissing($media, $playlist));
    }

    public function testIdempotentAddCreatesMissingDirectMembership(): void
    {
        $station = new Station();
        $playlist = new StationPlaylist($station);
        $playlist->name = 'Sequential';
        $playlist->order = PlaylistOrders::Sequential;

        $storageLocation = new StorageLocation(
            StorageLocationTypes::StationMedia,
            StorageLocationAdapters::Local
        );
        $media = new StationMedia($storageLocation, 'song.mp3');

        $objectRepository = $this->createStub(EntityRepository::class);
        $objectRepository->method('findOneBy')->willReturn(null);

        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(4);

        $persisted = null;
        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->method('getRepository')->willReturn($objectRepository);
        $em->method('createQuery')->willReturn($query);
        $em
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });

        $repository = new StationPlaylistMediaRepository(new StationQueueRepository());
        $repository->setEntityManager($em);

        self::assertTrue($repository->addMediaToPlaylistIfMissing($media, $playlist));
        self::assertInstanceOf(StationPlaylistMedia::class, $persisted);
        self::assertSame($media, $persisted->media);
        self::assertSame($playlist, $persisted->playlist);
        self::assertNull($persisted->folder);
        self::assertSame(5, $persisted->weight);
    }
}
