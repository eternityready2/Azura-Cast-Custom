from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def write(path: str, content: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content)


def replace_once(path: str, old: str, new: str) -> None:
    content = read(path)
    count = content.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected one exact match, found {count}")
    write(path, content.replace(old, new, 1))


def replace_all(path: str, old: str, new: str, expected: int) -> None:
    content = read(path)
    count = content.count(old)
    if count != expected:
        raise RuntimeError(f"{path}: expected {expected} exact matches, found {count}")
    write(path, content.replace(old, new))


def regex_once(path: str, pattern: str, replacement: str) -> None:
    content = read(path)
    updated, count = re.subn(pattern, replacement, content, count=1, flags=re.S)
    if count != 1:
        raise RuntimeError(f"{path}: expected one regex match, found {count}")
    write(path, updated)


# ---------------------------------------------------------------------------
# Professional TOH backtiming: plan a viable sequence, never plan a routine cut.
# ---------------------------------------------------------------------------
write(
    "backend/src/Radio/AutoDJ/TopOfHourSequencePlanner.php",
    r'''<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;

/**
 * Ranks the first track of a short end-of-hour music sequence.
 *
 * Intermediate tracks are left at natural speed. Only the final track may use
 * the station's bounded pitch-preserving stretch so the music lands cleanly on
 * the protected TOH handoff without routine cue-out/fade truncation.
 */
final class TopOfHourSequencePlanner
{
    public const float NATURAL_TOLERANCE_SECONDS = 2.0;

    private const int MAX_SEQUENCE_TRACKS = 4;

    public function __construct(
        private readonly ClockWheelStretchCalculator $stretchCalculator,
    ) {
    }

    /**
     * @param array<int, array{key:int, length:float, order?:int}> $candidates
     * @return array<int, array{
     *     key:int,
     *     length:float,
     *     order:int,
     *     gap:float,
     *     stretch_penalty:float,
     *     tracks:int,
     *     first_ratio:float|null
     * }>
     */
    public function rankFirstCandidates(
        array $candidates,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): array {
        if ($availableSeconds <= 0.0 || $candidates === []) {
            return [];
        }

        $normalized = [];
        foreach ($candidates as $index => $candidate) {
            $length = (float)$candidate['length'];
            if ($length <= 0.0) {
                continue;
            }

            $normalized[] = [
                'key' => (int)$candidate['key'],
                'length' => $length,
                'order' => (int)($candidate['order'] ?? $index),
            ];
        }

        if ($normalized === []) {
            return [];
        }

        $memo = [];
        $ranked = [];

        foreach ($normalized as $candidate) {
            $score = $this->scoreFirstCandidate(
                $candidate['length'],
                $availableSeconds,
                $crossfadeSeconds,
                $normalized,
                $memo,
            );

            if ($score === null) {
                continue;
            }

            $ranked[] = [
                ...$candidate,
                ...$score,
            ];
        }

        usort($ranked, [self::class, 'compareScores']);
        return $ranked;
    }

    public function getNaturalAirtime(float $sourceSeconds, float $crossfadeSeconds): float
    {
        if ($sourceSeconds <= 0.0) {
            return 0.0;
        }

        $crossfade = ($crossfadeSeconds > 0.0 && $sourceSeconds >= $crossfadeSeconds)
            ? $crossfadeSeconds
            : 0.0;

        return max(0.0, $sourceSeconds - $crossfade);
    }

    public function getStretchRatioToFill(
        float $sourceSeconds,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): ?float {
        if ($sourceSeconds <= 0.0 || $availableSeconds <= 0.0) {
            return null;
        }

        $crossfade = $crossfadeSeconds > 0.0 ? $crossfadeSeconds : 0.0;
        $targetSourceSeconds = (int)round($availableSeconds + $crossfade);

        return $this->stretchCalculator->calculate($sourceSeconds, $targetSourceSeconds);
    }

    public function canFinishAtHandoff(
        float $sourceSeconds,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): bool {
        return $this->getFinalFit($sourceSeconds, $availableSeconds, $crossfadeSeconds) !== null;
    }

    /**
     * @param array{key:int, length:float, order:int}[] $candidates
     * @param array<string, array{gap:float, stretch_penalty:float, tracks:int}|null> $memo
     * @return array{gap:float, stretch_penalty:float, tracks:int, first_ratio:float|null}|null
     */
    private function scoreFirstCandidate(
        float $sourceSeconds,
        float $availableSeconds,
        float $crossfadeSeconds,
        array $candidates,
        array &$memo,
    ): ?array {
        $finalFit = $this->getFinalFit($sourceSeconds, $availableSeconds, $crossfadeSeconds);
        if ($finalFit !== null) {
            return [
                'gap' => $finalFit['gap'],
                'stretch_penalty' => $finalFit['stretch_penalty'],
                'tracks' => 1,
                'first_ratio' => $finalFit['ratio'],
            ];
        }

        $naturalAirtime = $this->getNaturalAirtime($sourceSeconds, $crossfadeSeconds);
        if (
            $naturalAirtime <= 0.0
            || $naturalAirtime >= $availableSeconds - self::NATURAL_TOLERANCE_SECONDS
        ) {
            return null;
        }

        $remaining = $availableSeconds - $naturalAirtime;
        $completion = $this->findBestCompletion(
            $remaining,
            self::MAX_SEQUENCE_TRACKS - 1,
            $crossfadeSeconds,
            $candidates,
            $memo,
        );

        if ($completion === null) {
            return null;
        }

        return [
            'gap' => $completion['gap'],
            'stretch_penalty' => $completion['stretch_penalty'],
            'tracks' => 1 + $completion['tracks'],
            'first_ratio' => null,
        ];
    }

    /**
     * @param array{key:int, length:float, order:int}[] $candidates
     * @param array<string, array{gap:float, stretch_penalty:float, tracks:int}|null> $memo
     * @return array{gap:float, stretch_penalty:float, tracks:int}|null
     */
    private function findBestCompletion(
        float $remainingSeconds,
        int $tracksLeft,
        float $crossfadeSeconds,
        array $candidates,
        array &$memo,
    ): ?array {
        if ($remainingSeconds <= self::NATURAL_TOLERANCE_SECONDS) {
            return [
                'gap' => max(0.0, $remainingSeconds),
                'stretch_penalty' => 0.0,
                'tracks' => 0,
            ];
        }

        if ($tracksLeft <= 0) {
            return null;
        }

        $memoKey = sprintf('%.1f:%d:%.1f', $remainingSeconds, $tracksLeft, $crossfadeSeconds);
        if (array_key_exists($memoKey, $memo)) {
            return $memo[$memoKey];
        }

        $best = null;

        foreach ($candidates as $candidate) {
            $sourceSeconds = $candidate['length'];
            $finalFit = $this->getFinalFit($sourceSeconds, $remainingSeconds, $crossfadeSeconds);

            if ($finalFit !== null) {
                $score = [
                    'gap' => $finalFit['gap'],
                    'stretch_penalty' => $finalFit['stretch_penalty'],
                    'tracks' => 1,
                ];
                if ($best === null || self::compareScores($score, $best) < 0) {
                    $best = $score;
                }
            }

            if ($tracksLeft <= 1) {
                continue;
            }

            $naturalAirtime = $this->getNaturalAirtime($sourceSeconds, $crossfadeSeconds);
            if (
                $naturalAirtime <= 0.0
                || $naturalAirtime >= $remainingSeconds - self::NATURAL_TOLERANCE_SECONDS
            ) {
                continue;
            }

            $next = $this->findBestCompletion(
                $remainingSeconds - $naturalAirtime,
                $tracksLeft - 1,
                $crossfadeSeconds,
                $candidates,
                $memo,
            );

            if ($next === null) {
                continue;
            }

            $score = [
                'gap' => $next['gap'],
                'stretch_penalty' => $next['stretch_penalty'],
                'tracks' => 1 + $next['tracks'],
            ];
            if ($best === null || self::compareScores($score, $best) < 0) {
                $best = $score;
            }
        }

        $memo[$memoKey] = $best;
        return $best;
    }

    /**
     * @return array{gap:float, stretch_penalty:float, ratio:float|null}|null
     */
    private function getFinalFit(
        float $sourceSeconds,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): ?array {
        $naturalAirtime = $this->getNaturalAirtime($sourceSeconds, $crossfadeSeconds);
        $naturalGap = abs($availableSeconds - $naturalAirtime);

        if (
            $naturalAirtime <= $availableSeconds + self::NATURAL_TOLERANCE_SECONDS
            && $naturalGap <= self::NATURAL_TOLERANCE_SECONDS
        ) {
            return [
                'gap' => $naturalGap,
                'stretch_penalty' => 0.0,
                'ratio' => null,
            ];
        }

        $ratio = $this->getStretchRatioToFill(
            $sourceSeconds,
            $availableSeconds,
            $crossfadeSeconds,
        );
        if ($ratio === null) {
            return null;
        }

        return [
            'gap' => 0.0,
            'stretch_penalty' => abs(1.0 - $ratio),
            'ratio' => $ratio,
        ];
    }

    private static function compareScores(array $a, array $b): int
    {
        $gap = ((float)($a['gap'] ?? 0.0)) <=> ((float)($b['gap'] ?? 0.0));
        if ($gap !== 0) {
            return $gap;
        }

        $stretch = ((float)($a['stretch_penalty'] ?? 0.0))
            <=> ((float)($b['stretch_penalty'] ?? 0.0));
        if ($stretch !== 0) {
            return $stretch;
        }

        $tracks = ((int)($a['tracks'] ?? 0)) <=> ((int)($b['tracks'] ?? 0));
        if ($tracks !== 0) {
            return $tracks;
        }

        return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
    }
}
'''
)

