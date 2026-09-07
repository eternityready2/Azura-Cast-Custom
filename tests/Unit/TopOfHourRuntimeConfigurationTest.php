<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use Codeception\Test\Unit;
use Plugin\TopOfHour\TopOfHourRuntimeConfiguration;
use ReflectionClass;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/TopOfHourRuntimeConfiguration.php';

final class TopOfHourRuntimeConfigurationTest extends Unit
{
    public function testTohTakeoverDiscardsAndGatesAutoDjTransport(): void
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

        self::assertStringContainsString('Top-of-Hour Station ID exact wall-clock lane (plugin owned)', $config);
        self::assertStringContainsString('def top_of_hour_id_enter(_, new)', $config);
        self::assertStringContainsString('broadcast_clock_block_autodj()', $config);
        self::assertStringContainsString(
            'Top-of-Hour ID: discarded and gated interrupted AutoDJ track.',
            $config,
        );
        self::assertStringContainsString('def top_of_hour_release_gate_if_no_rigid()', $config);
        self::assertStringContainsString('exited_at_hard_boundary', $config);
        self::assertStringContainsString(
            'thread.run.recurrent(delay=1.0, top_of_hour_release_gate_if_no_rigid)',
            $config,
        );
        self::assertStringContainsString('broadcast_clock_release_autodj()', $config);
        self::assertStringNotContainsString('source.skip(source.effective(old))', $config);
        self::assertStringNotContainsString('source.skip(source.effective(new))', $config);
    }
}
