<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Radio\AutoDJ\BroadcastClockPlanner;
use App\Tests\Module;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

final class BroadcastClockPlannerTest extends Unit
{
    private BroadcastClockPlanner $planner;

    protected function _inject(Module $testsModule): void
    {
        $this->planner = $testsModule->container->get(BroadcastClockPlanner::class);
    }

    public function testScheduledProgramStartIsSoftAnchor(): void
    {
        [$station] = $this->makeScheduledProgram(1100, 1200);

        $seconds = $this->planner->secondsUntilNextSoftAnchor(
            $station,
            $this->time('2026-09-04 10:58:30'),
        );

        self::assertSame(90, $seconds);
    }

    public function testSoftAnchorContentDurationIncludesCrossfadeOverlap(): void
    {
        [$station] = $this->makeScheduledProgram(1100, 1200);
        $station->backend_config->crossfade = 5.0;

        $duration = $this->planner->maxContentDurationBeforeNextSoftAnchor(
            $station,
            $this->time('2026-09-04 10:58:30'),
        );

        self::assertSame(95.0, $duration);
    }

    public function testScheduledProgramEndIsSoftAnchor(): void
    {
        [$station] = $this->makeScheduledProgram(1100, 1200);

        $seconds = $this->planner->secondsUntilNextSoftAnchor(
            $station,
            $this->time('2026-09-04 11:58:00'),
        );

        self::assertSame(120, $seconds);
    }

    public function testAllowOverrunLeavesProgramEndFlexible(): void
    {
        [$station, $playlist] = $this->makeScheduledProgram(1100, 1200);
        $playlist->backend_options = [StationPlaylist::OPTION_ALLOW_OVERRUN];

        $seconds = $this->planner->secondsUntilNextSoftAnchor(
            $station,
            $this->time('2026-09-04 11:58:00'),
        );

        self::assertNull($seconds);
    }

    public function testAiNewsPushMinuteIsSoftAnchor(): void
    {
        $station = $this->makeStation();
        $station->backend_config->ai_news_enabled = true;
        $station->backend_config->ai_news_top_of_hour = true;
        $station->backend_config->ai_news_bottom_of_hour = false;
        $station->backend_config->ai_news_active_days = [];
        $station->backend_config->ai_news_active_hours = null;

        $seconds = $this->planner->secondsUntilNextSoftAnchor(
            $station,
            $this->time('2026-09-04 10:58:30'),
        );

        self::assertSame(30, $seconds);
    }

    public function testScheduledProgramOwnsStandardRotationWindow(): void
    {
        [$station, $program] = $this->makeScheduledProgram(1100, 1200);

        $general = new StationPlaylist($station);
        $general->name = 'General Mix';
        $general->source = PlaylistSources::Songs;
        $general->type = PlaylistTypes::Standard;
        $general->is_enabled = true;
        $station->playlists->add($general);

        $when = $this->time('2026-09-04 11:30:00');

        self::assertTrue($this->planner->isProgramWindowActive($station, $when));
        self::assertTrue($this->planner->isPlaylistPreemptedByProgram($general, $when));
        self::assertFalse($this->planner->isPlaylistPreemptedByProgram($program, $when));
        self::assertFalse(
            $this->planner->isProgramWindowActive(
                $station,
                $this->time('2026-09-04 12:30:00'),
            )
        );
    }

    public function testPreventRequestsOnlyBlocksRequestsDuringActiveWindow(): void
    {
        [$station, , $schedule] = $this->makeScheduledProgram(1100, 1200);
        $schedule->prevent_requests = true;

        self::assertTrue(
            $this->planner->areRequestsBlockedBySchedule(
                $station,
                $this->time('2026-09-04 11:30:00'),
            )
        );
        self::assertFalse(
            $this->planner->areRequestsBlockedBySchedule(
                $station,
                $this->time('2026-09-04 10:30:00'),
            )
        );

        $schedule->prevent_requests = false;
        self::assertFalse(
            $this->planner->areRequestsBlockedBySchedule(
                $station,
                $this->time('2026-09-04 11:30:00'),
            )
        );
    }

    public function testPlayOnceScheduleDoesNotOwnWholeFifteenMinuteWindow(): void
    {
        [$station] = $this->makeScheduledProgram(1100, 1100);

        $general = new StationPlaylist($station);
        $general->name = 'General Mix';
        $general->source = PlaylistSources::Songs;
        $general->type = PlaylistTypes::Standard;
        $general->is_enabled = true;
        $station->playlists->add($general);

        self::assertFalse(
            $this->planner->isPlaylistPreemptedByProgram(
                $general,
                $this->time('2026-09-04 11:05:00'),
            )
        );
    }

    /** @return array{Station, StationPlaylist, StationSchedule} */
    private function makeScheduledProgram(int $start, int $end): array
    {
        $station = $this->makeStation();

        $playlist = new StationPlaylist($station);
        $playlist->name = 'Scheduled Program';
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->is_enabled = true;

        $schedule = new StationSchedule($playlist);
        $schedule->start_time = $start;
        $schedule->end_time = $end;
        $schedule->days = [];

        $station->playlists->add($playlist);
        $playlist->schedule_items->add($schedule);

        return [$station, $playlist, $schedule];
    }

    private function makeStation(): Station
    {
        $station = new Station();
        $station->name = 'Broadcast Clock Test';
        $station->short_name = 'broadcast_clock_test';
        $station->timezone = 'UTC';

        return $station;
    }

    private function time(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
