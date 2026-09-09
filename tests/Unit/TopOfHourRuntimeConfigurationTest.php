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
    public function testTohPreservesLiveProven160CleanCutWithPrearmedContinuityClock(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        // The live-proven #160 retirement path is non-negotiable.
        self::assertStringContainsString('azuracast.discard_autodj_current_cleanly()', $config);
        self::assertStringContainsString(
            'armed #160 clean cross boundary and discarded interrupted AutoDJ request.',
            $config,
        );

        // Keep the exact processed radio graph activated before the outer TOH
        // switch takes over. The independent output is lifecycle-controlled via
        // its registered commands because output.dummy returns unit in LS 2.4.5.
        self::assertStringContainsString('top_of_hour_cleanup_driver_active = ref(false)', $config);
        self::assertStringContainsString('source.methods(radio_before_top_of_hour).on_frame(', $config);
        self::assertStringContainsString('id="top_of_hour_cleanup_driver"', $config);
        self::assertStringContainsString('register_telnet=true', $config);
        self::assertStringContainsString('start=false', $config);
        self::assertStringContainsString(
            'server.execute("top_of_hour_cleanup_driver.start")',
            $config,
        );
        self::assertStringContainsString(
            'server.execute("top_of_hour_cleanup_driver.stop")',
            $config,
        );
        self::assertStringContainsString(
            'pre-armed continuous #160 cleanup clock before takeover.',
            $config,
        );
        self::assertStringContainsString(
            'clean cross boundary consumed; parked fresh AutoDJ successor.',
            $config,
        );

        // #161's activation-changing cleanup switch and #162's post-cross reset
        // are both forbidden; live tests proved each regressed required behavior.
        self::assertStringNotContainsString('top_of_hour_cleanup_underlay = switch(', $config);
        self::assertStringNotContainsString('broadcast_clock_cross_source = radio', $config);
        self::assertStringNotContainsString('source.skip(broadcast_clock_cross_source)', $config);
        self::assertStringNotContainsString('azuracast.autodj_clean_cut_pending := false', $config);
    }

    public function testOpenHourReleaseDependsOnlyOnIdReadinessAndNeverCleanupState(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            '# Open hour: the ID file itself is the ONLY hold condition.',
            $config,
        );
        self::assertStringContainsString('top_of_hour_id.is_ready()', $config);
        self::assertStringNotContainsString(
            'top_of_hour_id.is_ready()\n                        or azuracast.autodj_clean_cut_pending()',
            $config,
        );
        self::assertStringContainsString(
            'open-hour lane released to parked fresh AutoDJ audio.',
            $config,
        );
        self::assertStringContainsString(
            'ERROR clean cross still pending at ID release; releasing air without deadlock.',
            $config,
        );
    }

    public function testCleanupClockStopsOnlyAfterCrossConsumesTheOneShotMarker(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new TopOfHourRuntimeConfiguration($clock))->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            'top_of_hour_cleanup_driver_active()\n                    and top_of_hour_id_active()\n                    and not azuracast.autodj_clean_cut_pending()',
            $config,
        );
        self::assertStringNotContainsString(
            'top_of_hour_id_should_play() =\n                azuracast.autodj_clean_cut_pending()',
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
