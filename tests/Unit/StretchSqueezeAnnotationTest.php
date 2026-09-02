<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use Codeception\Test\Unit;

final class StretchSqueezeAnnotationTest extends Unit
{
    public function testStretchRatioSurvivesAnnotationSerialization(): void
    {
        $station = new Station();
        $station->name = 'Stretch Annotation Test';
        $station->short_name = 'stretch_annotation_test';
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $media = new StationMedia($station->media_storage_location, '/music.mp3');
        $media->title = 'Music';
        $media->artist = 'Artist';
        $media->type = 'music';
        $media->length = 180.0;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($station, $media);
        $event = AnnotateNextSong::fromStationQueue($queue, true);
        $event->setSongPath('/music.mp3');
        $event->addAnnotations([
            'liq_stretch_ratio' => 0.96,
        ]);

        $serialized = $event->buildAnnotations();

        self::assertStringContainsString('liq_stretch_ratio', $serialized);
        self::assertStringContainsString('0.96', $serialized);
    }
}
