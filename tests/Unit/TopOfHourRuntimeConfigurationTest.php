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
    public function testTohSwitchRetiresBufferedCrossSourceDirectly(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        $runtime = new TopOfHourRuntimeConfiguration($clock);
        $runtime->captureCrossSource($event);
        $runtime->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('broadcast_clock_cross_source = radio', $config);
        self::assertStringContainsString('Top-of-Hour Station ID exact wall-clock lane (plugin owned)', $config);
        self::assertStringContainsString('def top_of_hour_id_enter(_, new)', $config);
        self::assertStringContainsString('top_of_hour_id_active := true', $config);
        self::assertStringContainsString('source.skip(broadcast_clock_cross_source)', $config);
        self::assertStringContainsString(
            'directly retired buffered post-cross AutoDJ track.',
            $config,
        );

        // Live incident under #161 proved that waiting on a cross callback can
        // deadlock the TOH lane on silence. The direct post-cross reset owns the
        // buffered tail, so TOH must not install or wait on a muted cleanup lane.
        self::assertStringNotContainsString('top_of_hour_cleanup_underlay', $config);
        self::assertStringNotContainsString('top_of_hour_cleanup_audio', $config);
        self::assertStringNotContainsString('or azuracast.autodj_clean_cut_pending()', $config);
        self::assertStringNotContainsString('azuracast.discard_autodj_current_cleanly()', $config);

        // Older failed retirement/gating designs stay gone.
        self::assertStringNotContainsString('broadcast_clock_autodj_gate', $config);
        self::assertStringNotContainsString('broadcast_clock_block_autodj()', $config);
        self::assertStringNotContainsString('broadcast_clock_prefetch_autodj()', $config);
        self::assertStringNotContainsString('broadcast_clock_release_when_fresh()', $config);
        self::assertStringNotContainsString('broadcast_clock_retire_outgoing(', $config);
        self::assertStringNotContainsString('top_of_hour_hard_release_epoch', $config);
        self::assertStringNotContainsString('source.skip(source.effective(', $config);
    }

    public function testOpenHourReleaseDependsOnlyOnIdReadiness(): void
    {
        $station = $this->makeStation();

        /** @var TopOfHourClock $clock */
        $clock = (new ReflectionClass(TopOfHourClock::class))->newInstanceWithoutConstructor();

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        $runtime = new TopOfHourRuntimeConfiguration($clock);
        $runtime->captureCrossSource($event);
        $runtime->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            "# Open hour: the ID file itself is the only hold condition.",
            $config,
        );
        self::assertStringContainsString('top_of_hour_id.is_ready()', $config);
        self::assertStringNotContainsString(
            'top_of_hour_id.is_ready()\n                        or azuracast.autodj_clean_cut_pending()',
            $config,
        );
        self::assertStringContainsString(
            'open-hour lane released directly to fresh AutoDJ audio.',
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

        // The ID source falls through to a blank within the SAME authoritative
        // TOH lane. If a short HARD ID ends before :00, ordinary AutoDJ never
        // becomes the selected branch during the gap.
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

        // Disabled TOH must not install the old AutoDJ transport gate/quarantine.
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

        // PR #155's normal queue repeat guard remains the single backend owner of
        // immediate-repeat rejection. No TOH-specific quarantine is needed.
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
