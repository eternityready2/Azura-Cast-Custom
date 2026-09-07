<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Radio\Backend\Liquidsoap\RigidScheduleRuntimeConfiguration;
use Codeception\Test\Unit;

final class RigidScheduleRuntimeConfigurationTest extends Unit
{
    public function testAutoDjOnlyStrictSongPlaylistGetsDedicatedNativeSource(): void
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
        self::assertStringContainsString('id="rigid_schedule_runtime"', $config);
        self::assertStringContainsString('def rigid_schedule_enter(old, new)', $config);
        self::assertStringContainsString('source.skip(old)', $config);
        self::assertStringContainsString(
            'Rigid Schedule: discarded complete outgoing AutoDJ playout track and crossfade buffer.',
            $config,
        );
        self::assertStringNotContainsString('azuracast.discard_autodj_current()', $config);
        self::assertStringNotContainsString('source.skip(source.effective(old))', $config);
        self::assertStringNotContainsString('source.skip(source.effective(new))', $config);
    }

    public function testFlexibleAutoDjOnlyScheduleDoesNotCreateRigidRuntime(): void
    {
        [$station] = $this->makeScheduledProgram(false);

        $event = new WriteLiquidsoapConfiguration($station, false, false);
        (new RigidScheduleRuntimeConfiguration())->writeRuntime($event);

        self::assertSame('', $event->buildConfiguration());
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