queue_builder = "backend/src/Radio/AutoDJ/QueueBuilder.php"
replace_once(
    queue_builder,
    "    /** Re-rank normal music by exact boundary fit during the final six minutes. */\n"
    "    private const int TOH_PRECISION_BACKTIME_SECONDS = 360;\n\n"
    "    /** Below this point the Liquidsoap pre-boundary hold owns the handoff. */\n"
    "    private const int TOH_MIN_TIMED_TRACK_SECONDS = 15;",
    "    /** Do not start another full music item once only a tiny handoff sliver remains. */\n"
    "    private const int TOH_MIN_TIMED_TRACK_SECONDS = 15;",
)
replace_once(
    queue_builder,
    "        private readonly HourBoundaryPlanner $hourBoundaryPlanner,\n"
    "        private readonly ClockWheel\\ClockWheelStretchCalculator $stretchCalculator,",
    "        private readonly HourBoundaryPlanner $hourBoundaryPlanner,\n"
    "        private readonly TopOfHourSequencePlanner $topOfHourSequencePlanner,",
)

regex_once(
    queue_builder,
    r"    private function applyHourBoundarySelection\(.*?\n    private function requestCanFitTopOfHourBoundary\(",
    r'''    private function applyHourBoundarySelection(
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

        if ($availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            $this->logger->info(
                'Hour boundary: protected handoff is too close to start another full music track.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }

        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );
        $plannerCandidates = [];
        $queueByKey = [];

        foreach ($mediaQueue as $queueItem) {
            $candidate = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$candidate instanceof StationMedia) {
                continue;
            }

            $key = count($plannerCandidates);
            $plannerCandidates[] = [
                'key' => $key,
                'length' => $candidate->getCalculatedLength(),
                'order' => $key,
            ];
            $queueByKey[$key] = $queueItem;
        }

        $ranked = $this->topOfHourSequencePlanner->rankFirstCandidates(
            $plannerCandidates,
            $availableSeconds,
            $playlist->station->backend_config->getCrossfadeDuration(),
        );

        if ($ranked === []) {
            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; refusing a routine cut/fade.',
                [
                    'playlist_id' => $playlist->id,
                    'available_seconds' => $availableSeconds,
                ]
            );
            return null;
        }

        $ordered = array_map(
            static fn(array $row): StationPlaylistQueue => $queueByKey[$row['key']],
            $ranked,
        );

        $chosen = null;
        if ($playlist->avoid_duplicates) {
            $chosen = $this->duplicatePrevention->preventDuplicates(
                $ordered,
                $recentSongHistory,
                $allowDuplicates,
            );
        } else {
            $chosen = $ordered[0] ?? null;
        }

        if (!$chosen instanceof StationPlaylistQueue) {
            $this->logger->warning(
                'Hour boundary: all clean backtiming candidates were rejected by duplicate prevention.',
                ['playlist_id' => $playlist->id]
            );
            return null;
        }

        $chosenPlan = null;
        foreach ($ranked as $row) {
            if ($queueByKey[$row['key']] === $chosen) {
                $chosenPlan = $row;
                break;
            }
        }

        $this->logger->info(
            'Hour boundary: selected a clean backtimed music sequence.',
            [
                'playlist_id' => $playlist->id,
                'target_seconds' => $availableSeconds,
                'media_id' => $chosen->media_id,
                'planned_tracks' => $chosenPlan['tracks'] ?? null,
                'planned_gap_seconds' => $chosenPlan['gap'] ?? null,
                'stretch_penalty' => $chosenPlan['stretch_penalty'] ?? null,
            ]
        );

        return $chosen;
    }

    private function requestCanFitTopOfHourBoundary(''',
)

