<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourContinuityIntegrationTest extends TestCase
{
    public function testOpenHourIdUsesFullConfiguredPlanningWindow(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/TopOfHourIdScheduler.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            '$this->hourBoundaryPlanner->getIdWindowLeadSeconds($station)',
            $source,
        );
        self::assertStringNotContainsString('OPEN_HOUR_TRIGGER_LEAD_SECONDS', $source);
        self::assertStringContainsString('natural-handoff window', $source);
    }

    public function testLiveQueuePrebuildsPostIdAudioAcrossProtectedWindow(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Queue.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'if ($this->hourBoundaryPlanner->isInTopOfHourIdWindow($station, $expectedPlayTime))',
            $source,
        );
        self::assertStringContainsString(
            "null !== $lookaheadMinutesOverride ? 'Linear Log' : 'AutoDJ'",
            $source,
        );
        self::assertStringContainsString('prebuilding post-ID audio.', $source);
        self::assertStringNotContainsString(
            'null !== $lookaheadMinutesOverride\n                    && $this->hourBoundaryPlanner->isInTopOfHourIdWindow',
            $source,
        );
    }

    public function testRoutineBlankHoldAndRoutineNonTrackSensitiveTakeoverAreGone(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringNotContainsString('top_of_hour_preboundary_hold', $source);
        self::assertStringNotContainsString('top_of_hour_hold_fallback', $source);
        self::assertStringContainsString('top_of_hour_natural_radio = fallback(', $source);
        self::assertStringContainsString('id="top_of_hour_natural_handoff"', $source);
        self::assertStringContainsString('id="top_of_hour_emergency_takeover"', $source);
    }
}
