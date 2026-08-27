<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourPreBoundaryHoldTest extends TestCase
{
    public function testNormalMusicIsHeldAtTrackBoundaryAroundTopOfHour(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('PRE_BOUNDARY_HOLD_SECONDS = 75', $source);
        self::assertStringContainsString('POST_BOUNDARY_HOLD_SECONDS = 30', $source);
        self::assertStringContainsString('def top_of_hour_hold_new_track() =', $source);
        self::assertStringContainsString('duration=1.', $source);
        self::assertStringContainsString('seconds_in_hour <= {$postBoundaryHoldSeconds}', $source);
        self::assertStringContainsString('boundary_has_delivery =', $source);
        self::assertStringContainsString('top_of_hour_queue.length() > 0', $source);
        self::assertStringContainsString('top_of_hour_queue.is_ready()', $source);
        self::assertStringContainsString('track_sensitive=true,', $source);
    }

    public function testHoldReleasesWhenBoundaryIsServedAndLateIdCanMarkBoundary(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('top_of_hour_last_served_boundary() != boundary', $source);
        self::assertStringContainsString('now_seconds - seconds_in_hour', $source);
        self::assertStringContainsString('[top_of_hour_queue, radio_before_top_of_hour]', $source);
    }
}
