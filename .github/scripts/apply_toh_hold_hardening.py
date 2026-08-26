from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise SystemExit(f"{label} not found")
    return text.replace(old, new, 1)


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
