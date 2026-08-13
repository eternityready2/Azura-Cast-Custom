<?php

declare(strict_types=1);

namespace App\Utilities;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
    }

    public function contains(?DateTimeImmutable $time): bool
    {
        if (null === $time) {
            return false;
        }

        return CarbonImmutable::instance($time)->between($this->start, $this->end);
    }

    /**
     * True when this range and $toCompare share any moment in time.
     *
     * Uses a half-open interval comparison (start inclusive, end exclusive) so that
     * back-to-back schedule items -- one ending exactly when the next begins, e.g.
     * 1:00–2:00 PM followed by 2:00–3:00 PM -- are treated as adjacent, not
     * overlapping. This matches how most radio scheduling software (Airtime Pro,
     * Live365, etc.) allows shows to be scheduled edge-to-edge without a gap.
     */
    public function isWithin(self $toCompare): bool
    {
        return $this->start < $toCompare->end
            && $toCompare->start < $this->end;
    }

    public function format(
        string $format = 'Y-m-d H:i:s',
        string $separator = ' to '
    ): string {
        return $this->start->format($format) . $separator . $this->end->format($format);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