regex_once(
    queue_builder,
    r"    private function requestCanFitTopOfHourBoundary\(.*?\n    private function applyTopOfHourTimingToQueueEntry\(",
    r'''    private function requestCanFitTopOfHourBoundary(
        Station $station,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds) {
            return true;
        }

        if ($availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return false;
        }

        return $this->topOfHourSequencePlanner->canFinishAtHandoff(
            $media->getCalculatedLength(),
            $availableSeconds,
            $station->backend_config->getCrossfadeDuration(),
        );
    }

    private function applyTopOfHourTimingToQueueEntry(''',
)

regex_once(
    queue_builder,
    r"    private function applyTopOfHourTimingToQueueEntry\(.*?\n    private function filterQueueByRotationGoal\(",
    r'''    private function applyTopOfHourTimingToQueueEntry(
        StationQueue $queueEntry,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): bool {
        $availableSeconds = $this->hourBoundaryPlanner->secondsAvailableForMusicBeforeTopOfHour(
            $queueEntry->station,
            $expectedPlayTime,
        );

        if (null === $availableSeconds) {
            return true;
        }

        if ($availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return false;
        }

        $mediaLength = $media->getCalculatedLength();
        $crossfadeSeconds = $queueEntry->station->backend_config->getCrossfadeDuration();
        $stretchRatio = $this->topOfHourSequencePlanner->getStretchRatioToFill(
            $mediaLength,
            $availableSeconds,
            $crossfadeSeconds,
        );

        if (null !== $stretchRatio) {
            $queueEntry->clock_wheel_stretch_ratio = $stretchRatio;
            $queueEntry->hour_boundary_enforce_cap = false;
            $queueEntry->hour_boundary_max_play_seconds = null;
            $queueEntry->top_of_hour_pre_id_fade = false;
            $queueEntry->top_of_hour_pre_id_fade_seconds = null;
            return true;
        }

        $naturalAirtime = $this->topOfHourSequencePlanner->getNaturalAirtime(
            $mediaLength,
            $crossfadeSeconds,
        );
        if ($naturalAirtime <= $availableSeconds + TopOfHourSequencePlanner::NATURAL_TOLERANCE_SECONDS) {
            $queueEntry->hour_boundary_enforce_cap = false;
            $queueEntry->hour_boundary_max_play_seconds = null;
            $queueEntry->top_of_hour_pre_id_fade = false;
            $queueEntry->top_of_hour_pre_id_fade_seconds = null;
            return true;
        }

        $this->logger->warning(
            'Hour boundary: refusing to annotate a normal music track with a routine TOH cut/fade.',
            [
                'media_id' => $media->id,
                'media_length' => $mediaLength,
                'available_seconds' => $availableSeconds,
            ]
        );
        return false;
    }

    private function filterQueueByRotationGoal(''',
)

