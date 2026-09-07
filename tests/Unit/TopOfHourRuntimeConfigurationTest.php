<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap\TopOfHourRuntimeConfiguration;
use Codeception\Test\Unit;
use ReflectionClass;

final class TopOfHourRuntimeConfigurationTest extends Unit
{
    public function testTohTakeoverSkipsTheCompleteOutgoingPlayoutSource(): void
    {
        $station = new Station();
        $station->name = 'TOH Runtime Test';
        $station->short_name = 'toh_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/toh_runtime_test';
        $station->backend_config->ai_news_enabled = false;
        $station->backend_config->ai_news_top_of_hour = false;

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('def top_of_hour_id_enter(old, new)', $config);
        self::assertStringContainsString('source.skip(old)', $config);
        self::assertStringContainsString(
            'Top-of-Hour ID: discarded complete outgoing AutoDJ playout track and crossfade buffer.',
            $config,
        );
        self::assertStringNotContainsString('azuracast.discard_autodj_current()', $config);
        self::assertStringNotContainsString('source.skip(source.effective(old))', $config);
        self::assertStringNotContainsString('source.skip(source.effective(new))', $config);
    }
}
