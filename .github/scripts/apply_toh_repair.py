from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}")
    p.write_text(text.replace(old, new, 1))


# 1. Open-hour IDs must be staged as soon as the established :58 ID window opens.
path = "backend/src/Radio/AutoDJ/TopOfHourIdScheduler.php"
replace_once(
    path,
    '''    /**
     * Open-hour boundaries wait until :59 before interrupting normal music.
     */
    private const int OPEN_HOUR_TRIGGER_LEAD_SECONDS = 60;

''',
    ''
)
replace_once(
    path,
    '''        return $secondsUntilTop > 0
            && $secondsUntilTop <= self::OPEN_HOUR_TRIGGER_LEAD_SECONDS;
''',
    '''        return $secondsUntilTop > 0
            && $secondsUntilTop <= $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station);
'''
)
replace_once(
    path,
    '''                'Top-of-hour ID deferred: next hour is open and the :59 trigger window has not arrived.'
''',
    '''                'Top-of-hour ID deferred: next hour is open and the natural-handoff window has not arrived.'
'''
)

# 2. Remove the blank/silence hold. A staged ID wins only at a natural track boundary.
path = "backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php"
replace_once(path, "    private const int PRE_BOUNDARY_HOLD_SECONDS = 75;\n", "")
replace_once(
    path,
    "        $holdStartSecond = HourBoundaryPlanner::HOUR_SECONDS - self::PRE_BOUNDARY_HOLD_SECONDS;\n",
    ""
)
old_hold = '''            # Do not start a fresh normal song during the protected ID window.
            # The selector now backtimes the final records as a sequence; this
            # hold is the runtime safety layer that waits quietly if the music
            # ends a few seconds early rather than starting a song that would
            # have to be faded out for the legal ID.
            def top_of_hour_hold_new_track() =
              now = time()
              now_seconds = int_of_float(now)
              seconds_in_hour = top_of_hour_seconds_in_station_hour(now)

              if seconds_in_hour >= {$holdStartSecond} then
                boundary = now_seconds + (3600 - seconds_in_hour)
                top_of_hour_last_served_boundary() != boundary
              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then
                # After :00, hold only while this boundary actually has an ID
                # claimed or waiting. A missed enqueue fails open to programming.
                boundary = now_seconds - seconds_in_hour
                boundary_has_delivery =
                  top_of_hour_claimed_boundary() == boundary or
                  top_of_hour_queue.length() > 0 or
                  top_of_hour_queue.is_ready()
                boundary_has_delivery and top_of_hour_last_served_boundary() != boundary
              else
                false
              end
            end

            radio_before_top_of_hour_unheld = radio_before_top_of_hour
            top_of_hour_preboundary_hold = blank(
              id="top_of_hour_preboundary_hold",
              duration=1.
            )
            radio_before_top_of_hour = switch(
              id="top_of_hour_preboundary_hold_switch",
              track_sensitive=true,
              [
                ({ top_of_hour_hold_new_track() }, top_of_hour_preboundary_hold),
                ({ true }, radio_before_top_of_hour_unheld)
              ]
            )

            # Rebuild the outer TOH wrapper around the held normal-program source.
            # The legal-ID queue remains non-track-sensitive only as the emergency
            # last line of defense. Under normal operation the music has already
            # ended naturally and this transition is from the silent hold.
            radio = fallback(
              id="top_of_hour_hold_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, radio_before_top_of_hour]
            )
'''
new_handoff = '''            # Stage the legal ID before the final record ends, but never use
            # silence or a forced transition as the timing tool. This fallback is
            # track-sensitive: the current record finishes naturally, then a ready
            # legal ID wins the very next clean track boundary. If no ID is ready,
            # normal programming remains available and audio never falls to blank.
            radio = fallback(
              id="top_of_hour_natural_handoff",
              track_sensitive=true,
              [top_of_hour_queue, radio_before_top_of_hour]
            )
'''
replace_once(path, old_hold, new_handoff)

