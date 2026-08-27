<?php

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
