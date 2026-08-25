<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\AutoDJ\ClockWheel\ClockWheelAnnotator;
use Codeception\Test\Unit;

final class ClockWheelAnnotatorTest extends Unit
{
    private ClockWheelAnnotator $annotator;

    private Station $station;

    protected function _before(): void
    {
        $this->annotator = new ClockWheelAnnotator();
        $this->station = new Station();
        $this->station->name = 'Annotator Test';
        $this->station->short_name = 'annotator_test';
        $this->station->timezone = 'UTC';
        $this->station->ensureDirectoriesExist();
    }

    public function testSkipsWhenNotAutoDj(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: true, asAutoDj: false);

        $this->annotator->applyClockWheelCap($event);

        self::assertArrayNotHasKey('autocue_cue_out', $event->getAnnotations());
    }

    public function testSkipsWhenEnforceCapIsFalse(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: false, asAutoDj: true);

        $this->annotator->applyClockWheelCap($event);

        self::assertArrayNotHasKey('autocue_cue_out', $event->getAnnotations());
    }

    public function testAppliesCueOutCapForClockWheelQueueRow(): void
    {
        $event = $this->makeAnnotateEvent(enforceCap: true, asAutoDj: true, maxPlaySeconds: 30);

        $this->annotator->applyClockWheelCap($event);

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

        $this->annotator->applyClockWheelCap($event);

        self::assertSame(45.0, $event->getAnnotations()['autocue_cue_out']);
    }

    public function testStretchThatFitsHardCapDoesNotAlsoCueOut(): void
    {
        $event = $this->makeAnnotateEvent(
            enforceCap: true,
            asAutoDj: true,
            maxPlaySeconds: 180,
            mediaLength: 181.0,
            stretchRatio: 0.9945,
        );

        $this->annotator->applyClockWheelStretch($event);
        $this->annotator->applyClockWheelCap($event);

        self::assertSame(0.9945, $event->getAnnotations()['liq_stretch_ratio']);
        self::assertArrayNotHasKey('autocue_cue_out', $event->getAnnotations());
        self::assertEqualsWithDelta(180.0, $event->getAnnotations()['duration'], 0.01);
    }

    public function testStretchIsNeutralizedWhenHardCapIsTighter(): void
    {
        $event = $this->makeAnnotateEvent(
            enforceCap: true,
            asAutoDj: true,
            maxPlaySeconds: 30,
            mediaLength: 90.0,
            stretchRatio: 1.05,
        );

        $this->annotator->applyClockWheelStretch($event);
        $this->annotator->applyClockWheelCap($event);

        self::assertSame(1.0, $event->getAnnotations()['liq_stretch_ratio']);
        self::assertSame(30.0, $event->getAnnotations()['autocue_cue_out']);
        self::assertNull($event->getQueue()?->clock_wheel_stretch_ratio);
    }

    private function makeAnnotateEvent(
        bool $enforceCap,
        bool $asAutoDj,
        int $maxPlaySeconds = 30,
        float $mediaLength = 90.0,
        ?float $stretchRatio = null,
    ): AnnotateNextSong {
        $wheel = new StationClockWheel($this->station);

        $media = new StationMedia($this->station->media_storage_location, '/promo.mp3');
        $media->title = 'Promo';
        $media->artist = 'Station';
        $media->type = 'promo';
        $media->length = $mediaLength;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($this->station, $media);
        $queue->clock_wheel = $wheel;
        $queue->clock_wheel_enforce_cap = $enforceCap;
        $queue->clock_wheel_max_play_seconds = $maxPlaySeconds;
        $queue->clock_wheel_stretch_ratio = $stretchRatio;

        return AnnotateNextSong::fromStationQueue($queue, $asAutoDj);
    }
}