# A rejected boundary track must not advance the playlist-media rotation.
replace_once(
    queue_builder,
    "        $spm = $this->em->find(StationPlaylistMedia::class, $validTrack->spm_id);\n"
    "        if ($spm instanceof StationPlaylistMedia) {\n"
    "            $spm->played($expectedPlayTime->getTimestamp());\n"
    "            $this->em->persist($spm);\n"
    "        }\n\n"
    "        $stationQueueEntry = StationQueue::fromMedia($playlist->station, $mediaToPlay);",
    "        $stationQueueEntry = StationQueue::fromMedia($playlist->station, $mediaToPlay);",
)
replace_once(
    queue_builder,
    "        if ($topOfHourOwnsBoundary) {\n"
    "            $this->applyTopOfHourTimingToQueueEntry(\n"
    "                $stationQueueEntry,\n"
    "                $mediaToPlay,\n"
    "                $expectedPlayTime,\n"
    "            );\n"
    "        }\n\n"
    "        if (!$deferQueuePersistence) {",
    "        if (\n"
    "            $topOfHourOwnsBoundary\n"
    "            && !$this->applyTopOfHourTimingToQueueEntry(\n"
    "                $stationQueueEntry,\n"
    "                $mediaToPlay,\n"
    "                $expectedPlayTime,\n"
    "            )\n"
    "        ) {\n"
    "            return null;\n"
    "        }\n\n"
    "        $spm = $this->em->find(StationPlaylistMedia::class, $validTrack->spm_id);\n"
    "        if ($spm instanceof StationPlaylistMedia) {\n"
    "            $spm->played($expectedPlayTime->getTimestamp());\n"
    "            $this->em->persist($spm);\n"
    "        }\n\n"
    "        if (!$deferQueuePersistence) {",
)