# 3. AI News must preserve that natural boundary and be armed before an ID can
#    legitimately start in minute 58.
path = "backend/src/Radio/Backend/Liquidsoap/TopOfHourNewsConfigWriter.php"
replace_once(
    path,
    '''            radio = fallback(
              id="top_of_hour_priority_fallback",
              track_sensitive=false,
              transitions=[to_top_of_hour, from_top_of_hour, from_top_of_hour],
              [top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]
            )
''',
    '''            radio = fallback(
              id="top_of_hour_priority_natural_handoff",
              track_sensitive=true,
              [top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]
            )
'''
)
replace_once(
    path,
    '            cron.add("59 * * * {$cronDays}", {arm_top_news_bulletin()})\n',
    '            cron.add("58 * * * {$cronDays}", {arm_top_news_bulletin()})\n'
)

# 4. The protected handoff is a queue-planning boundary, not the end of the
#    live queue. Prebuild the first post-ID row for live AutoDJ as well as the
#    long-range Linear Log so the ID cannot end into an empty normal queue.
path = "backend/src/Radio/AutoDJ/Queue.php"
replace_once(
    path,
    '''                if (
                    null !== $lookaheadMinutesOverride
                    && $this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $expectedPlayTime)
                ) {
''',
    '''                if ($this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $expectedPlayTime)) {
'''
)
replace_once(
    path,
    '''                        $this->logger->info(
                            'Linear Log: crossing protected top-of-hour window and continuing projection.',
                            [
                                'from' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
                                'resume_at' => $resumeAt->format(DateTimeImmutable::ATOM),
                            ]
                        );
''',
    '''                        $queueMode = null !== $lookaheadMinutesOverride ? 'Linear Log' : 'AutoDJ';
                        $this->logger->info(
                            $queueMode . ': reserving the protected TOH handoff and prebuilding post-ID audio.',
                            [
                                'from' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
                                'resume_at' => $resumeAt->format(DateTimeImmutable::ATOM),
                            ]
                        );
'''
)

# 5. The multi-song scorer is an optimization, not permission to starve a
#    perfectly valid record. If it cannot prove an exact sequence, keep the
#    already-selected record only when that record itself ends naturally inside
#    the remaining music window. Never allow an overrun and never add a fade.
path = "backend/src/Radio/AutoDJ/QueueBuilder.php"
old_ranked = '''        if ($ranked === []) {
            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; routine cut/fade is refused.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }
'''
new_ranked = '''        if ($ranked === []) {
            $selectedMedia = $this->em->find(StationMedia::class, $selectedTrack->media_id);
            if ($selectedMedia instanceof StationMedia) {
                $selectedAirtime = $this->topOfHourSequencePlanner->getNaturalAirtime(
                    $selectedMedia->getCalculatedLength(),
                    $playlist->station->backend_config->getCrossfadeDuration(),
                );

                if (
                    $selectedAirtime > 0.0
                    && $selectedAirtime <= $availableSeconds + TopOfHourSequencePlanner::NATURAL_TOLERANCE_SECONDS
                ) {
                    $this->logger->info(
                        'Hour boundary: exact sequence unavailable; keeping a naturally fitting record.',
                        [
                            'playlist_id' => $playlist->id,
                            'media_id' => $selectedTrack->media_id,
                            'available_seconds' => $availableSeconds,
                            'natural_airtime' => $selectedAirtime,
                        ]
                    );
                    return $selectedTrack;
                }
            }

            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; reserving the natural ID break.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }
'''
replace_once(path, old_ranked, new_ranked)

# Replace the old test that explicitly required the blank hold.
old_test = Path("tests/Unit/TopOfHourPreBoundaryHoldTest.php")
if not old_test.exists():
    raise SystemExit("missing tests/Unit/TopOfHourPreBoundaryHoldTest.php")
old_test.unlink()

