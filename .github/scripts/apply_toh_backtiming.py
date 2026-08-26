from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise SystemExit(f"{label} not found")
    return text.replace(old, new, 1)


planner = Path('backend/src/Radio/AutoDJ/HourBoundaryPlanner.php')
text = planner.read_text()
old = '''    /**
     * Maximum music duration before the late-hour protection reserve begins.
     * Returns null outside the lookahead window or once the reserve is open.
     *
     * The full :58/:59 ID planning window is intentionally not used here: doing
     * so caused ordinary music to be shortened at :58 even when the next hour
     * had no scheduled content. Runtime TOH ownership still uses the wider window.
     */
    public function secondsAvailableForMusicBeforeTopOfHour(
'''
new = '''    /**
     * Real music time remaining before the late-hour TOH handoff begins.
     * Returns null outside the configured lookahead and zero once the reserve
     * is open. Queue selection uses this full value for precision backtiming;
     * the legacy cap helper below still suppresses unusably short cue-out caps.
     *
     * The full :58/:59 ID planning window is intentionally not used here: doing
     * so caused ordinary music to be shortened at :58 even when the next hour
     * had no scheduled content. Runtime TOH ownership still uses the wider window.
     */
    public function secondsAvailableForMusicBeforeTopOfHour(
'''
if old in text:
    planner.write_text(text.replace(old, new, 1))


scheduler = Path('backend/src/Radio/AutoDJ/Scheduler.php')
text = scheduler.read_text()
text = replace_once(
    text,
    '''final class Scheduler
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;
''',
    '''final class Scheduler
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public const int STRICT_START_GRACE_SECONDS = 120;
''',
    'Scheduler grace constant',
)
old = '''    /**
     * True exactly once, during the single minute a playlist's "Strict" schedule
     * item is due to start -- used to trigger a hard interrupt via the existing
     * interrupting-queue mechanism, rather than waiting for the current track
     * to finish naturally.
     */
    public function isPlaylistStrictStartDueNow(
        StationPlaylist $playlist,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));
        $nowMinute = $now->hour * 100 + $now->minute;

        foreach ($playlist->schedule_items as $schedule) {
            if (!$schedule->strict_start) {
                continue;
            }

            if ($schedule->start_time !== $nowMinute) {
                continue;
            }

            if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $now)) {
                continue;
            }

            if (!$this->isScheduleScheduledToPlayToday($schedule, $now->dayOfWeekIso)) {
                continue;
            }

            return true;
        }

        return false;
    }
'''
new = '''    /**
     * True while an unserved Strict schedule occurrence is inside its short
     * catch-up window. This lets ID/AI News finish first without allowing normal
     * music to make a scheduled programme several minutes late.
     */
    public function isPlaylistStrictStartDueNow(
        StationPlaylist $playlist,
        DateTimeZone $tz,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = CarbonImmutable::instance(Time::nowInTimezone($tz, $now));

        foreach ($playlist->schedule_items as $schedule) {
            if (!$schedule->strict_start) {
                continue;
            }

            foreach ([$now, $now->subDay()] as $candidateDay) {
                $occurrenceStart = CarbonImmutable::instance(
                    StationSchedule::getDateTime($schedule->start_time, $tz, $candidateDay)
                );
                $secondsLate = $now->getTimestamp() - $occurrenceStart->getTimestamp();

                if ($secondsLate < 0 || $secondsLate > self::STRICT_START_GRACE_SECONDS) {
                    continue;
                }

                if (!$this->shouldSchedulePlayOnCurrentDate($schedule, $tz, $occurrenceStart)) {
                    continue;
                }

                if (!$this->isScheduleScheduledToPlayToday($schedule, $occurrenceStart->dayOfWeekIso)) {
                    continue;
                }

                if ($this->queueRepo->hasPlayedPlaylistSince(
                    $playlist,
                    $occurrenceStart->toDateTimeImmutable(),
                )) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }
'''
if old in text:
    text = text.replace(old, new, 1)
scheduler.write_text(text)


