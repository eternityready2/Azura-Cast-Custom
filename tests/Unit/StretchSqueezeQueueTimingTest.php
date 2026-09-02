<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\StretchSqueezeQueueTiming;
use Codeception\Test\Unit;

final class StretchSqueezeQueueTimingTest extends Unit
{
    public function testOrdinaryAutoDjRowUsesStretchedProjectedDuration(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.96);

        (new StretchSqueezeQueueTiming())->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel);
        self::assertEqualsWithDelta(187.5, (float)$queue->duration, 0.001);
    }

    public function testStationMaximumPreventsQueueTimingAdjustment(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.96, maxPercent: 2.0);
        $before = $queue->duration;

        (new StretchSqueezeQueueTiming())->applyProjectedDuration($event);

        self::assertSame($before, $queue->duration);
    }

    public function testExistingBoundaryTargetRemainsAuthoritative(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.99);
        $queue->hour_boundary_enforce_cap = true;
        $queue->hour_boundary_max_play_seconds = 175;
        $queue->duration = 175.0;

        (new StretchSqueezeQueueTiming())->applyProjectedDuration($event);

        self::assertSame(175.0, $queue->duration);
    }

    /**
     * @return array{BuildQueue, StationQueue}
     */
    private function makeEvent(float $ratio, float $maxPercent = 5.0): array
    {
        $station = new Station();
        $station->name = 'Stretch Timing Test';
        $station->short_name = 'stretch_timing_test';
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $config = $station->backend_config;
        $config->fromArray([
            'playout_stretch_squeeze_enabled' => true,
            'playout_stretch_squeeze_max_percent' => $maxPercent,
        ]);
        $station->backend_config = $config;

        $media = new StationMedia($station->media_storage_location, '/music.mp3');
        $media->title = 'Music';
        $media->artist = 'Artist';
        $media->type = 'music';
        $media->length = 180.0;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($station, $media);
        $queue->clock_wheel_stretch_ratio = $ratio;

        $event = new BuildQueue($station);
        self::assertTrue($event->setNextSongs($queue));

        return [$event, $queue];
    }
}
