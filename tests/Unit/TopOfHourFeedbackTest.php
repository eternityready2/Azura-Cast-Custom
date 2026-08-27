<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourFeedbackTest extends TestCase
{
    public function testActualTohTrackStartCreatesIdempotentHistoryFeedback(): void
    {
        $writer = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourConfigWriter.php'
        );
        $feedback = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/Command/FeedbackCommand.php'
        );
        $liq = file_get_contents(
            dirname(__DIR__, 2) . '/util/docker/stations/liquidsoap/azuracast.liq'
        );

        self::assertIsString($writer);
        self::assertIsString($feedback);
        self::assertIsString($liq);
        self::assertStringContainsString('def top_of_hour_send_feedback(metadata) =', $writer);
        self::assertStringContainsString(
            'source.methods(top_of_hour_queue).on_track(synchronous=false, top_of_hour_send_feedback)',
            $writer,
        );
        self::assertStringContainsString('isDuplicateTopOfHourFeedback(', $feedback);
        self::assertStringContainsString('sq.top_of_hour_legal_id = 1', $feedback);
        self::assertStringContainsString('"azuracast_top_of_hour_id"', $liq);
    }
}
