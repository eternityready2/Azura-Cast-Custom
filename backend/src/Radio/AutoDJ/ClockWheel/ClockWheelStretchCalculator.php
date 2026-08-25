<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

/**
 * Computes a safe Liquidsoap stretch ratio to close small timing gaps.
 */
final class ClockWheelStretchCalculator
{
    private const float MIN_STRETCH_RATIO = 0.95;
    private const float MAX_STRETCH_RATIO = 1.05;

    /**
     * Liquidsoap's stretch ratio is output duration divided by source duration:
     * values below 1 accelerate playback and values above 1 slow it down.
     */
    public function calculate(float $trackLengthSeconds, int $availableSeconds): ?float
    {
        if ($trackLengthSeconds <= 0 || $availableSeconds <= 0) {
            return null;
        }

        $ratio = $availableSeconds / $trackLengthSeconds;

        if ($ratio < self::MIN_STRETCH_RATIO || $ratio > self::MAX_STRETCH_RATIO) {
            return null;
        }

        return round($ratio, 4);
    }
}
