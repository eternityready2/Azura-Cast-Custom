<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\StorageLocationAdapters;
use App\Entity\Enums\StorageLocationTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\StorageLocation;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\DmcaComplianceListener;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DmcaComplianceListenerTest extends Unit
{
    private StationQueueRepository&MockObject $queueRepo;

    private DmcaComplianceListener $listener;

    private EventDispatcher $dispatcher;

    /** @var list<StationQueue> */
    private array $selectorPicks = [];

    private int $selectorCallCount = 0;

    protected function _before(): void
    {
        $this->queueRepo = $this->createMock(StationQueueRepository::class);
        $this->listener = new DmcaComplianceListener($this->queueRepo);
        $this->listener->setLogger(new Logger('dmca-test', [new NullHandler()]));

        $this->dispatcher = new EventDispatcher();
        $this->selectorPicks = [];
        $this->selectorCallCount = 0;

        // Simulate a normal AutoDJ selector at priority 0 (same band as QueueBuilder).
        $this->dispatcher->addListener(
            BuildQueue::class,
            function (BuildQueue $event): void {
                if (!empty($event->getNextSongs()) || $this->selectorPicks === []) {
                    return;
                }

                $index = min($this->selectorCallCount, count($this->selectorPicks) - 1);
                $event->setNextSongs($this->selectorPicks[$index]);
                $this->selectorCallCount++;
            },
            0
        );

        $this->dispatcher->addSubscriber($this->listener);
    }

    public function testSetNextSongsNullClearsExistingSelection(): void
    {
        $station = $this->makeStation();
        $event = new BuildQueue($station);
        $entry = $this->makeMusicQueueEntry($station, 'Artist', 'Song A', 'Album');

        self::assertTrue($event->setNextSongs($entry));
        self::assertCount(1, $event->getNextSongs());
        self::assertFalse($event->isPropagationStopped());

        self::assertTrue($event->setNextSongs(null));
        self::assertSame([], $event->getNextSongs());
    }

    public function testDmcaRunsAfterSelectorAndClearsViolatingPick(): void
    {
        $station = $this->makeStation(enabled: true);
        $violating = $this->makeMusicQueueEntry($station, 'Artist', 'Song A', 'Album');

        $this->selectorPicks = [$violating];
        $this->queueRepo
            ->expects(self::once())
            ->method('getPlayedMusicHistoryByTimeRange')
            ->willReturn($this->historyRows($violating->song_id, count: 3));

        $event = new BuildQueue($station, expectedPlayTime: new DateTimeImmutable('2026-07-25 12:00:00'));
        $this->dispatcher->dispatch($event);

        self::assertSame([], $event->getNextSongs(), 'DMCA must clear the violating selector pick');
        self::assertTrue($event->isPropagationStopped());
        self::assertSame(1, $this->selectorCallCount);
    }

    public function testQueueRetriesWithDifferentTrackAfterDmcaRejection(): void
    {
        $station = $this->makeStation(enabled: true);
        $violating = $this->makeMusicQueueEntry($station, 'Artist', 'Song A', 'Album');
        $replacement = $this->makeMusicQueueEntry($station, 'Other Artist', 'Song B', 'Other Album');

        $this->selectorPicks = [$violating, $replacement];

        $this->queueRepo
            ->expects(self::exactly(2))
            ->method('getPlayedMusicHistoryByTimeRange')
            ->willReturn($this->historyRows($violating->song_id, count: 3));

        // Attempt 1: selector picks the violating track → DMCA clears it.
        $first = new BuildQueue($station, expectedPlayTime: new DateTimeImmutable('2026-07-25 12:00:00'));
        $this->dispatcher->dispatch($first);
        self::assertSame([], $first->getNextSongs());

        // Attempt 2: selector retries with a different track → DMCA allows it.
        $second = new BuildQueue($station, expectedPlayTime: new DateTimeImmutable('2026-07-25 12:00:30'));
        $this->dispatcher->dispatch($second);

        $accepted = $second->getNextSongs();
        self::assertCount(1, $accepted);
        self::assertSame($replacement->song_id, $accepted[0]->song_id);
        self::assertSame(2, $this->selectorCallCount);
    }

    public function testCompliantPickIsKept(): void
    {
        $station = $this->makeStation(enabled: true);
        $entry = $this->makeMusicQueueEntry($station, 'Artist', 'Song A', 'Album');

        $this->selectorPicks = [$entry];
        $this->queueRepo
            ->method('getPlayedMusicHistoryByTimeRange')
            ->willReturn($this->historyRows($entry->song_id, count: 1));

        $event = new BuildQueue($station, expectedPlayTime: new DateTimeImmutable('2026-07-25 12:00:00'));
        $this->dispatcher->dispatch($event);

        self::assertCount(1, $event->getNextSongs());
        self::assertSame($entry->song_id, $event->getNextSongs()[0]->song_id);
        self::assertFalse($event->isPropagationStopped());
    }

    public function testDisabledDmcaDoesNotClearPick(): void
    {
        $station = $this->makeStation(enabled: false);
        $entry = $this->makeMusicQueueEntry($station, 'Artist', 'Song A', 'Album');

        $this->selectorPicks = [$entry];
        $this->queueRepo->expects(self::never())->method('getPlayedMusicHistoryByTimeRange');

        $event = new BuildQueue($station);
        $this->dispatcher->dispatch($event);

        self::assertCount(1, $event->getNextSongs());
    }

    private function makeStation(bool $enabled = false): Station
    {
        $station = new Station();
        $station->name = 'DMCA Test';
        $station->short_name = 'dmca_test';

        $config = $station->backend_config;
        $config->dmca_compliance_enabled = $enabled;
        $config->dmca_max_song_plays = 3;
        $config->dmca_window_minutes = 180;
        $station->backend_config = $config;

        return $station;
    }

    private function makeMusicQueueEntry(
        Station $station,
        string $artist,
        string $title,
        string $album,
    ): StationQueue {
        $storage = new StorageLocation(StorageLocationTypes::StationMedia, StorageLocationAdapters::Local);
        $media = new StationMedia($storage, '/' . md5($artist . $title) . '.mp3');
        $media->artist = $artist;
        $media->title = $title;
        $media->album = $album;
        $media->type = 'music';
        $media->updateMetaFields();

        return StationQueue::fromMedia($station, $media);
    }

    /**
     * @return list<array{song_id: string, title: string|null, artist: string|null, album: string|null}>
     */
    private function historyRows(string $songId, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'song_id' => $songId,
                'title' => 'Song A',
                'artist' => 'Artist',
                'album' => 'Album',
            ];
        }

        return $rows;
    }
}