# Both request paths must stop rather than knowingly create a track that needs a cut.
replace_all(
    queue_builder,
    "        $this->applyTopOfHourTimingToQueueEntry(\n"
    "            $stationQueueEntry,\n"
    "            $request->track,\n"
    "            $expectedPlayTime,\n"
    "        );",
    "        if (!$this->applyTopOfHourTimingToQueueEntry(\n"
    "            $stationQueueEntry,\n"
    "            $request->track,\n"
    "            $expectedPlayTime,\n"
    "        )) {\n"
    "            return null;\n"
    "        }",
    expected=2,
)

if "stretchCalculator" in read(queue_builder):
    raise RuntimeError("QueueBuilder still references the old stretchCalculator dependency")


# ---------------------------------------------------------------------------
# AI DJ: decouple live talk breaks from the 24-hour Linear Log builder.
# ---------------------------------------------------------------------------
write(
    "backend/src/Sync/Task/AiDjRealtimeTask.php",
    r'''<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AiDjQueueListener;
use App\Utilities\Time;

/**
 * Gives AI DJ a real-time scheduling heartbeat independent of long-range queue planning.
 */
final class AiDjRealtimeTask extends AbstractTask
{
    public function __construct(
        private readonly AiDjQueueListener $aiDjQueueListener,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$station->supportsAutoDjQueue()) {
                continue;
            }

            $now = Time::nowUtc()->toDateTimeImmutable();
            $this->aiDjQueueListener->onBuildQueue(
                new BuildQueue($station, $now, $now)
            );
        }
    }
}
'''
)

ai_dj = "backend/src/Radio/AutoDJ/AiDjQueueListener.php"
replace_once(
    ai_dj,
    "    private const int COMBO_PROBABILITY_PCT = 50;",
    "    private const int COMBO_PROBABILITY_PCT = 50;\n\n"
    "    /** Ignore long-range Linear Log BuildQueue events; AiDjRealtimeTask owns live cadence. */\n"
    "    private const int REALTIME_EVENT_MAX_LEAD_SECONDS = 30;",
)
replace_once(
    ai_dj,
    "        if ($event->isInterrupting()) {\n"
    "            $this->logger->debug('AI DJ: Skipped - event is interrupting.');\n"
    "            return;\n"
    "        }\n\n"
    "        // Skip if another listener (e.g. TopOfHourIdScheduler) already queued a song",
    "        if ($event->isInterrupting()) {\n"
    "            $this->logger->debug('AI DJ: Skipped - event is interrupting.');\n"
    "            return;\n"
    "        }\n\n"
    "        $eventLeadSeconds = $event->getExpectedPlayTime()->getTimestamp() - time();\n"
    "        if ($eventLeadSeconds > self::REALTIME_EVENT_MAX_LEAD_SECONDS) {\n"
    "            $this->logger->debug('AI DJ: Skipped - long-range queue planning event.', [\n"
    "                'lead_seconds' => $eventLeadSeconds,\n"
    "            ]);\n"
    "            return;\n"
    "        }\n\n"
    "        // Skip if another listener (e.g. TopOfHourIdScheduler) already queued a song",
)

# Register the real-time heartbeat.
events = "backend/config/events.php"
replace_once(
    events,
    "                App\\Sync\\Task\\QueueInterruptingTracks::class,\n"
    "                App\\Sync\\Task\\ReactivateStreamerTask::class,",
    "                App\\Sync\\Task\\QueueInterruptingTracks::class,\n"
    "                App\\Sync\\Task\\AiDjRealtimeTask::class,\n"
    "                App\\Sync\\Task\\ReactivateStreamerTask::class,",
)


# ---------------------------------------------------------------------------
# Upcoming queue report: chronological display only; do not change runtime ownership.
# ---------------------------------------------------------------------------
queue_controller = "backend/src/Controller/Api/Stations/QueueController.php"
replace_once(
    queue_controller,
    "        $qb = $this->queueRepo->getUnplayedBaseQuery($station);",
    "        // The runtime repository prioritizes already-sent rows for delivery semantics.\n"
    "        // The report must instead show one chronological listener-facing timeline.\n"
    "        $qb = $this->queueRepo->getUnplayedBaseQuery($station)\n"
    "            ->orderBy('sq.timestamp_played', 'ASC')\n"
    "            ->addOrderBy('sq.timestamp_cued', 'ASC')\n"
    "            ->addOrderBy('sq.id', 'ASC');",
)


