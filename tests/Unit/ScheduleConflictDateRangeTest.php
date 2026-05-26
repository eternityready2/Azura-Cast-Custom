<?php

declare(strict_types=1);

namespace Unit;

use App\Utilities\DateRange;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class ScheduleConflictDateRangeTest extends Unit
{
    public function testOverlappingRanges(): void
    {
        $a = new DateRange(
            CarbonImmutable::parse('2026-05-19 09:00:00'),
            CarbonImmutable::parse('2026-05-19 10:00:00'),
        );
        $b = new DateRange(
            CarbonImmutable::parse('2026-05-19 09:30:00'),
            CarbonImmutable::parse('2026-05-19 10:30:00'),
        );

        self::assertTrue($a->isWithin($b));
    }

    public function testNonOverlappingRanges(): void
    {
        $a = new DateRange(
            CarbonImmutable::parse('2026-05-19 09:00:00'),
            CarbonImmutable::parse('2026-05-19 10:00:00'),
        );
        $b = new DateRange(
            CarbonImmutable::parse('2026-05-19 10:00:00'),
            CarbonImmutable::parse('2026-05-19 11:00:00'),
        );

        self::assertFalse($a->isWithin($b));
    }
}
