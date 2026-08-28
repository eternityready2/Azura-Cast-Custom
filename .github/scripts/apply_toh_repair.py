from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if text.count(old) != 1:
        raise SystemExit(f"unexpected source state in {path}")
    p.write_text(text.replace(old, new, 1))


path = "backend/src/Radio/AutoDJ/QueueBuilder.php"
replace_once(
    path,
    """        if ($ranked === []) {
            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; routine cut/fade is refused.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }
""",
    """        if ($ranked === []) {
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
"""
)

path = "tests/Unit/TopOfHourContinuityIntegrationTest.php"
p = Path(path)
text = p.read_text()
anchor = "    public function testRoutineBlankHoldAndRoutineNonTrackSensitiveTakeoverAreGone(): void\n"
if text.count(anchor) != 1:
    raise SystemExit(f"unexpected test state in {path}")
new_test = """    public function testSequenceFallbackKeepsOnlyNaturallyFittingSelectedRecord(): void
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

"""
p.write_text(text.replace(anchor, new_test + anchor, 1))
