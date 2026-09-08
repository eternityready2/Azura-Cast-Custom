<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use Codeception\Test\Unit;
use Plugin\TopOfHour\RigidScheduleRuntimeConfiguration;

require_once dirname(__DIR__, 2) . '/plugins/top_of_hour/src/RigidScheduleRuntimeConfiguration.php';

final class RigidScheduleRuntimeConfigurationTest extends Unit
{
    public function testAutoDjOnlyStrictSongPlaylistGetsDedicatedNativeSourceAndTransportGate(): void
    {
        [$station] = $this->makeScheduledProgram(true);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString(
            '# Dedicated native source for an AutoDJ-only rigid scheduled programme.',
            $config,
        );
        self::assertStringContainsString('rigid_playlist_scheduled_program_', $config);
        self::assertStringContainsString('mode="randomize"', $config);
        self::assertStringContainsString('11h0m-12h0m', $config);

        // Two-layer retirement is intentional: request.dynamic is killed at the
        // transport and fallback.skip flushes the selected post-crossfade track.
        self::assertStringContainsString('broadcast_clock_autodj_blocked = ref(false)', $config);
        self::assertStringContainsString('def broadcast_clock_block_autodj()', $config);
        self::assertStringContainsString('azuracast.discard_autodj_current()', $config);
        self::assertStringContainsString('fallback.skip(', $config);
        self::assertStringContainsString('source.set_id(radio, "broadcast_clock_autodj_gate")', $config);

        self::assertStringContainsString('source.available(', $config);
        self::assertStringContainsString('{broadcast_clock_base_available()}', $config);
        self::assertStringNotContainsString('predicate.activates({broadcast_clock_base_available()})', $config);

        // Rejoin must be a fresh request, never a blind gate reopen.
        self::assertStringContainsString('def broadcast_clock_prefetch_autodj()', $config);
        self::assertStringContainsString('azuracast.prefetch_autodj_next()', $config);
        self::assertStringContainsString('azuracast.autodj_fresh_ready()', $config);
        self::assertStringContainsString('def broadcast_clock_release_when_fresh()', $config);
        self::assertStringContainsString('holding rejoin until a fresh AutoDJ request is ready', $config);

        self::assertStringContainsString('id="rigid_schedule_runtime"', $config);
        self::assertStringContainsString('Rigid scheduled-programme wall-clock lane (Top-of-Hour plugin)', $config);
        self::assertStringContainsString('def rigid_schedule_enter(_, new)', $config);
        self::assertStringContainsString('broadcast_clock_block_autodj()', $config);
        self::assertStringContainsString('broadcast_clock_release_when_fresh()', $config);
        self::assertStringNotContainsString('source.skip(source.effective(old))', $config);
        self::assertStringNotContainsString('source.skip(source.effective(new))', $config);
    }

    public function testFlexibleAutoDjOnlyScheduleStillGetsSharedGateButNoRigidRuntime(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);
        $config = $event->buildConfiguration();

        self::assertStringContainsString('source.set_id(radio, "broadcast_clock_autodj_gate")', $config);
        self::assertStringContainsString('fallback.skip(', $config);
        self::assertStringContainsString('rigid_schedule_active = ref(false)', $config);
        self::assertStringContainsString('{broadcast_clock_base_available()}', $config);
        self::assertStringContainsString('def broadcast_clock_release_when_fresh()', $config);
        self::assertStringNotContainsString('predicate.activates({broadcast_clock_base_available()})', $config);
        self::assertStringNotContainsString('id="rigid_schedule_runtime"', $config);
    }

    /** @return array{Station, StationPlaylist, StationSchedule} */
    private function makeScheduledProgram(bool $strictStart): array
    {
        $station = new Station();
        $station->name = 'Rigid Runtime Test';
        $station->short_name = 'rigid_runtime_test';
        $station->timezone = 'UTC';
        $station->radio_base_dir = '/tmp/rigid_runtime_test';
        $station->backend_config->write_playlists_to_liquidsoap = false;
        $station->backend_config->use_manual_autodj = false;

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Scheduled Program';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = 1100;
        $schedule->end_time = 1200;
        $schedule->days = [];
        $schedule->strict_start = $strictStart;

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$station, $playlist, $schedule];
    }
}
