<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\HourBoundaryAnnotator;
use Codeception\Test\Unit;

final class HourBoundaryAnnotatorTest extends Unit
{
    private HourBoundaryAnnotator $annotator;

    private Station $station;

    protected function _before(): void
    {
        $this->annotator = new HourBoundaryAnnotator();
        $this->station = new Station();
        $this->station->name = 'Hour Boundary Annotator Test';
        $this->station->short_name = 'hour_boundary_annotator_test';
        $this->station->timezone = 'UTC';
        $this->station->ensureDirectoriesExist();
    }

    public function testSkipsCapWhenNotAutoDj(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: true, asAutoDj: false);

        $this->annotator->applyHourBoundaryCap($event);

        self::assertArrayNotHasKey('autocue_cue_out', $event->getAnnotations());
    }

    public function testSkipsCapWhenEnforceCapIsFalse(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: false, asAutoDj: true);

        $this->annotator->applyHourBoundaryCap($event);

        self::assertArrayNotHasKey('autocue_cue_out', $event->getAnnotations());
    }

    public function testAppliesHourBoundaryCueOutCap(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: true, asAutoDj: true, maxPlaySeconds: 30);

        $this->annotator->applyHourBoundaryCap($event);

        self::assertSame(30.0, $event->getAnnotations()['autocue_cue_out']);
        self::assertSame(30.0, $event->getAnnotations()['duration']);
        self::assertSame(30.0, $event->getQueue()?->duration);
    }

    public function testCueOutNeverExceedsMediaLength(): void
    {
        $event = $this->makeAnnotateEvent(
            enforceCap: true,
            asAutoDj: true,
            maxPlaySeconds: 120,
            mediaLength: 45.0,
        );

        $this->annotator->applyHourBoundaryCap($event);

        self::assertSame(45.0, $event->getAnnotations()['autocue_cue_out']);
        self::assertSame(45.0, $event->getQueue()?->duration);
    }

    public function testAppliesQuickCutToTopOfHourLegalId(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: false, asAutoDj: true);
        $queue = $event->getQueue();
        self::assertInstanceOf(StationQueue::class, $queue);
        $queue->top_of_hour_legal_id = true;

        $event->addAnnotations([
            'autocue_fade_in' => 2.0,
            'autocue_fade_out' => 2.0,
            'autocue_start_next' => 10.0,
        ]);

        $this->annotator->applyLegalIdQuickCut($event);

        self::assertSame(0.0, $event->getAnnotations()['autocue_fade_in']);
        self::assertSame(0.0, $event->getAnnotations()['autocue_fade_out']);
        self::assertNull($event->getAnnotations()['autocue_start_next']);
    }

    private function makeAnnotateEvent(
        bool $enforceCap,
        bool $asAutoDj,
        int $maxPlaySeconds = 30,
        float $mediaLength = 90.0,
    ): AnnotateNextSong {
        $media = new StationMedia($this->station->media_storage_location, '/promo.mp3');
        $media->title = 'Promo';
        $media->artist = 'Station';
        $media->type = 'promo';
        $media->length = $mediaLength;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($this->station, $media);
        $queue->hour_boundary_enforce_cap = $enforceCap;
        $queue->hour_boundary_max_play_seconds = $maxPlaySeconds;

        return AnnotateNextSong::fromStationQueue($queue, $asAutoDj);
    }
}
