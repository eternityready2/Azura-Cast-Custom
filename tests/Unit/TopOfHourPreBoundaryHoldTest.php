<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourPreBoundaryHoldTest extends TestCase
{
    public function testNormalMusicHandsOffAtNaturalTrackBoundaryWithoutBlankAudio(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('id="top_of_hour_natural_handoff"', $source);
        self::assertStringContainsString('track_sensitive=true,', $source);
        self::assertStringContainsString('[top_of_hour_queue, radio_before_top_of_hour]', $source);
        self::assertStringNotContainsString('PRE_BOUNDARY_HOLD_SECONDS', $source);
        self::assertStringNotContainsString('top_of_hour_preboundary_hold', $source);
        self::assertStringNotContainsString('top_of_hour_hold_new_track', $source);
        self::assertStringNotContainsString('duration=1.', $source);
    }

    public function testHardCutExistsOnlyAsPostBoundaryComplianceEmergency(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('def top_of_hour_force_takeover() =', $source);
        self::assertStringContainsString('seconds_in_hour >= {$forceTakeoverAfterSeconds}', $source);
        self::assertStringContainsString('top_of_hour_claimed_boundary() == boundary', $source);
        self::assertStringContainsString('top_of_hour_last_served_boundary() != boundary', $source);
        self::assertStringContainsString('id="top_of_hour_emergency_takeover"', $source);
        self::assertStringContainsString('track_sensitive=false,', $source);
    }

    public function testLateLegalIdStillMarksTheCorrectBoundaryAndSendsFeedback(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('seconds_in_hour >= 3480', $source);
        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);
        self::assertStringContainsString('top_of_hour_last_served_boundary := boundary', $source);
        self::assertStringContainsString('top_of_hour_send_feedback', $source);
    }
}
