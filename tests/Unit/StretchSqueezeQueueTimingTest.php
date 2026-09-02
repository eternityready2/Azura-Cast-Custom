<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\StretchSqueezeQueueTiming;
use Codeception\Test\Unit;

final class StretchSqueezeQueueTimingTest extends Unit
{
    private StretchSqueezeQueueTiming $timing;

    protected function _before(): void
    {
        $this->timing = new StretchSqueezeQueueTiming();
    }

    public function testOrdinaryAutoDjRowUsesStretchedProjectedDuration(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.96);

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel);
        self::assertSame(0.96, $queue->clock_wheel_stretch_ratio);
        self::assertEqualsWithDelta(187.5, (float)$queue->duration, 0.001);
    }

    public function testOrdinaryRatioUsesSerializedPrecisionForProjectedDuration(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: 58 / 60,
            mediaLength: 58.0,
        );

        $this->timing->applyProjectedDuration($event);

        self::assertSame(0.9667, $queue->clock_wheel_stretch_ratio);
        self::assertEqualsWithDelta(58 / 0.9667, (float)$queue->duration, 0.0001);
    }

    public function testStationMaximumFreezesOutOfRangeRowToNaturalPlayback(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.96, maxPercent: 2.0);

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel_stretch_ratio);
        self::assertSame(180.0, $queue->duration);
    }

    public function testDisabledSettingFreezesRowToNaturalPlayback(): void
    {
        [$event, $queue] = $this->makeEvent(ratio: 0.96, enabled: false);

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel_stretch_ratio);
        self::assertSame(180.0, $queue->duration);
    }

    public function testStrictClockWheelShortRowUsesTargetDurationWhenStretchIsSafe(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: 58 / 60,
            mediaLength: 58.0,
            clockWheelTargetSeconds: 60,
        );

        $this->timing->applyProjectedDuration($event);

        self::assertEqualsWithDelta(0.9667, (float)$queue->clock_wheel_stretch_ratio, 0.0001);
        self::assertSame(60.0, $queue->duration);
        self::assertFalse($queue->clock_wheel_enforce_cap);
    }

    public function testStrictClockWheelLongRowUsesTargetDurationWhenSqueezeIsSafe(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: 62 / 60,
            mediaLength: 62.0,
            clockWheelTargetSeconds: 60,
        );

        $this->timing->applyProjectedDuration($event);

        self::assertEqualsWithDelta(1.0333, (float)$queue->clock_wheel_stretch_ratio, 0.0001);
        self::assertSame(60.0, $queue->duration);
        self::assertFalse($queue->clock_wheel_enforce_cap);
    }

    public function testClockWheelCapFallbackUsesCappedProjectedDurationWhenAdjustmentIsUnsafe(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: null,
            mediaLength: 70.0,
            clockWheelTargetSeconds: 60,
        );

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel_stretch_ratio);
        self::assertSame(60.0, $queue->duration);
        self::assertTrue($queue->clock_wheel_enforce_cap);
    }

    public function testTightestProtectedBoundaryWinsWhenMultipleTargetsExist(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: null,
            mediaLength: 103.0,
            hourBoundaryTargetSeconds: 105,
            preIdTargetSeconds: 100,
        );

        $this->timing->applyProjectedDuration($event);

        self::assertSame(1.03, $queue->clock_wheel_stretch_ratio);
        self::assertSame(100.0, $queue->duration);
        self::assertFalse($queue->hour_boundary_enforce_cap);
        self::assertFalse($queue->top_of_hour_pre_id_fade);
    }

    public function testLegalIdMaximumIsNotTreatedAsStretchTarget(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: 118 / 120,
            mediaLength: 118.0,
            hourBoundaryTargetSeconds: 120,
            mediaType: StationMediaTypes::ID,
        );
        $queue->top_of_hour_legal_id = true;

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel_stretch_ratio);
        self::assertSame(118.0, $queue->duration);
        self::assertTrue($queue->hour_boundary_enforce_cap);
    }

    public function testLegalIdLongerThanMaximumKeepsCeilingAsCap(): void
    {
        [$event, $queue] = $this->makeEvent(
            ratio: null,
            mediaLength: 125.0,
            hourBoundaryTargetSeconds: 120,
            mediaType: StationMediaTypes::ID,
        );
        $queue->top_of_hour_legal_id = true;

        $this->timing->applyProjectedDuration($event);

        self::assertNull($queue->clock_wheel_stretch_ratio);
        self::assertSame(120.0, $queue->duration);
        self::assertTrue($queue->hour_boundary_enforce_cap);
    }

    /**
     * @return array{BuildQueue, StationQueue, Station}
     */
    private function makeEvent(
        ?float $ratio,
        bool $enabled = true,
        float $maxPercent = 5.0,
        float $mediaLength = 180.0,
        ?int $clockWheelTargetSeconds = null,
        ?int $hourBoundaryTargetSeconds = null,
        ?int $preIdTargetSeconds = null,
        string $mediaType = 'music',
    ): array {
        $station = new Station();
        $station->name = 'Stretch Timing Test';
        $station->short_name = 'stretch_timing_test';
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();
        $this->setStretchSettings($station, $enabled, $maxPercent);

        $media = new StationMedia($station->media_storage_location, '/music.mp3');
        $media->title = 'Music';
        $media->artist = 'Artist';
        $media->type = $mediaType;
        $media->length = $mediaLength;
        $media->mtime = time();
        $media->uploaded_at = time();

        $queue = StationQueue::fromMedia($station, $media);
        $queue->clock_wheel_stretch_ratio = $ratio;

        if (null !== $clockWheelTargetSeconds) {
            $queue->clock_wheel = new StationClockWheel($station);
            $queue->clock_wheel_enforce_cap = true;
            $queue->clock_wheel_max_play_seconds = $clockWheelTargetSeconds;
        }

        if (null !== $hourBoundaryTargetSeconds) {
            $queue->hour_boundary_enforce_cap = true;
            $queue->hour_boundary_max_play_seconds = $hourBoundaryTargetSeconds;
        }

        if (null !== $preIdTargetSeconds) {
            $queue->top_of_hour_pre_id_fade = true;
            $queue->top_of_hour_pre_id_fade_seconds = 3;
            $queue->duration = (float)$preIdTargetSeconds;
        }

        $event = new BuildQueue($station);
        self::assertTrue($event->setNextSongs($queue));

        return [$event, $queue, $station];
    }

    private function setStretchSettings(Station $station, bool $enabled, float $maxPercent): void
    {
        $config = $station->backend_config;
        $config->fromArray([
            'playout_stretch_squeeze_enabled' => $enabled,
            'playout_stretch_squeeze_max_percent' => $maxPercent,
        ]);
        $station->backend_config = $config;
    }
}
