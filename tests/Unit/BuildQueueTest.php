<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use Codeception\Test\Unit;

final class BuildQueueTest extends Unit
{
    public function testEventPreservesRepeatCandidateForQueueRetryPolicy(): void
    {
        $station = new Station();
        $entry = new StationQueue($station, Song::createFromText('Artist - Song A'));
        $event = new BuildQueue($station, lastPlayedSongId: $entry->song_id);

        // Queue::buildQueue owns the retry budget. The event must preserve the
        // selector result so Queue can retry when an alternative exists, while
        // still allowing the final liveness attempt for a one-song library.
        self::assertTrue($event->setNextSongs($entry));
        self::assertSame([$entry], $event->getNextSongs());
        self::assertSame($entry->song_id, $event->getLastPlayedSongId());
        self::assertFalse($event->isPropagationStopped());
    }
}