repo = Path('backend/src/Entity/Repository/StationQueueRepository.php')
text = repo.read_text()
old = '''    public function hasCuedPlaylistMedia(StationPlaylist $playlist): bool
    {
'''
new = '''    public function hasPlayedPlaylistSince(
        StationPlaylist $playlist,
        DateTimeImmutable $since,
    ): bool {
        $row = $this->getBaseQuery($playlist->station)
            ->select('sq.id')
            ->andWhere('sq.playlist = :playlist')
            ->andWhere('sq.is_played = 1')
            ->andWhere('sq.timestamp_played >= :since')
            ->setParameter('playlist', $playlist)
            ->setParameter('since', $since)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $row;
    }

    public function hasCuedPlaylistMedia(StationPlaylist $playlist): bool
    {
'''
if old in text:
    text = text.replace(old, new, 1)
repo.write_text(text)


task = Path('backend/src/Sync/Task/QueueInterruptingTracks.php')
text = task.read_text()
text = replace_once(
    text,
    '''final class QueueInterruptingTracks extends AbstractTask
{
''',
    '''final class QueueInterruptingTracks extends AbstractTask
{
    private const int SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS;

''',
    'QueueInterruptingTracks grace constant',
)
text = text.replace(
    '''        if (null === $secondsToScheduled || $secondsToScheduled > 90) {
''',
    '''        if (
            null === $secondsToScheduled
            || $secondsToScheduled > self::SCHEDULED_START_GRACE_SECONDS
        ) {
''',
    1,
)
text = text.replace(
    '''        if ($currentSongEndsAt <= $scheduledBoundaryAt + 60) {
''',
    '''        if ($currentSongEndsAt <= $scheduledBoundaryAt + self::SCHEDULED_START_GRACE_SECONDS) {
''',
    1,
)
text = text.replace(
    '''            'Scheduled boundary at risk: current item projects beyond the 60-second grace window; no hard skip will be issued.',
''',
    '''            'Scheduled boundary at risk: current item projects beyond the strict-start catch-up window; no hard skip will be issued.',
''',
    1,
)
text = text.replace(
    '''                'maximum_grace_seconds' => 60,
''',
    '''                'maximum_grace_seconds' => self::SCHEDULED_START_GRACE_SECONDS,
''',
    1,
)
task.write_text(text)


# Harden PR #95's track-boundary hold. A finite silence track lets the held
# source re-evaluate immediately after the ID, and the post-boundary branch
# prevents a song that crosses :00 from opening a fresh music track before a
# slightly-late legal ID.
toh = Path('backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php')
text = toh.read_text()
text = replace_once(
    text,
    '''    private const int PHP_CLAIM_GRACE_SECONDS = 5;
    private const int PRE_BOUNDARY_HOLD_SECONDS = 75;
''',
    '''    private const int PHP_CLAIM_GRACE_SECONDS = 5;
    private const int PRE_BOUNDARY_HOLD_SECONDS = 75;
    private const int POST_BOUNDARY_HOLD_SECONDS = 30;
''',
    'TOH post-boundary hold constant',
)
text = replace_once(
    text,
    '''        $claimGraceSeconds = self::PHP_CLAIM_GRACE_SECONDS;
        $holdStartSecond = HourBoundaryPlanner::HOUR_SECONDS - self::PRE_BOUNDARY_HOLD_SECONDS;
''',
    '''        $claimGraceSeconds = self::PHP_CLAIM_GRACE_SECONDS;
        $holdStartSecond = HourBoundaryPlanner::HOUR_SECONDS - self::PRE_BOUNDARY_HOLD_SECONDS;
        $postBoundaryHoldSeconds = self::POST_BOUNDARY_HOLD_SECONDS;
''',
    'TOH hold PHP values',
)
old = '''            def top_of_hour_hold_new_track() =
              now = time()
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if seconds_in_hour < {$holdStartSecond} then
                false
              else
                boundary = int_of_float(now) + (3600 - seconds_in_hour)
                top_of_hour_last_served_boundary() != boundary
              end
            end

            radio_before_top_of_hour_unheld = radio_before_top_of_hour
            top_of_hour_preboundary_hold = blank(id="top_of_hour_preboundary_hold")
'''
new = '''            def top_of_hour_hold_new_track() =
              now = time()
              now_seconds = int_of_float(now)
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if seconds_in_hour >= {$holdStartSecond} then
                boundary = now_seconds + (3600 - seconds_in_hour)
                top_of_hour_last_served_boundary() != boundary
              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then
                # If a song crossed :00, keep the next normal track held briefly
                # for the just-started hour until its legal ID is observed.
                boundary = now_seconds - seconds_in_hour
                top_of_hour_last_served_boundary() != boundary
              else
                false
              end
            end

            radio_before_top_of_hour_unheld = radio_before_top_of_hour
            top_of_hour_preboundary_hold = blank(
              id="top_of_hour_preboundary_hold",
              duration=1.
            )
'''
text = replace_once(text, old, new, 'TOH finite/cross-boundary hold')
old = '''            def top_of_hour_mark_legal_id(metadata) =
              now = time()
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if metadata["azuracast_top_of_hour_id"] == "true" and seconds_in_hour >= 3480 then
                boundary = int_of_float(now) + (3600 - seconds_in_hour)
                top_of_hour_claimed_boundary := boundary
                top_of_hour_claimed_at := now
                top_of_hour_last_served_boundary := boundary
                log("Top of hour: legal ID started for boundary #{boundary}.")
              end
            end
'''
new = '''            def top_of_hour_mark_legal_id(metadata) =
              now = time()
              now_seconds = int_of_float(now)
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if metadata["azuracast_top_of_hour_id"] == "true" then
                boundary =
                  if seconds_in_hour >= 3480 then
                    now_seconds + (3600 - seconds_in_hour)
                  elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then
                    now_seconds - seconds_in_hour
                  else
                    -1
                  end

                if boundary >= 0 then
                  top_of_hour_claimed_boundary := boundary
                  top_of_hour_claimed_at := now
                  top_of_hour_last_served_boundary := boundary
                  log("Top of hour: legal ID started for boundary #{boundary}.")
                end
              end
            end
'''
text = replace_once(text, old, new, 'TOH late-ID boundary marker')
toh.write_text(text)


