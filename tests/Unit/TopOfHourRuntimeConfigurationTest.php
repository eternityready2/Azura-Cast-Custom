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
    public function testTohTakeoverPermanentlyRetiresOldAudioAndRejoinsFreshOnly(): void
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

        // Open hours fetch the post-ID song while the ID owns air, and never
        // blindly reopen the processed graph before that fresh request is ready.
        self::assertStringContainsString('broadcast_clock_prefetch_autodj()', $config);
        self::assertStringContainsString('broadcast_clock_release_when_fresh()', $config);

        // HARD hours hold AutoDJ through :00 even if the ID ends fractionally
        // early. The old exited-at-boundary-only behavior must not return.
        self::assertStringContainsString('top_of_hour_hard_release_epoch = ref(0.0)', $config);
        self::assertStringContainsString('def top_of_hour_release_gate_if_no_rigid()', $config);
        self::assertStringContainsString('top_of_hour_hard_release_epoch := boundary + 0.25', $config);
        self::assertStringContainsString(
            'thread.run.recurrent(delay=0.1, top_of_hour_release_gate_if_no_rigid)',
            $config,
        );
        self::assertStringContainsString(
            'HARD handoff is holding AutoDJ through the :00 boundary.',
            $config,
        );
        self::assertStringNotContainsString('exited_at_hard_boundary', $config);

        self::assertStringNotContainsString('source.skip(source.effective(old))', $config);
        self::assertStringNotContainsString('source.skip(source.effective(new))', $config);
    }

    public function testQueueRepeatGuardPreservesInterruptedMusicAcrossTohMetadata(): void
    {
        $queueSource = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php',
        );

        self::assertIsString($queueSource);

        // Once the interrupted queue row is marked played and the TOH ID becomes
        // current metadata, neither current_song nor getUnplayedQueue() identifies
        // the music predecessor. Seed from actual played music history instead.
        self::assertStringContainsString(
            '$recentPlayedMusic = $this->queueRepo->getPlayedMusicHistoryByTimeRange(',
            $queueSource,
        );
        self::assertStringContainsString(
            "\$lastSongId = \$recentPlayedMusic[0]['song_id'] ?? null;",
            $queueSource,
        );
        self::assertStringNotContainsString(
            '$lastSongId = $currentSong?->song_id;',
            $queueSource,
        );
        self::assertStringNotContainsString(
            "\$upcomingQueue = \$this->queueRepo->getUnplayedQueue(\$station);\n\n        \$lastSongId = null;",
            $queueSource,
        );

        // The preserved music identity is passed into the selector path and the
        // retry-budget-aware guard, rather than being rejected unconditionally by
        // BuildQueue itself.
        self::assertStringContainsString(
            "\$event = new BuildQueue(\n                    \$station,\n                    \$expectedCueTime,\n                    \$expectedPlayTime,\n                    \$lastSongId",
            $queueSource,
        );
        self::assertStringContainsString(
            '$nextSongs[0]->song_id === $lastSongId',
            $queueSource,
        );
    }
}
