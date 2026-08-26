from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"{label} not found")
    return text.replace(old, new, 1)


planner = Path('backend/src/Radio/AutoDJ/HourBoundaryPlanner.php')
text = planner.read_text()
old = '''    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            return null;
        }

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );
        $maxDuration = (float)($secondsUntil - $this->getMusicProtectionLeadSeconds($station));

        if ($maxDuration < self::MIN_USABLE_CAP_SECONDS) {
            return null;
        }

        return $maxDuration;
    }
'''
new = '''    public function secondsAvailableForMusicBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        if (!$this->isInLookaheadZone($station, $expectedPlayTime)) {
            return null;
        }

        $secondsUntil = $this->secondsUntilNextTopOfHour(
            $expectedPlayTime,
            $station->getTimezoneObject(),
        );

        return max(
            0.0,
            (float)($secondsUntil - $this->getMusicProtectionLeadSeconds($station)),
        );
    }

    public function maxMusicDurationBeforeTopOfHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): ?float {
        $availableSeconds = $this->secondsAvailableForMusicBeforeTopOfHour(
            $station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds || $availableSeconds < self::MIN_USABLE_CAP_SECONDS) {
            return null;
        }

        return $availableSeconds;
    }
'''
planner.write_text(replace_once(text, old, new, 'HourBoundaryPlanner target method'))


queue_builder = Path('backend/src/Radio/AutoDJ/QueueBuilder.php')
text = queue_builder.read_text()
old = '''final class QueueBuilder implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;
'''
new = '''final class QueueBuilder implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    /** Re-rank normal music by exact boundary fit during the final six minutes. */
    private const int TOH_PRECISION_BACKTIME_SECONDS = 360;

    /** Below this point the Liquidsoap pre-boundary hold owns the handoff. */
    private const int TOH_MIN_TIMED_TRACK_SECONDS = 15;
'''
text = replace_once(text, old, new, 'QueueBuilder class marker')

old = '''        $stationQueueEntry = StationQueue::fromRequest($request);
        $stationQueueEntry->playlist = $playlist;

        if (!$deferQueuePersistence) {
            $this->em->persist($stationQueueEntry);
        }
'''
new = '''        if (!$this->requestCanFitTopOfHourBoundary(
            $playlist->station,
            $request->track,
            $expectedPlayTime,
        )) {
            $this->logger->info(
                'Request playlist item deferred because it cannot fit the approaching top-of-hour boundary.',
                [
                    'request_id' => $request->id,
                    'media_id' => $request->track->id,
                ]
            );
            return null;
        }

        $stationQueueEntry = StationQueue::fromRequest($request);
        $stationQueueEntry->playlist = $playlist;
        $this->applyTopOfHourTimingToQueueEntry(
            $stationQueueEntry,
            $request->track,
            $expectedPlayTime,
        );

        if (!$deferQueuePersistence) {
            $this->em->persist($stationQueueEntry);
        }
'''
text = replace_once(text, old, new, 'Request playlist queue block')

old = '''        $topOfHourMaxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
            $playlist->station,
            $expectedPlayTime,
        );

        if (null !== $topOfHourMaxDuration && $mediaToPlay->getCalculatedLength() > $topOfHourMaxDuration) {
            $cappedSeconds = (int)floor($topOfHourMaxDuration);

            $fadeOutSeconds = min(
                $playlist->station->backend_config->getCrossfadeDuration(),
                (float)$cappedSeconds
            );

            $stationQueueEntry->top_of_hour_pre_id_fade = true;
            $stationQueueEntry->top_of_hour_pre_id_fade_seconds = (int)round(max(0.0, $fadeOutSeconds));
            $stationQueueEntry->duration = (float)$cappedSeconds;
        } elseif (null !== $topOfHourMaxDuration) {
            $stretchRatio = $this->stretchCalculator->calculate(
                $mediaToPlay->getCalculatedLength(),
                (int)round($topOfHourMaxDuration),
            );

            if (null !== $stretchRatio) {
                $stationQueueEntry->clock_wheel_stretch_ratio = $stretchRatio;
            }
        }
'''
new = '''        $topOfHourMaxDuration = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $playlist->station,
            $expectedPlayTime,
        );

        // Scheduled programming remains the tighter hard boundary when it arrives
        // before the station-wide TOH music handoff.
        $topOfHourOwnsBoundary = null !== $topOfHourMaxDuration
            && ($maxDuration === null || $topOfHourMaxDuration <= $maxDuration);

        if ($topOfHourOwnsBoundary) {
            $this->applyTopOfHourTimingToQueueEntry(
                $stationQueueEntry,
                $mediaToPlay,
                $expectedPlayTime,
            );
        }
'''
text = replace_once(text, old, new, 'Old TOH queue timing block')

