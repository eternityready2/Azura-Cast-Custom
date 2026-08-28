<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

/**
 * Computes a safe, pitch-preserving stretch ratio to close small gaps before
 * a mandatory anchor (e.g. top-of-hour legal ID). Only ever used for SMALL
 * gaps -- never a substitute for correct slot spacing or track selection.
 */
final class ClockWheelStretchCalculator
{
    private const float MIN_STRETCH_RATIO = 0.95; // up to 5% faster
    private const float MAX_STRETCH_RATIO = 1.05; // up to 5% slower

    /**
     * @return float|null Ratio to pass to Liquidsoap's stretch(), or null if
     *                     outside the safe range (caller should fall back to
     *                     the existing cue-out cap instead).
     */
    public function calculate(float $trackLengthSeconds, int $availableSeconds): ?float
    {
        if ($trackLengthSeconds <= 0 || $availableSeconds <= 0) {
            return null;
        }

        $ratio = $trackLengthSeconds / $availableSeconds;

        if ($ratio < self::MIN_STRETCH_RATIO || $ratio > self::MAX_STRETCH_RATIO) {
            return null;
        }

        return round($ratio, 4);
    }
}