strict_test = Path('tests/Unit/StrictStartGraceTest.php')
strict_test.write_text('''<?php

declare(strict_types=1);

namespace Tests\\Unit;

use PHPUnit\\Framework\\TestCase;

final class StrictStartGraceTest extends TestCase
{
    public function testStrictStartsHaveTwoMinuteOneShotCatchUpWindow(): void
    {
        $scheduler = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Scheduler.php'
        );
        $repository = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Entity/Repository/StationQueueRepository.php'
        );

        self::assertIsString($scheduler);
        self::assertIsString($repository);
        self::assertStringContainsString('STRICT_START_GRACE_SECONDS = 120', $scheduler);
        self::assertStringContainsString('foreach ([$now, $now->subDay()] as $candidateDay)', $scheduler);
        self::assertStringContainsString('hasPlayedPlaylistSince(', $scheduler);
        self::assertStringContainsString('sq.is_played = 1', $repository);
        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);
    }

    public function testTopOfHourNewsStillOwnsPriorityAheadOfStrictProgramming(): void
    {
        $news = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourNewsConfigWriter.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/Task/QueueInterruptingTracks.php'
        );

        self::assertIsString($news);
        self::assertIsString($task);
        self::assertStringContainsString(
            '[top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]',
            $news,
        );
        self::assertStringContainsString(
            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',
            $task,
        );
    }
}
''')


hold_test = Path('tests/Unit/TopOfHourPreBoundaryHoldTest.php')
hold_test.write_text('''<?php

declare(strict_types=1);

namespace Tests\\Unit;

use PHPUnit\\Framework\\TestCase;

final class TopOfHourPreBoundaryHoldTest extends TestCase
{
    public function testNormalMusicIsHeldAtTrackBoundaryAroundTopOfHour(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('PRE_BOUNDARY_HOLD_SECONDS = 75', $source);
        self::assertStringContainsString('POST_BOUNDARY_HOLD_SECONDS = 30', $source);
        self::assertStringContainsString('def top_of_hour_hold_new_track() =', $source);
        self::assertStringContainsString('duration=1.', $source);
        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);
        self::assertStringContainsString('track_sensitive=true,', $source);
    }

    public function testHoldReleasesWhenBoundaryIsServedAndLateIdCanMarkBoundary(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('top_of_hour_last_served_boundary() != boundary', $source);
        self::assertStringContainsString('now_seconds - seconds_in_hour', $source);
        self::assertStringContainsString('[top_of_hour_queue, radio_before_top_of_hour]', $source);
    }
}
''')
