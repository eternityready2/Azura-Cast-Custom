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
    public function testTohPreservesCleanCutAndUsesDirectCleanupDriver(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString('top_of_hour_cleanup_driver = output.dummy(', $config);
        self::assertStringContainsString('start=false', $config);
        self::assertStringContainsString('top_of_hour_cleanup_driver.start()', $config);
        self::assertStringContainsString('top_of_hour_cleanup_driver.stop()', $config);
        self::assertStringContainsString(
            'clean cross boundary consumed; parked fresh AutoDJ successor.',
            $config,
        );

        // #162 cleared the clean-cut marker and skipped the post-cross wrapper;
        // the 11 PM live test proved that reintroduced the interrupted-song tail.
        self::assertStringNotContainsString('broadcast_clock_cross_source = radio', $config);
        self::assertStringNotContainsString('source.skip(broadcast_clock_cross_source)', $config);
        self::assertStringNotContainsString('azuracast.autodj_clean_cut_pending := false', $config);

        // #161's conditional cleanup switch failed live because the inner cross
        // callback never completed. The driver clocks the processed source
        // directly, with no switch between the clock and the cross operator.
        self::assertStringNotContainsString('top_of_hour_cleanup_underlay = switch(', $config);
        self::assertStringNotContainsString('top_of_hour_clocked_lane = add(', $config);
    }

    public function testOpenHourReleaseNeverWaitsOnCleanupState(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            '# Open hour: the ID file itself is the only hold condition.',
            $config,
        );
        self::assertStringContainsString('top_of_hour_id.is_ready()', $config);
        self::assertStringNotContainsString(
            'top_of_hour_id.is_ready()\n                        or azuracast.autodj_clean_cut_pending()',
            $config,
        );
        self::assertStringContainsString(
            'open-hour lane released after clean-cut maintenance.',
            $config,
        );
    }

    public function testHardTohOwnsEveryFrameUntilExactBoundary(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('top_of_hour_hard_hold = blank(id="top_of_hour_hard_hold")', $config);
        self::assertStringContainsString('top_of_hour_lane = fallback(', $config);
        self::assertStringContainsString('[top_of_hour_id, top_of_hour_hard_hold]', $config);
        self::assertStringContainsString('boundary > 0.0 and now < boundary', $config);
        self::assertStringContainsString(
            'HARD lane released exactly at the :00 boundary to rigid authority.',
            $config,
        );
    }

    public function testDisabledPredicateLeavesNormalSourceSelected(): void
    {
        $station = $this->makeStation();
        $station->backend_config->top_of_hour_id_enabled = false;

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('if not top_of_hour_id_enabled() then', $config);
        self::assertStringContainsString('false', $config);
        self::assertStringContainsString('({true}, radio_before_top_of_hour)', $config);
        self::assertStringNotContainsString('broadcast_clock_autodj_gate', $config);
        self::assertStringNotContainsString('autodj_retired_song_id', $config);
        self::assertStringNotContainsString('exclude_song_id', $config);
    }

    public function testQueueRepeatGuardPreservesInterruptedMusicAcrossTohMetadata(): void
    {
        $queueSource = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php',
        );

        self::assertIsString($queueSource);
        self::assertStringContainsString(
            '$recentPlayedMusic = $this->queueRepo->getPlayedMusicHistoryByTimeRange(',
            $queueSource,
        );
        self::assertStringContainsString(
            "\$lastSongId = \$recentPlayedMusic[0]['song_id'] ?? null;",
            $queueSource,
        );
        self::assertStringContainsString(
            '$nextSongs[0]->song_id === $lastSongId',
            $queueSource,
        );
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'TOH Runtime Test';
        $station->short_name = 'toh_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/toh_runtime_test';
        $station->backend_config->ai_news_enabled = false;
        $station->backend_config->ai_news_top_of_hour = false;

        return $station;
    }
}