Path("tests/Unit/TopOfHourNaturalHandoffTest.php").write_text(r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourNaturalHandoffTest extends TestCase
{
    public function testLegalIdWaitsForNaturalTrackBoundaryWithoutBlankHold(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('id="top_of_hour_natural_handoff"', $source);
        self::assertStringContainsString('track_sensitive=true,', $source);
        self::assertStringContainsString('[top_of_hour_queue, radio_before_top_of_hour]', $source);
        self::assertStringNotContainsString('PRE_BOUNDARY_HOLD_SECONDS', $source);
        self::assertStringNotContainsString('top_of_hour_preboundary_hold', $source);
        self::assertStringNotContainsString('top_of_hour_hold_new_track', $source);
        self::assertStringNotContainsString('id="top_of_hour_hold_fallback"', $source);
    }

    public function testLegalIdOwnershipAndActualPlaybackFeedbackRemainIntact(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('top_of_hour_claimed_request_id', $source);
        self::assertStringContainsString('top_of_hour_last_served_boundary := boundary', $source);
        self::assertStringContainsString('top_of_hour_send_feedback', $source);
        self::assertStringContainsString('"azuracast_top_of_hour_id"', $source);
    }
}
''')

# Extend news priority coverage for natural handoff and minute-58 arming.
news_test = Path("tests/Unit/TopOfHourNewsPriorityTest.php")
text = news_test.read_text()
anchor = '''        self::assertStringContainsString(
            'top_news_bulletin_queue.push(request.create(top_news_bulletin_request))',
            $source,
        );
'''
extra = anchor + '''        self::assertStringContainsString('track_sensitive=true,', $source);
        self::assertStringContainsString(
            'cron.add("58 * * * {$cronDays}", {arm_top_news_bulletin()})',
            $source,
        );
        self::assertStringNotContainsString(
            'transitions=[to_top_of_hour, from_top_of_hour, from_top_of_hour]',
            $source,
        );
'''
if text.count(anchor) != 1:
    raise SystemExit("TopOfHourNewsPriorityTest anchor mismatch")
news_test.write_text(text.replace(anchor, extra, 1))

# New integration contracts for early staging, live post-ID prefetch, and
# non-starving/no-overrun selection fallback.
Path("tests/Unit/TopOfHourContinuityIntegrationTest.php").write_text(r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourContinuityIntegrationTest extends TestCase
{
    public function testOpenHourIdStagesAcrossConfiguredIdWindow(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/TopOfHourIdScheduler.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            '$this->hourBoundaryPlanner->getIdWindowLeadSeconds($station)',
            $source,
        );
        self::assertStringNotContainsString('OPEN_HOUR_TRIGGER_LEAD_SECONDS = 60', $source);
        self::assertStringContainsString('natural-handoff window', $source);
    }

    public function testLiveQueuePrebuildsPostIdAudioAcrossProtectedWindow(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'if ($this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $expectedPlayTime))',
            $source,
        );
        self::assertStringContainsString(
            "$queueMode = null !== $lookaheadMinutesOverride ? 'Linear Log' : 'AutoDJ';",
            $source,
        );
        self::assertStringContainsString('prebuilding post-ID audio.', $source);
        self::assertStringNotContainsString(
            'null !== $lookaheadMinutesOverride' . "\n"
            . '                    && $this->hourBoundaryPlanner->isInTopOfHourIdWindow',
            $source,
        );
    }

    public function testSequenceFallbackKeepsOnlyNaturallyFittingMusic(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'exact sequence unavailable; keeping a naturally fitting record.',
            $source,
        );
        self::assertStringContainsString(
            '$selectedAirtime <= $availableSeconds + TopOfHourSequencePlanner::NATURAL_TOLERANCE_SECONDS',
            $source,
        );
        self::assertStringContainsString('reserving the natural ID break.', $source);
        self::assertStringNotContainsString('routine cut/fade is refused.', $source);
    }
}
''')

# Update the #101 Linear Log regression for the shared live/linear crossing path.
linear_test = Path("tests/Unit/LinearLogBoundaryContinuityTest.php")
text = linear_test.read_text()
old = "        self::assertStringContainsString('null !== $lookaheadMinutesOverride', $queue);\n"
new = "        self::assertStringContainsString(\"null !== $lookaheadMinutesOverride ? 'Linear Log' : 'AutoDJ'\", $queue);\n"
if old not in text:
    raise SystemExit("LinearLogBoundaryContinuityTest lookahead assertion anchor missing")
text = text.replace(old, new, 1)
old = "            'Linear Log: crossing protected top-of-hour window and continuing projection.',\n"
new = "            'prebuilding post-ID audio.',\n"
if old not in text:
    raise SystemExit("LinearLogBoundaryContinuityTest message anchor missing")
linear_test.write_text(text.replace(old, new, 1))
