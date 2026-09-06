<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Entity\StationMedia;
use DateTimeImmutable;

final readonly class TopOfHourPlan
{
    public function __construct(
        public TopOfHourMode $mode,
        public DateTimeImmutable $boundaryAt,
        public DateTimeImmutable $targetStartAt,
        public StationMedia $media,
        public float $durationSeconds,
    ) {
    }

    public function isHard(): bool
    {
        return TopOfHourMode::HardToh === $this->mode;
    }
}