# ---------------------------------------------------------------------------
# TOH playback history: force actual track-start feedback, then de-duplicate it.
# ---------------------------------------------------------------------------
liq = "util/docker/stations/liquidsoap/azuracast.liq"
old_feedback = r'''# Send metadata changes back to AzuraCast
def azuracast.send_feedback(m) =
    if (m["is_error_file"] != "true") then
        if (m["title"] != azuracast.last_title() or m["artist"] != azuracast.last_artist()) then
            azuracast.last_title := m["title"]
            azuracast.last_artist := m["artist"]

            # Only send some metadata to AzuraCast
            def fl(k, _) =
                tags = ["song_id", "media_id", "playlist_id", "sq_id", "artist", "title"]
                list.mem(k, tags)
            end

            feedback_meta = list.assoc.filter((fl), metadata.cover.remove(m))

            j = json()
            for item = list.iterator(feedback_meta) do
                let (tag, value) = item
                j.add(tag, value)
            end

            _ = azuracast.api_call(
                "feedback",
                json.stringify(compact=true, j)
            )
        end
    end
end
'''
new_feedback = r'''# Build the exact metadata payload sent back to AzuraCast.
def azuracast.feedback_payload(m) =
    def fl(k, _) =
        tags = [
            "song_id", "media_id", "playlist_id", "sq_id", "artist", "title",
            "azuracast_legal_id", "azuracast_top_of_hour_id", "azuracast_top_of_hour_fallback"
        ]
        list.mem(k, tags)
    end

    feedback_meta = list.assoc.filter((fl), metadata.cover.remove(m))
    j = json()
    for item = list.iterator(feedback_meta) do
        let (tag, value) = item
        j.add(tag, value)
    end

    json.stringify(compact=true, j)
end

# Send metadata changes back to AzuraCast.
def azuracast.send_feedback(m) =
    if (m["is_error_file"] != "true") then
        if (m["title"] != azuracast.last_title() or m["artist"] != azuracast.last_artist()) then
            azuracast.last_title := m["title"]
            azuracast.last_artist := m["artist"]

            _ = azuracast.api_call(
                "feedback",
                azuracast.feedback_payload(m)
            )
        end
    end
end

# Dedicated TOH playback uses an explicit track-start callback. It must not be
# suppressed by title/artist de-duplication because the history row is evidence
# that the legal ID actually started on air for this boundary.
def azuracast.send_feedback_force(m) =
    if (m["is_error_file"] != "true") then
        azuracast.last_title := m["title"]
        azuracast.last_artist := m["artist"]

        _ = azuracast.api_call(
            "feedback",
            azuracast.feedback_payload(m)
        )
    end
end
'''
replace_once(liq, old_feedback, new_feedback)

toh_writer = "backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php"
replace_once(
    toh_writer,
    "            source.methods(top_of_hour_queue).on_track(synchronous=true, top_of_hour_mark_legal_id)\n"
    "            source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)",
    "            source.methods(top_of_hour_queue).on_track(synchronous=true, top_of_hour_mark_legal_id)\n"
    "            source.methods(top_of_hour_queue).on_track(synchronous=false, azuracast.send_feedback_force)\n"
    "            source.methods(radio).on_track(synchronous=true, top_of_hour_mark_legal_id)",
)

feedback = "backend/src/Radio/Backend/Liquidsoap/Command/FeedbackCommand.php"
replace_once(
    feedback,
    "        $historyRow = $this->getSongHistory($station, $payload);",
    "        if ($this->isDuplicateTopOfHourFeedback($station, $payload)) {\n"
    "            return true;\n"
    "        }\n\n"
    "        $historyRow = $this->getSongHistory($station, $payload);",
)
replace_once(
    feedback,
    "    private function resolveTopOfHourQueueRow(\n",
    r'''    private function isDuplicateTopOfHourFeedback(Station $station, array $payload): bool
    {
        if (empty($payload['azuracast_top_of_hour_id'])) {
            return false;
        }

        if (!empty($payload['sq_id'])) {
            $queueRow = $this->em->find(StationQueue::class, $payload['sq_id']);
            if ($queueRow instanceof StationQueue && $queueRow->is_played) {
                return true;
            }
        }

        $now = Time::nowUtc()->toDateTimeImmutable();
        $targetTop = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt(
            $station,
            $now,
        );
        $windowStart = $targetTop->modify(
            '-' . $this->hourBoundaryPlanner->getIdWindowLeadSeconds($station) . ' seconds'
        );
        $windowEnd = $targetTop->modify('+30 seconds');

        $count = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(sq.id)
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station
                AND sq.top_of_hour_legal_id = 1
                AND sq.is_played = 1
                AND sq.timestamp_played >= :windowStart
                AND sq.timestamp_played <= :windowEnd
            DQL
        )->setParameter('station', $station)
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd)
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function resolveTopOfHourQueueRow(
''',
)