start = text.index('    private function applyHourBoundarySelection(')
end = text.index('    private function filterQueueByRotationGoal(', start)
replacement = '''    private function applyHourBoundarySelection(
        StationPlaylist $playlist,
        StationPlaylistQueue $selectedTrack,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates,
    ): ?StationPlaylistQueue {
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $playlist->station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds) {
            return $selectedTrack;
        }

        // Once the usable music window is effectively gone, keep the already
        // prefetched row intact for after the TOH chain. Liquidsoap's boundary
        // hold prevents it from starting before the ID; do not manufacture a
        // 5-10 second cue-out track just to fill the remaining sliver.
        if ($availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return $selectedTrack;
        }

        $targetSeconds = (int)round($availableSeconds);
        $selectedMedia = $this->em->find(StationMedia::class, $selectedTrack->media_id);
        $precisionBacktime = $availableSeconds <= self::TOH_PRECISION_BACKTIME_SECONDS;

        if (!$precisionBacktime && $selectedMedia instanceof StationMedia) {
            $selectedLength = $selectedMedia->getCalculatedLength();
            if (
                $selectedLength <= $availableSeconds
                || null !== $this->stretchCalculator->calculate($selectedLength, $targetSeconds)
            ) {
                return $selectedTrack;
            }
        }

        // Reuse the same playability and rotation-goal filters used by normal
        // playlist selection. The older TOH fallback scanned the raw queue and
        // could choose a track that normal AutoDJ would have rejected.
        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );
        $candidates = [];
        $byLength = [];

        foreach ($mediaQueue as $queueItem) {
            $candidate = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$candidate instanceof StationMedia) {
                continue;
            }

            $length = $candidate->getCalculatedLength();
            $ratio = $this->stretchCalculator->calculate($length, $targetSeconds);
            $byLength[] = [$queueItem, $length];

            if ($length <= $availableSeconds || null !== $ratio) {
                $candidates[] = [
                    'queue' => $queueItem,
                    'length' => $length,
                    'ratio' => $ratio,
                ];
            }
        }

        if ($candidates !== []) {
            usort(
                $candidates,
                static function (array $a, array $b) use ($availableSeconds): int {
                    $aExact = null !== $a['ratio'];
                    $bExact = null !== $b['ratio'];

                    if ($aExact !== $bExact) {
                        return $aExact ? -1 : 1;
                    }

                    if ($aExact) {
                        $aStretch = abs(1.0 - (float)$a['ratio']);
                        $bStretch = abs(1.0 - (float)$b['ratio']);
                        $stretchOrder = $aStretch <=> $bStretch;
                        if (0 !== $stretchOrder) {
                            return $stretchOrder;
                        }
                    }

                    $aGap = max(0.0, $availableSeconds - (float)$a['length']);
                    $bGap = max(0.0, $availableSeconds - (float)$b['length']);
                    return $aGap <=> $bGap;
                }
            );

            $ordered = array_map(
                static fn(array $row): StationPlaylistQueue => $row['queue'],
                $candidates,
            );

            if ($playlist->avoid_duplicates) {
                $duplicateSafe = $this->duplicatePrevention->preventDuplicates(
                    $ordered,
                    $recentSongHistory,
                    $allowDuplicates,
                );
                if (null !== $duplicateSafe) {
                    $this->logger->info('Hour boundary: precision-backtimed a duplicate-safe music track.', [
                        'playlist_id' => $playlist->id,
                        'target_seconds' => $availableSeconds,
                        'media_id' => $duplicateSafe->media_id,
                    ]);
                    return $duplicateSafe;
                }
            } else {
                $chosen = $ordered[0];
                $this->logger->info('Hour boundary: precision-backtimed music to the TOH handoff.', [
                    'playlist_id' => $playlist->id,
                    'target_seconds' => $availableSeconds,
                    'media_id' => $chosen->media_id,
                ]);
                return $chosen;
            }
        }

        if (!$selectedMedia instanceof StationMedia) {
            return $selectedTrack;
        }

        // No candidate can naturally fit or reach the boundary inside the safe
        // +/-5% stretch window. Pick the shortest duplicate-safe option; the
        // annotation layer may then use cue-out/fade as the final safety net.
        usort($byLength, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        if ($byLength !== []) {
            $shortestFew = array_map(
                static fn(array $row): StationPlaylistQueue => $row[0],
                array_slice($byLength, 0, 5),
            );
            $nonRepeat = $this->duplicatePrevention->preventDuplicates(
                $shortestFew,
                $recentSongHistory,
                false,
            );
            if (null !== $nonRepeat) {
                return $nonRepeat;
            }

            if ($byLength[0][1] < $selectedMedia->getCalculatedLength()) {
                return $byLength[0][0];
            }
        }

        $this->logger->warning(
            'Hour boundary: no natural or stretchable fit exists; retaining selected track for safety fallback.',
            [
                'playlist_id' => $playlist->id,
                'target_seconds' => $availableSeconds,
            ]
        );

        return $selectedTrack;
    }

    private function requestCanFitTopOfHourBoundary(
        Station $station,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds || $availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return true;
        }

        $length = $media->getCalculatedLength();
        return $length <= $availableSeconds
            || null !== $this->stretchCalculator->calculate(
                $length,
                (int)round($availableSeconds),
            );
    }

    private function applyTopOfHourTimingToQueueEntry(
        StationQueue $queueEntry,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): void {
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $queueEntry->station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds || $availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return;
        }

        $targetSeconds = (int)round($availableSeconds);
        $mediaLength = $media->getCalculatedLength();

        // Prefer the already-existing pitch-preserving +/-5% stretch/squeeze
        // engine before ever shortening a record with cue_out/fade.
        $stretchRatio = $this->stretchCalculator->calculate($mediaLength, $targetSeconds);
        if (null !== $stretchRatio) {
            $queueEntry->clock_wheel_stretch_ratio = $stretchRatio;
            $queueEntry->top_of_hour_pre_id_fade = false;
            $queueEntry->top_of_hour_pre_id_fade_seconds = null;
            return;
        }

        if ($mediaLength <= $availableSeconds) {
            return;
        }

        $cappedSeconds = (int)floor($availableSeconds);
        if ($cappedSeconds < 1) {
            return;
        }

        $fadeOutSeconds = min(
            $queueEntry->station->backend_config->getCrossfadeDuration(),
            (float)$cappedSeconds,
        );

        $queueEntry->hour_boundary_enforce_cap = true;
        $queueEntry->hour_boundary_max_play_seconds = $cappedSeconds;
        $queueEntry->top_of_hour_pre_id_fade = true;
        $queueEntry->top_of_hour_pre_id_fade_seconds = (int)round(max(0.0, $fadeOutSeconds));
        $queueEntry->duration = (float)$cappedSeconds;
    }

'''
text = text[:start] + replacement + text[end:]

