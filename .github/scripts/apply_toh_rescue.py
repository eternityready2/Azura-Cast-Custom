from __future__ import annotations

from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def write(path: str, content: str) -> None:
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content)


def git_show(path: str) -> str:
    return subprocess.check_output(
        ["git", "show", f"origin/dev:{path}"],
        cwd=ROOT,
        text=True,
    )


def replace_once(content: str, old: str, new: str, label: str) -> str:
    count = content.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected one exact match, found {count}")
    return content.replace(old, new, 1)


def replace_all(content: str, old: str, new: str, expected: int, label: str) -> str:
    count = content.count(old)
    if count != expected:
        raise RuntimeError(f"{label}: expected {expected} exact matches, found {count}")
    return content.replace(old, new)


def regex_once(content: str, pattern: str, replacement: str, label: str) -> str:
    updated, count = re.subn(pattern, replacement, content, count=1, flags=re.S)
    if count != 1:
        raise RuntimeError(f"{label}: expected one regex match, found {count}")
    return updated


# ---------------------------------------------------------------------------
# TOH sequence planner: normal music is backtimed as a sequence. A full song is
# never deliberately started when it cannot finish cleanly before the ID.
# ---------------------------------------------------------------------------
write(
    "backend/src/Radio/AutoDJ/TopOfHourSequencePlanner.php",
    r'''<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;

/**
 * Scores the first record in an end-of-hour sequence.
 *
 * Intermediate records run naturally. Only the final record may use the
 * existing bounded, pitch-preserving +/-5% stretch/squeeze engine. Routine
 * cue-out/fade truncation is intentionally not part of this planner.
 */
final class TopOfHourSequencePlanner
{
    public const float NATURAL_TOLERANCE_SECONDS = 2.0;

    /** Current record plus at most three following records. */
    private const int MAX_SEQUENCE_TRACKS = 4;

    public function __construct(
        private readonly ClockWheelStretchCalculator $stretchCalculator,
    ) {
    }

    /**
     * @param array<int, array{key:int, length:float, order?:int}> $candidates
     * @param float[] $futureLengths
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
        array $futureLengths,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): array {
        if ($availableSeconds <= 0.0 || $candidates === []) {
            return [];
        }

        $normalized = [];
        $pool = [];

        foreach ($futureLengths as $length) {
            $length = (float)$length;
            if ($length > 0.0) {
                $pool[(int)round($length * 10)] = $length;
            }
        }

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
            $pool[(int)round($length * 10)] = $length;
        }

        if ($normalized === [] || $pool === []) {
            return [];
        }

        ksort($pool, SORT_NUMERIC);
        $pool = array_values($pool);
        $memo = [];
        $ranked = [];

        foreach ($normalized as $candidate) {
            $score = $this->scoreFirstCandidate(
                $candidate['length'],
                $availableSeconds,
                $crossfadeSeconds,
                $pool,
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

    /** @param float[] $futureLengths */
    public function canStartTrack(
        float $sourceSeconds,
        array $futureLengths,
        float $availableSeconds,
        float $crossfadeSeconds,
    ): bool {
        return $this->rankFirstCandidates(
            [['key' => 0, 'length' => $sourceSeconds, 'order' => 0]],
            $futureLengths,
            $availableSeconds,
            $crossfadeSeconds,
        ) !== [];
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

    /**
     * @param float[] $futureLengths
     * @param array<string, array{gap:float, stretch_penalty:float, tracks:int}|null> $memo
     * @return array{gap:float, stretch_penalty:float, tracks:int, first_ratio:float|null}|null
     */
    private function scoreFirstCandidate(
        float $sourceSeconds,
        float $availableSeconds,
        float $crossfadeSeconds,
        array $futureLengths,
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

        $completion = $this->findBestCompletion(
            $availableSeconds - $naturalAirtime,
            self::MAX_SEQUENCE_TRACKS - 1,
            $crossfadeSeconds,
            $futureLengths,
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
     * @param float[] $futureLengths
     * @param array<string, array{gap:float, stretch_penalty:float, tracks:int}|null> $memo
     * @return array{gap:float, stretch_penalty:float, tracks:int}|null
     */
    private function findBestCompletion(
        float $remainingSeconds,
        int $tracksLeft,
        float $crossfadeSeconds,
        array $futureLengths,
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

        foreach ($futureLengths as $sourceSeconds) {
            $finalFit = $this->getFinalFit(
                $sourceSeconds,
                $remainingSeconds,
                $crossfadeSeconds,
            );
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
                $futureLengths,
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

    /** @return array{gap:float, stretch_penalty:float, ratio:float|null}|null */
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


# QueueBuilder is rebuilt from the known-good post-PR96 dev version, then the
# failed six-minute/single-track strategy is replaced with full-lookahead
# sequence planning before any queue/rotation side effects are persisted.
queue_path = "backend/src/Radio/AutoDJ/QueueBuilder.php"
queue = git_show(queue_path)
queue = replace_once(
    queue,
    "use App\\Entity\\Enums\\PlaylistTypes;",
    "use App\\Entity\\Enums\\PlaylistTypes;\nuse App\\Entity\\Enums\\StationMediaTypes;",
    "QueueBuilder import",
)
queue = replace_once(
    queue,
    "    /** Re-rank normal music by exact boundary fit during the final six minutes. */\n"
    "    private const int TOH_PRECISION_BACKTIME_SECONDS = 360;\n\n"
    "    /** Below this point the Liquidsoap pre-boundary hold owns the handoff. */\n"
    "    private const int TOH_MIN_TIMED_TRACK_SECONDS = 15;",
    "    /** Below this point the Liquidsoap pre-boundary hold owns the handoff. */\n"
    "    private const int TOH_MIN_TIMED_TRACK_SECONDS = 15;\n\n"
    "    private const int TOH_FUTURE_POOL_CACHE_SECONDS = 300;",
    "QueueBuilder constants",
)
queue = replace_once(
    queue,
    "        private readonly HourBoundaryPlanner $hourBoundaryPlanner,\n"
    "        private readonly ClockWheel\\ClockWheelStretchCalculator $stretchCalculator,",
    "        private readonly HourBoundaryPlanner $hourBoundaryPlanner,\n"
    "        private readonly ClockWheel\\ClockWheelStretchCalculator $stretchCalculator,\n"
    "        private readonly TopOfHourSequencePlanner $topOfHourSequencePlanner,",
    "QueueBuilder constructor",
)

new_boundary_methods = r'''    private function applyHourBoundarySelection(
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
                'Hour boundary: no full music item will be started inside the protected handoff sliver.',
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

            if (
                StationMediaTypes::isStationId($candidate->type)
                || 'music' !== ($candidate->type ?? 'music')
            ) {
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
            $this->getTopOfHourFutureMusicLengths($playlist->station, $expectedPlayTime),
            $availableSeconds,
            $playlist->station->backend_config->getCrossfadeDuration(),
        );

        if ($ranked === []) {
            $this->logger->warning(
                'Hour boundary: no clean music sequence can reach the TOH handoff; routine cut/fade is refused.',
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
                'Hour boundary: duplicate prevention rejected every clean backtiming candidate.',
                ['playlist_id' => $playlist->id]
            );
            return null;
        }

        $chosenPlan = null;
        foreach ($ranked as $row) {
            $candidate = $queueByKey[$row['key']];
            if ($candidate->spm_id === $chosen->spm_id && $candidate->media_id === $chosen->media_id) {
                $chosenPlan = $row;
                break;
            }
        }

        $this->logger->info('Hour boundary: selected clean multi-song backtiming.', [
            'playlist_id' => $playlist->id,
            'target_seconds' => $availableSeconds,
            'media_id' => $chosen->media_id,
            'planned_tracks' => $chosenPlan['tracks'] ?? null,
            'planned_gap_seconds' => $chosenPlan['gap'] ?? null,
            'stretch_penalty' => $chosenPlan['stretch_penalty'] ?? null,
        ]);

        return $chosen;
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

        if (null === $availableSeconds) {
            return true;
        }

        if ($availableSeconds < self::TOH_MIN_TIMED_TRACK_SECONDS) {
            return false;
        }

        return $this->topOfHourSequencePlanner->canStartTrack(
            $media->getCalculatedLength(),
            $this->getTopOfHourFutureMusicLengths($station, $expectedPlayTime),
            $availableSeconds,
            $station->backend_config->getCrossfadeDuration(),
        );
    }

    private function applyTopOfHourTimingToQueueEntry(
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

        $crossfadeSeconds = $queueEntry->station->backend_config->getCrossfadeDuration();
        $mediaLength = $media->getCalculatedLength();
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
            'Hour boundary: refusing to turn a normal music track into a routine TOH cut/fade.',
            [
                'media_id' => $media->id,
                'media_length' => $mediaLength,
                'available_seconds' => $availableSeconds,
            ]
        );

        return false;
    }

    /** @return float[] */
    private function getTopOfHourFutureMusicLengths(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        $localHour = $expectedPlayTime
            ->setTimezone($station->getTimezoneObject())
            ->format('YmdH');
        $cacheKey = 'toh_future_music_pool_v2.' . $station->id . '.' . $localHour;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_numeric'));
        }

        $lengths = [];

        foreach ($station->playlists as $candidatePlaylist) {
            if (!$candidatePlaylist instanceof StationPlaylist) {
                continue;
            }
            if (
                !$candidatePlaylist->is_enabled
                || $candidatePlaylist->is_jingle
                || PlaylistSources::Songs !== $candidatePlaylist->source
            ) {
                continue;
            }
            if (!$this->scheduler->shouldPlaylistPlayNow($candidatePlaylist, $expectedPlayTime)) {
                continue;
            }

            try {
                $candidateQueue = $this->preparePlaylistQueue(
                    $candidatePlaylist,
                    $this->spmRepo->getQueue($candidatePlaylist),
                    $expectedPlayTime,
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Hour boundary: future music pool lookup failed for playlist.', [
                    'playlist_id' => $candidatePlaylist->id,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($candidateQueue as $queueItem) {
                $candidate = $this->em->find(StationMedia::class, $queueItem->media_id);
                if (!$candidate instanceof StationMedia) {
                    continue;
                }
                if (
                    StationMediaTypes::isStationId($candidate->type)
                    || 'music' !== ($candidate->type ?? 'music')
                ) {
                    continue;
                }

                $length = $candidate->getCalculatedLength();
                if ($length <= 0.0 || $length > 900.0) {
                    continue;
                }

                $lengths[(int)round($length * 10)] = $length;
            }
        }

        ksort($lengths, SORT_NUMERIC);
        $result = array_values($lengths);
        $this->cache->set($cacheKey, $result, self::TOH_FUTURE_POOL_CACHE_SECONDS);
        return $result;
    }

'''
queue = regex_once(
    queue,
    r"    private function applyHourBoundarySelection\(.*?\n    private function filterQueueByRotationGoal\(",
    new_boundary_methods + "    private function filterQueueByRotationGoal(",
    "QueueBuilder boundary methods",
)

# Do not advance playlist-media rotation until the chosen record survives TOH timing.
early_spm = (
    "        $spm = $this->em->find(StationPlaylistMedia::class, $validTrack->spm_id);\n"
    "        if ($spm instanceof StationPlaylistMedia) {\n"
    "            $spm->played($expectedPlayTime->getTimestamp());\n"
    "            $this->em->persist($spm);\n"
    "        }\n\n"
)
queue = replace_once(queue, early_spm, "", "QueueBuilder early SPM side effect")
queue = replace_once(
    queue,
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
    "QueueBuilder TOH timing side effect order",
)
queue = replace_all(
    queue,
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
    2,
    "QueueBuilder request timing",
)

if "TOH_PRECISION_BACKTIME_SECONDS" in queue:
    raise RuntimeError("QueueBuilder still contains the failed six-minute precision window")
if "$queueEntry->top_of_hour_pre_id_fade = true;" in queue:
    raise RuntimeError("QueueBuilder still plans routine TOH fade annotations")
write(queue_path, queue)

# The lower-priority post-selection guard from the first rescue draft is removed:
# selection must be correct before QueueBuilder persists/advances anything.
music_guard = ROOT / "backend/src/Radio/AutoDJ/TopOfHourMusicGuard.php"
if music_guard.exists():
    music_guard.unlink()


# ---------------------------------------------------------------------------
# AI DJ cadence: live decisions happen once per actually-started on-air item,
# independent of the 24-hour Linear Log. This preserves talk_frequency as an
# on-air-item probability instead of rolling it repeatedly every few seconds.
# ---------------------------------------------------------------------------
write(
    "backend/src/Radio/AutoDJ/AiDjRealtimeQueueListener.php",
    r'''<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Psr\SimpleCache\CacheInterface;

/**
 * Runs one AI DJ decision per actual on-air item, using real wall-clock time.
 */
final class AiDjRealtimeQueueListener
{
    private const int SEEN_ITEM_TTL_SECONDS = 21600;

    public function __construct(
        private readonly AiDjQueueListener $delegate,
        private readonly CacheInterface $cache,
    ) {
    }

    public function run(Station $station): void
    {
        $current = $station->current_song;
        if (null === $current || null === $current->timestamp_start) {
            return;
        }

        $fingerprint = $current->song_id . ':' . $current->timestamp_start->getTimestamp();
        $cacheKey = 'ai_dj_realtime_item_' . $station->id;

        if ($this->cache->get($cacheKey) === $fingerprint) {
            return;
        }

        // Claim this item before generation so parallel Now Playing workers cannot
        // roll talk_frequency twice for the same song/program/liner.
        $this->cache->set($cacheKey, $fingerprint, self::SEEN_ITEM_TTL_SECONDS);

        $now = Time::nowUtc()->toDateTimeImmutable();
        $this->delegate->onBuildQueue(
            new BuildQueue($station, $now, $now, $current->song_id, false)
        );
    }
}
'''
)

# Keep the existing rescue change that invokes the realtime wrapper from the
# Now Playing worker before ordinary queue maintenance.
build_queue_task = read("backend/src/Sync/NowPlaying/Task/BuildQueueTask.php")
if "AiDjRealtimeQueueListener" not in build_queue_task:
    raise RuntimeError("BuildQueueTask is not wired to the realtime AI DJ wrapper")

# Event subscribers are rebuilt from dev, except projected BuildQueue events no
# longer call AiDjQueueListener. The realtime wrapper above is its sole cadence owner.
events_path = "backend/config/events.php"
events = git_show(events_path)
events = replace_once(
    events,
    "            App\\Radio\\AutoDJ\\AiDjQueueListener::class,\n",
    "",
    "events AI DJ projected subscriber",
)
write(events_path, events)


# ---------------------------------------------------------------------------
# Upcoming queue report: presentation is chronological. Runtime queue priority
# remains untouched so dedicated TOH ownership/prefetch behavior is unchanged.
# ---------------------------------------------------------------------------
queue_controller_path = "backend/src/Controller/Api/Stations/QueueController.php"
queue_controller = git_show(queue_controller_path)
queue_controller = replace_once(
    queue_controller,
    "        $qb = $this->queueRepo->getUnplayedBaseQuery($station);",
    "        $qb = $this->queueRepo->getUnplayedBaseQuery($station);\n\n"
    "        // Internal delivery ordering prioritizes rows already handed to Liquidsoap.\n"
    "        // The Upcoming Queue report is listener-facing and must be chronological.\n"
    "        $qb->resetDQLPart('orderBy')\n"
    "            ->orderBy('sq.timestamp_played', 'ASC')\n"
    "            ->addOrderBy('sq.timestamp_cued', 'ASC')\n"
    "            ->addOrderBy('sq.id', 'ASC');",
    "QueueController chronological sort",
)
write(queue_controller_path, queue_controller)


# ---------------------------------------------------------------------------
# Legal ID history: record the actual TOH track start. Do not recreate the old
# enqueue-time fake history. Generic feedback also carries TOH flags so duplicate
# callbacks can be made idempotent without changing normal-song semantics.
# ---------------------------------------------------------------------------
liq_path = "util/docker/stations/liquidsoap/azuracast.liq"
liq = read(liq_path)
liq = replace_once(
    liq,
    '                tags = ["song_id", "media_id", "playlist_id", "sq_id", "artist", "title"]',
    '                tags = ["song_id", "media_id", "playlist_id", "sq_id", "artist", "title", '
    '"azuracast_legal_id", "azuracast_top_of_hour_id", "azuracast_top_of_hour_fallback"]',
    "Liquidsoap feedback metadata flags",
)
write(liq_path, liq)

feedback_path = "backend/src/Radio/Backend/Liquidsoap/Command/FeedbackCommand.php"
feedback = git_show(feedback_path)
feedback = replace_once(
    feedback,
    "        $historyRow = $this->getSongHistory($station, $payload);",
    "        if ($this->isDuplicateTopOfHourFeedback($station, $payload)) {\n"
    "            return true;\n"
    "        }\n\n"
    "        $historyRow = $this->getSongHistory($station, $payload);",
    "FeedbackCommand idempotent TOH precheck",
)
feedback_helper = r'''    private function isDuplicateTopOfHourFeedback(Station $station, array $payload): bool
    {
        $isTopOfHour = !empty($payload['azuracast_top_of_hour_id'])
            || !empty($payload['azuracast_top_of_hour_fallback']);

        if (!empty($payload['sq_id'])) {
            $queueRow = $this->em->find(StationQueue::class, $payload['sq_id']);
            if (
                $queueRow instanceof StationQueue
                && $queueRow->is_played
                && ($queueRow->top_of_hour_legal_id || $isTopOfHour)
            ) {
                return true;
            }
        }

        if (!$isTopOfHour) {
            return false;
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

'''
feedback = replace_once(
    feedback,
    "    private function resolveTopOfHourQueueRow(\n",
    feedback_helper + "    private function resolveTopOfHourQueueRow(\n",
    "FeedbackCommand TOH duplicate helper",
)
write(feedback_path, feedback)

# Preserve the first rescue's actual-playback callback, then harden the post-:00
# hold so a missed ID fails open instead of producing 30 seconds of silence.
toh_writer_path = "backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php"
toh_writer = read(toh_writer_path)
if "def top_of_hour_send_feedback(metadata) =" not in toh_writer:
    raise RuntimeError("TopOfHourConfigWriter lost actual-track-start history feedback")
toh_writer = replace_once(
    toh_writer,
    '                "azuracast_top_of_hour_id", "azuracast_top_of_hour_fallback"',
    '                "azuracast_legal_id", "azuracast_top_of_hour_id", "azuracast_top_of_hour_fallback"',
    "TOH dedicated feedback tags",
)
toh_writer = replace_once(
    toh_writer,
    "              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then\n"
    "                # If a song crossed :00 unexpectedly, keep the next normal track\n"
    "                # held briefly until the just-started hour's legal ID is observed.\n"
    "                boundary = now_seconds - seconds_in_hour\n"
    "                top_of_hour_last_served_boundary() != boundary",
    "              elsif seconds_in_hour <= {$postBoundaryHoldSeconds} then\n"
    "                # After :00, hold only while this boundary actually has an ID\n"
    "                # claimed or waiting. A missed enqueue fails open to programming.\n"
    "                boundary = now_seconds - seconds_in_hour\n"
    "                boundary_has_delivery =\n"
    "                  top_of_hour_claimed_boundary() == boundary or\n"
    "                  top_of_hour_queue.length() > 0 or\n"
    "                  top_of_hour_queue.is_ready()\n"
    "                boundary_has_delivery and top_of_hour_last_served_boundary() != boundary",
    "TOH post-boundary fail-open hold",
)
write(toh_writer_path, toh_writer)


# ---------------------------------------------------------------------------
# PR #96 strict-start review hardening: queue construction is not delivery.
# A failed/busy interrupting enqueue must remain retryable during the grace window.
# ---------------------------------------------------------------------------
repo_path = "backend/src/Entity/Repository/StationQueueRepository.php"
repo = git_show(repo_path)
repo = replace_once(
    repo,
    "            ->andWhere('sq.is_played = 1')\n"
    "            ->andWhere('sq.timestamp_played >= :since')",
    "            ->andWhere('sq.is_played = 1')\n"
    "            ->andWhere('sq.sent_to_autodj = 1')\n"
    "            ->andWhere('sq.timestamp_played >= :since')",
    "strict-start successful-delivery guard",
)
write(repo_path, repo)

interrupt_path = "backend/src/Sync/Task/QueueInterruptingTracks.php"
interrupt = git_show(interrupt_path)
interrupt = replace_once(
    interrupt,
    "            if (!$isEmpty) {\n"
    "                $this->logger->info('Skipping enqueue; target queue is not empty.', [\n"
    "                    'queue' => $queueName->value,\n"
    "                ]);\n"
    "                continue;\n"
    "            }",
    "            if (!$isEmpty) {\n"
    "                $this->logger->info('Skipping enqueue; target queue is not empty.', [\n"
    "                    'queue' => $queueName->value,\n"
    "                ]);\n\n"
    "                if (LiquidsoapQueues::Interrupting === $queueName) {\n"
    "                    $this->discardUndeliveredInterruptingRow($sq);\n"
    "                }\n"
    "                continue;\n"
    "            }",
    "interrupting busy queue cleanup",
)
interrupt = replace_once(
    interrupt,
    "            $this->logger->debug('Submitting request to AutoDJ.', [\n"
    "                'track' => $track,\n"
    "                'queue' => $queueName->value,\n"
    "            ]);\n"
    "            $response = $backend->enqueue($station, $queueName, $track);\n"
    "            $this->logger->debug('AutoDJ request response', ['response' => $response]);",
    "            // Annotation marks normal rows as sent before the external enqueue.\n"
    "            // Reset that optimistic bit first; only a successful Liquidsoap\n"
    "            // enqueue may satisfy strict-start one-shot protection.\n"
    "            $sq->sent_to_autodj = false;\n"
    "            $this->em->persist($sq);\n"
    "            $this->em->flush();\n\n"
    "            try {\n"
    "                $this->logger->debug('Submitting request to AutoDJ.', [\n"
    "                    'track' => $track,\n"
    "                    'queue' => $queueName->value,\n"
    "                ]);\n"
    "                $response = $backend->enqueue($station, $queueName, $track);\n"
    "                $this->logger->debug('AutoDJ request response', ['response' => $response]);\n\n"
    "                $sq->sent_to_autodj = true;\n"
    "                $this->em->persist($sq);\n"
    "                $this->em->flush();\n"
    "            } catch (\\Throwable $e) {\n"
    "                $this->logger->error('Interrupting enqueue failed; row remains retryable.', [\n"
    "                    'queue_id' => $sq->id,\n"
    "                    'exception' => $e->getMessage(),\n"
    "                ]);\n"
    "                $this->discardUndeliveredInterruptingRow($sq);\n"
    "            }",
    "interrupting successful delivery bit",
)
interrupt = replace_once(
    interrupt,
    "    private function enqueueTopOfHour(\n",
    r'''    private function discardUndeliveredInterruptingRow(StationQueue $sq): void
    {
        try {
            $this->em->remove($sq);
            $this->em->flush();
        } catch (\Throwable $e) {
            // The durable success bit was cleared before delivery. Even if cleanup
            // itself fails, strict-start catch-up will not treat this row as served.
            $sq->sent_to_autodj = false;
            $this->em->persist($sq);
            $this->em->flush();
            $this->logger->warning('Could not remove undelivered interrupting row.', [
                'queue_id' => $sq->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function enqueueTopOfHour(
''',
    "interrupting discard helper",
)
write(interrupt_path, interrupt)


# ---------------------------------------------------------------------------
# Regression/source-contract tests.
# ---------------------------------------------------------------------------
write(
    "tests/Unit/TopOfHourSmartBacktimingTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourSmartBacktimingTest extends TestCase
{
    public function testQueueBuilderUsesFullLookaheadSequencePlanningAndNoRoutineFade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('TopOfHourSequencePlanner $topOfHourSequencePlanner', $source);
        self::assertStringContainsString('rankFirstCandidates(', $source);
        self::assertStringContainsString('getTopOfHourFutureMusicLengths(', $source);
        self::assertStringNotContainsString('TOH_PRECISION_BACKTIME_SECONDS', $source);
        self::assertStringNotContainsString('$queueEntry->top_of_hour_pre_id_fade = true;', $source);
        self::assertStringContainsString('routine cut/fade is refused', $source);
    }

    public function testRequestsDoNotCreateAnUnfillableLateHourOverrun(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('private function requestCanFitTopOfHourBoundary(', $source);
        self::assertStringContainsString('canStartTrack(', $source);
        self::assertStringContainsString(
            'Listener request deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
        self::assertStringContainsString(
            'Request playlist item deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
    }

    public function testStretchMetadataHandoffRemainsSynchronous(): void
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

    public function testLongTrackThatStrandsFiftySevenSecondsIsRejected(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 333.0, 'order' => 0],
                ['key' => 2, 'length' => 205.0, 'order' => 1],
            ],
            [185.0, 205.0, 333.0],
            390.0,
            2.0,
        );

        self::assertNotEmpty($ranked);
        self::assertSame(2, $ranked[0]['key']);
        self::assertNotContains(1, array_column($ranked, 'key'));
    }

    public function testImpossibleFiftySevenSecondSlotStartsNoFullSong(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 180.0],
                ['key' => 2, 'length' => 240.0],
            ],
            [180.0, 240.0],
            57.0,
            2.0,
        );

        self::assertSame([], $ranked);
    }

    public function testFinalStretchAccountsForCrossfadeOverlap(): void
    {
        self::assertSame(1.05, $this->planner->getStretchRatioToFill(180.0, 187.0, 2.0));
    }
}
'''
)

write(
    "tests/Unit/AiDjRealtimeQueueListenerTest.php",
    r'''<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AiDjRealtimeQueueListenerTest extends TestCase
{
    public function testAiDjRunsFromActualOnAirItemsNotProjectedLinearLog(): void
    {
        $wrapper = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/AiDjRealtimeQueueListener.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/NowPlaying/Task/BuildQueueTask.php'
        );
        $events = file_get_contents(dirname(__DIR__, 2) . '/backend/config/events.php');

        self::assertIsString($wrapper);
        self::assertIsString($task);
        self::assertIsString($events);
        self::assertStringContainsString("'ai_dj_realtime_item_'", $wrapper);
        self::assertStringContainsString('$current->timestamp_start->getTimestamp()', $wrapper);
        self::assertStringContainsString('new BuildQueue($station, $now, $now, $current->song_id, false)', $wrapper);
        self::assertStringContainsString('$this->aiDjRealtimeQueueListener->run($station);', $task);
        self::assertStringNotContainsString('App\\Radio\\AutoDJ\\AiDjQueueListener::class,', $events);
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
    public function testActualTohTrackStartCreatesIdempotentHistoryFeedback(): void
    {
        $writer = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );
        $feedback = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/Command/FeedbackCommand.php'
        );
        $liq = file_get_contents(
            dirname(__DIR__, 2) . '/util/docker/stations/liquidsoap/azuracast.liq'
        );

        self::assertIsString($writer);
        self::assertIsString($feedback);
        self::assertIsString($liq);
        self::assertStringContainsString('def top_of_hour_send_feedback(metadata) =', $writer);
        self::assertStringContainsString(
            'source.methods(top_of_hour_queue).on_track(synchronous=false, top_of_hour_send_feedback)',
            $writer,
        );
        self::assertStringContainsString('isDuplicateTopOfHourFeedback(', $feedback);
        self::assertStringContainsString('sq.top_of_hour_legal_id = 1', $feedback);
        self::assertStringContainsString('"azuracast_top_of_hour_id"', $liq);
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
    public function testUpcomingQueueOverridesRuntimePriorityWithChronologicalDisplayOrder(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Controller/Api/Stations/QueueController.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString("->resetDQLPart('orderBy')", $source);
        self::assertStringContainsString("->orderBy('sq.timestamp_played', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.timestamp_cued', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.id', 'ASC')", $source);
    }
}
'''
)

# Strengthen two existing PR96 tests instead of adding overlapping replacements.
hold_test_path = "tests/Unit/TopOfHourPreBoundaryHoldTest.php"
hold_test = git_show(hold_test_path)
hold_test = replace_once(
    hold_test,
    "        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);\n"
    "        self::assertStringContainsString('track_sensitive=true,', $source);",
    "        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);\n"
    "        self::assertStringContainsString('boundary_has_delivery =', $source);\n"
    "        self::assertStringContainsString('top_of_hour_queue.length() > 0', $source);\n"
    "        self::assertStringContainsString('top_of_hour_queue.is_ready()', $source);\n"
    "        self::assertStringContainsString('track_sensitive=true,', $source);",
    "TOH hold regression test",
)
write(hold_test_path, hold_test)

strict_test_path = "tests/Unit/StrictStartGraceTest.php"
strict_test = git_show(strict_test_path)
strict_test = replace_once(
    strict_test,
    "        self::assertStringContainsString('sq.is_played = 1', $repository);\n"
    "        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);",
    "        self::assertStringContainsString('sq.is_played = 1', $repository);\n"
    "        self::assertStringContainsString('sq.sent_to_autodj = 1', $repository);\n"
    "        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);",
    "strict-start repository regression test",
)
strict_test = replace_once(
    strict_test,
    "        self::assertStringContainsString(\n"
    "            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',\n"
    "            $task,\n"
    "        );",
    "        self::assertStringContainsString(\n"
    "            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',\n"
    "            $task,\n"
    "        );\n"
    "        self::assertStringContainsString('$sq->sent_to_autodj = false;', $task);\n"
    "        self::assertStringContainsString('$sq->sent_to_autodj = true;', $task);\n"
    "        self::assertStringContainsString('discardUndeliveredInterruptingRow', $task);",
    "strict-start delivery regression test",
)
write(strict_test_path, strict_test)

# The final production commit must not contain temporary patch/workflow machinery.
for temporary in [
    ROOT / ".github/scripts/apply_toh_rescue.py",
    ROOT / ".github/workflows/apply_toh_rescue.yml",
]:
    if temporary.exists():
        temporary.unlink()