# ---------------------------------------------------------------------------
# PR #96 hardening: no false strict-start success and no unconditional :00 silence.
# ---------------------------------------------------------------------------
repository = "backend/src/Entity/Repository/StationQueueRepository.php"
replace_once(
    repository,
    "            ->andWhere('sq.is_played = 1')\n"
    "            ->andWhere('sq.timestamp_played >= :since')",
    "            ->andWhere('sq.is_played = 1')\n"
    "            ->andWhere('sq.sent_to_autodj = 1')\n"
    "            ->andWhere('sq.timestamp_played >= :since')",
)

interrupt_task = "backend/src/Sync/Task/QueueInterruptingTracks.php"
replace_once(
    interrupt_task,
    "            $response = $backend->enqueue($station, $queueName, $track);\n"
    "            $this->logger->debug('AutoDJ request response', ['response' => $response]);",
    "            $response = $backend->enqueue($station, $queueName, $track);\n"
    "            $this->logger->debug('AutoDJ request response', ['response' => $response]);\n\n"
    "            // Queue::getInterruptingQueue marks a row selected before delivery.\n"
    "            // sent_to_autodj is the durable success bit used by strict-start catch-up.\n"
    "            $sq->sent_to_autodj = true;\n"
    "            $this->em->persist($sq);\n"
    "            $this->em->flush();",
)

replace_once(
    toh_writer,
    "              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then\n"
    "                # If a song crossed :00, keep the next normal track held briefly\n"
    "                # for the just-started hour until its legal ID is observed.\n"
    "                boundary = now_seconds - seconds_in_hour\n"
    "                top_of_hour_last_served_boundary() != boundary",
    "              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then\n"
    "                # After :00, hold only when an ID is actually pending/claimed.\n"
    "                # A missed enqueue must fail open to programming, not 30s of silence.\n"
    "                boundary = now_seconds - seconds_in_hour\n"
    "                boundary_has_delivery =\n"
    "                  top_of_hour_claimed_boundary() == boundary or\n"
    "                  top_of_hour_queue.length() > 0 or\n"
    "                  top_of_hour_queue.is_ready()\n"
    "                boundary_has_delivery and top_of_hour_last_served_boundary() != boundary",
)


