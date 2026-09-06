<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\Scheduler;
use App\Tests\Module;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

final class SchedulerRemoteDurationTest extends Unit
{
    private Scheduler $scheduler;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $testsModule->container->get(Scheduler::class);
    }

    public function testLateRemoteProgramUsesOnlyRemainingWindow(): void
    {
        [$playlist] = $this->makeRemoteProgram(1100, 1200);
        $playlist->station->backend_config->crossfade = 5.0;

        $duration = $this->scheduler->getPlaylistScheduleDuration(
            $playlist,
            $this->time('2026-09-04 11:35:00'),
        );

        self::assertSame(1505, $duration);
    }

    public function testAllowOverrunKeepsOriginalProgramDuration(): void
    {
        [$playlist] = $this->makeRemoteProgram(1100, 1200);
        $playlist->station->backend_config->crossfade = 5.0;
        $playlist->backend_options = [StationPlaylist::OPTION_ALLOW_OVERRUN];

        $duration = $this->scheduler->getPlaylistScheduleDuration(
            $playlist,
            $this->time('2026-09-04 11:35:00'),
        );

        self::assertSame(3600, $duration);
    }

    public function testOvernightRemoteProgramUsesRemainingWindowAfterMidnight(): void
    {
        [$playlist] = $this->makeRemoteProgram(2300, 100);
        $playlist->station->backend_config->crossfade = 5.0;

        $duration = $this->scheduler->getPlaylistScheduleDuration(
            $playlist,
            $this->time('2026-09-05 00:30:00'),
        );

        self::assertSame(1805, $duration);
    }

    public function testRemoteProgramOutsideScheduleReturnsZero(): void
    {
        [$playlist] = $this->makeRemoteProgram(1100, 1200);

        $duration = $this->scheduler->getPlaylistScheduleDuration(
            $playlist,
            $this->time('2026-09-04 10:30:00'),
        );

        self::assertSame(0, $duration);
    }

    /** @return array{StationPlaylist, StationSchedule} */
    private function makeRemoteProgram(int $start, int $end): array
    {
        $station = new Station();
        $station->name = 'Remote Clock Test';
        $station->short_name = 'remote_clock_test';
        $station->timezone = 'UTC';

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Remote Program';
        $playlist->source = PlaylistSources::RemoteUrl;
        $playlist->remote_type = PlaylistRemoteTypes::Stream;
        $playlist->remote_url = 'https://example.com/program.mp3';
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = $start;
        $schedule->end_time = $end;
        $schedule->days = [];

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$playlist, $schedule];
    }

    private function time(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