old = '''        $this->logger->debug(sprintf('Queueing next song from request ID %d.', $request->id));

        $stationQueueEntry = StationQueue::fromRequest($request);
        $this->em->persist($stationQueueEntry);
'''
new = '''        if (!$this->requestCanFitTopOfHourBoundary(
            $station,
            $request->track,
            $expectedPlayTime,
        )) {
            $this->logger->info(
                'Listener request deferred because it cannot fit the approaching top-of-hour boundary.',
                [
                    'request_id' => $request->id,
                    'media_id' => $request->track->id,
                ]
            );
            return;
        }

        $this->logger->debug(sprintf('Queueing next song from request ID %d.', $request->id));

        $stationQueueEntry = StationQueue::fromRequest($request);
        $this->applyTopOfHourTimingToQueueEntry(
            $stationQueueEntry,
            $request->track,
            $expectedPlayTime,
        );
        $this->em->persist($stationQueueEntry);
'''
text = replace_once(text, old, new, 'Global request queue block')
queue_builder.write_text(text)


config_writer = Path('backend/src/Radio/Backend/Liquidsoap/ConfigWriter.php')
text = config_writer.read_text()
old = '''source.methods(radio).on_track(synchronous=false, fun (m) -> begin
              clock_wheel_stretch_ratio := float_of_string(default=1.0, m["liq_stretch_ratio"])'''
new = '''source.methods(radio).on_track(synchronous=true, fun (m) -> begin
              clock_wheel_stretch_ratio := float_of_string(default=1.0, m["liq_stretch_ratio"])'''
config_writer.write_text(replace_once(text, old, new, 'Liquidsoap stretch callback marker'))


toh_writer = Path('backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php')
text = toh_writer.read_text()
toh_writer.write_text(replace_once(
    text,
    '    private const int PRE_BOUNDARY_HOLD_SECONDS = 60;',
    '    private const int PRE_BOUNDARY_HOLD_SECONDS = 75;',
    'TOH hold constant',
))


test_file = Path('tests/Unit/TopOfHourSmartBacktimingTest.php')
test_file.write_text('''<?php

declare(strict_types=1);

namespace Tests\\Unit;

use PHPUnit\\Framework\\TestCase;

final class TopOfHourSmartBacktimingTest extends TestCase
{
    public function testQueueBuilderUsesPrecisionBacktimingAndStretchBeforeFade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            'private const int TOH_PRECISION_BACKTIME_SECONDS = 360;',
            $source,
        );
        self::assertStringContainsString('$this->preparePlaylistQueue(', $source);
        self::assertStringContainsString(
            'Hour boundary: precision-backtimed music to the TOH handoff.',
            $source,
        );

        $timingStart = strpos($source, 'private function applyTopOfHourTimingToQueueEntry');
        self::assertNotFalse($timingStart);
        $timingBlock = substr($source, $timingStart, 4500);
        $stretch = strpos($timingBlock, '$this->stretchCalculator->calculate');
        $fade = strpos($timingBlock, '$queueEntry->top_of_hour_pre_id_fade = true;');
        self::assertNotFalse($stretch);
        self::assertNotFalse($fade);
        self::assertLessThan($fade, $stretch);
    }

    public function testRequestsCannotForceAnOverrunAtTopOfHour(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            'private function requestCanFitTopOfHourBoundary(',
            $source,
        );
        self::assertStringContainsString(
            'Listener request deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
        self::assertStringContainsString(
            'Request playlist item deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
    }

    public function testStretchMetadataHandoffIsSynchronous(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/ConfigWriter.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            'source.methods(radio).on_track(synchronous=true, fun (m) -> begin',
            $source,
        );
    }
}
''')