# ---------------------------------------------------------------------------
# Regression tests.
# ---------------------------------------------------------------------------
write(
    "tests/Unit/TopOfHourSmartBacktimingTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourSmartBacktimingTest extends TestCase
{
    public function testQueueBuilderPlansASequenceAndRefusesRoutineTohFade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('TopOfHourSequencePlanner $topOfHourSequencePlanner', $source);
        self::assertStringContainsString('rankFirstCandidates(', $source);
        self::assertStringContainsString(
            'no clean music sequence can reach the TOH handoff; refusing a routine cut/fade.',
            $source,
        );

        $timingStart = strpos($source, 'private function applyTopOfHourTimingToQueueEntry');
        self::assertNotFalse($timingStart);
        $timingBlock = substr($source, $timingStart, 4200);
        self::assertStringNotContainsString('$queueEntry->top_of_hour_pre_id_fade = true;', $timingBlock);
        self::assertStringNotContainsString('$queueEntry->hour_boundary_enforce_cap = true;', $timingBlock);
    }

    public function testRequestsCannotCreateAnOrphanedEndOfHourOverrun(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('canFinishAtHandoff(', $source);
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
'''
)

write(
    "tests/Unit/TopOfHourSequencePlannerTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;
use App\Radio\AutoDJ\TopOfHourSequencePlanner;
use PHPUnit\Framework\TestCase;

final class TopOfHourSequencePlannerTest extends TestCase
{
    private TopOfHourSequencePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new TopOfHourSequencePlanner(new ClockWheelStretchCalculator());
    }

    public function testOrphanFiftySevenSecondGapIsNotThePreferredPlan(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 333.0, 'order' => 0],
                ['key' => 2, 'length' => 205.0, 'order' => 1],
                ['key' => 3, 'length' => 185.0, 'order' => 2],
            ],
            390.0,
            2.0,
        );

        self::assertNotEmpty($ranked);
        self::assertSame(2, $ranked[0]['key']);
        self::assertGreaterThanOrEqual(2, $ranked[0]['tracks']);
        self::assertNotSame(1, $ranked[0]['key']);
    }

    public function testFinalStretchAccountsForCrossfadeOverlap(): void
    {
        $ratio = $this->planner->getStretchRatioToFill(180.0, 187.0, 2.0);
        self::assertSame(1.05, $ratio);
    }

    public function testImpossibleFiftySevenSecondSlotStartsNoFullSong(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 180.0],
                ['key' => 2, 'length' => 240.0],
            ],
            57.0,
            2.0,
        );

        self::assertSame([], $ranked);
    }
}
'''
)

write(
    "tests/Unit/AiDjRealtimeTaskTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AiDjRealtimeTaskTest extends TestCase
{
    public function testAiDjUsesRealtimeHeartbeatInsteadOfLinearLogProjection(): void
    {
        $listener = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/AiDjQueueListener.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/Task/AiDjRealtimeTask.php'
        );
        $events = file_get_contents(
            dirname(__DIR__, 2) . '/backend/config/events.php'
        );

        self::assertIsString($listener);
        self::assertIsString($task);
        self::assertIsString($events);
        self::assertStringContainsString('REALTIME_EVENT_MAX_LEAD_SECONDS = 30', $listener);
        self::assertStringContainsString('long-range queue planning event', $listener);
        self::assertStringContainsString('return self::SCHEDULE_EVERY_MINUTE;', $task);
        self::assertStringContainsString('new BuildQueue($station, $now, $now)', $task);
        self::assertStringContainsString('App\\Sync\\Task\\AiDjRealtimeTask::class', $events);
    }
}
'''
)

write(
    "tests/Unit/TopOfHourFeedbackTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourFeedbackTest extends TestCase
{
    public function testDedicatedTohTrackStartForcesHistoryFeedbackExactlyOnce(): void
    {
        $liq = file_get_contents(
            dirname(__DIR__, 2) . '/util/docker/stations/liquidsoap/azuracast.liq'
        );
        $writer = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );
        $feedback = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/Command/FeedbackCommand.php'
        );

        self::assertIsString($liq);
        self::assertIsString($writer);
        self::assertIsString($feedback);
        self::assertStringContainsString('def azuracast.send_feedback_force(m) =', $liq);
        self::assertStringContainsString('"azuracast_top_of_hour_id"', $liq);
        self::assertStringContainsString(
            'source.methods(top_of_hour_queue).on_track(synchronous=false, azuracast.send_feedback_force)',
            $writer,
        );
        self::assertStringContainsString('isDuplicateTopOfHourFeedback(', $feedback);
        self::assertStringContainsString('sq.top_of_hour_legal_id = 1', $feedback);
    }
}
'''
)

write(
    "tests/Unit/QueueTimelineOrderTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueueTimelineOrderTest extends TestCase
{
    public function testUpcomingQueueReportOverridesRuntimePriorityWithChronologicalOrder(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Controller/Api/Stations/QueueController.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString("->orderBy('sq.timestamp_played', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.timestamp_cued', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.id', 'ASC')", $source);
    }
}
'''
)

# Strengthen the existing hold and strict-start source contracts.
replace_once(
    "tests/Unit/TopOfHourPreBoundaryHoldTest.php",
    "        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);\n"
    "        self::assertStringContainsString('track_sensitive=true,', $source);",
    "        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);\n"
    "        self::assertStringContainsString('boundary_has_delivery =', $source);\n"
    "        self::assertStringContainsString('top_of_hour_queue.length() > 0', $source);\n"
    "        self::assertStringContainsString('top_of_hour_queue.is_ready()', $source);\n"
    "        self::assertStringContainsString('track_sensitive=true,', $source);",
)
replace_once(
    "tests/Unit/StrictStartGraceTest.php",
    "        self::assertStringContainsString('sq.is_played = 1', $repository);\n"
    "        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);",
    "        self::assertStringContainsString('sq.is_played = 1', $repository);\n"
    "        self::assertStringContainsString('sq.sent_to_autodj = 1', $repository);\n"
    "        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);",
)
replace_once(
    "tests/Unit/StrictStartGraceTest.php",
    "        self::assertStringContainsString(\n"
    "            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',\n"
    "            $task,\n"
    "        );",
    "        self::assertStringContainsString(\n"
    "            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',\n"
    "            $task,\n"
    "        );\n"
    "        self::assertStringContainsString('$sq->sent_to_autodj = true;', $task);",
)

# Remove temporary patch machinery from the resulting production commit.
for temporary in [
    ROOT / ".github/scripts/apply_toh_rescue.py",
    ROOT / ".github/workflows/apply_toh_rescue.yml",
]:
    if temporary.exists():
        temporary.unlink()
