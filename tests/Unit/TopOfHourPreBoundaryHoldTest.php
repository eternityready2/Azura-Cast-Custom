<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourPreBoundaryHoldTest extends TestCase
{
    public function testNormalMusicIsHeldAtTrackBoundaryBeforeMinute59(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'private const int PRE_BOUNDARY_HOLD_SECONDS = 75;',
            $source,
        );
        self::assertStringContainsString(
            'def top_of_hour_hold_new_track() =',
            $source,
        );
        self::assertStringContainsString(
            'track_sensitive=true,',
            $source,
        );
        self::assertStringContainsString(
            '({ top_of_hour_hold_new_track() }, top_of_hour_preboundary_hold)',
            $source,
        );
    }

    public function testHoldReleasesAsSoonAsBoundaryIsServed(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'top_of_hour_last_served_boundary() != boundary',
            $source,
        );
        self::assertStringContainsString(
            '[top_of_hour_queue, radio_before_top_of_hour]',
            $source,
        );
    }
}
