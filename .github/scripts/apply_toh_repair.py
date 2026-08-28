from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}")
    p.write_text(text.replace(old, new, 1))


# The natural-boundary Liquidsoap handoff, earlier legal-ID staging, AI News
# chaining, and live post-ID prebuild are already on this branch. The remaining
# scheduler gap is narrower: when the sequence scorer cannot prove a complete
# exact multi-record path, it must not throw away the playlist's already-selected
# record if that record itself ends naturally before the protected handoff.
path = "backend/src/Radio/AutoDJ/QueueBuilder.php"
replace_once(
    path,
    '''        if ($ranked === []) {
            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; routine cut/fade is refused.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }
''',
    '''        if ($ranked === []) {
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
)

# #101's Linear Log regression test still contains the wording from before the
# live queue was taught to cross the same protected boundary. Update the contract
# to cover the shared live/linear path without changing the 24-48 hour horizon.
path = "tests/Unit/LinearLogBoundaryContinuityTest.php"
replace_once(
    path,
    "        self::assertStringContainsString('null !== $lookaheadMinutesOverride', $queue);\n",
    '''        self::assertStringContainsString(
            "null !== $lookaheadMinutesOverride ? 'Linear Log' : 'AutoDJ'",
            $queue,
        );
'''
)
replace_once(
    path,
    '''        self::assertStringContainsString(
            'Linear Log: crossing protected top-of-hour window and continuing projection.',
            $queue,
        );
''',
    '''        self::assertStringContainsString(
            'prebuilding post-ID audio.',
            $queue,
        );
'''
)

# Extend the focused continuity contract so future edits cannot reintroduce the
# starvation fallback or relax the existing no-overrun/no-routine-fade rule.
path = "tests/Unit/TopOfHourContinuityIntegrationTest.php"
p = Path(path)
text = p.read_text()
anchor = '''    public function testRoutineBlankHoldAndRoutineNonTrackSensitiveTakeoverAreGone(): void
'''
if text.count(anchor) != 1:
    raise SystemExit(f"{path}: integration-test insertion anchor mismatch")
new_test = '''    public function testSequenceFallbackKeepsOnlyNaturallyFittingSelectedRecord(): void
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
        self::assertStringContainsString(
            'refusing to turn a normal music track into a routine TOH cut/fade.',
            $source,
        );
    }

'''
p.write_text(text.replace(anchor, new_test + anchor, 1))
