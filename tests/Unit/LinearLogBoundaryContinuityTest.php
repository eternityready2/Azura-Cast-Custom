<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LinearLogBoundaryContinuityTest extends TestCase
{
    public function testLongRangeProjectionContinuesAcrossProtectedTopOfHourWindow(): void
    {
        $queue = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php'
        );
        $linearLog = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/LinearLogBuilder.php'
        );

        self::assertIsString($queue);
        self::assertIsString($linearLog);

        self::assertStringContainsString('HourBoundaryPlanner $hourBoundaryPlanner', $queue);
        self::assertStringContainsString('null !== $lookaheadMinutesOverride', $queue);
        self::assertStringContainsString(
            'isInTopOfHourIdWindow($station, $expectedPlayTime)',
            $queue,
        );
        self::assertStringContainsString(
            'Linear Log: crossing protected top-of-hour window and continuing projection.',
            $queue,
        );
        self::assertStringContainsString('$expectedPlayTime = $resumeAt;', $queue);
        self::assertStringContainsString('$expectedCueTime = $resumeAt;', $queue);
        self::assertStringContainsString(
            'min(48, $hoursOverride ?? $station->backend_config->linear_log_hours)',
            $linearLog,
        );
    }
}
