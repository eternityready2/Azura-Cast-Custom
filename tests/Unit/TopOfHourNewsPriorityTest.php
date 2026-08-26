<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the generated Liquidsoap playout contract for the top-of-hour chain.
 *
 * Runtime syntax is separately checked against Liquidsoap 2.4.5. These tests
 * protect the source-ordering decisions that must not regress during refactors.
 */
final class TopOfHourNewsPriorityTest extends TestCase
{
    public function testTopOfHourNewsUsesDedicatedPriorityQueue(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourNewsConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            '[top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]',
            $source,
        );
        self::assertStringContainsString(
            'top_news_bulletin_queue.push(request.create(top_news_bulletin_request))',
            $source,
        );
    }

    public function testTopNewsWaitsForObservedLegalIdBeforeNormalQueueing(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourNewsConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'top_of_hour_last_served_boundary() == pending',
            $source,
        );
        self::assertStringContainsString(
            'queue_top_news_for_boundary(pending, "legal-id-started")',
            $source,
        );
    }

    public function testLegacyNewsWriterYieldsMinute59ToCoordinator(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/ConfigWriter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('$coordinateTopNews', $source);
        self::assertStringContainsString(
            '// The dedicated TOH news writer owns minute 59.',
            $source,
        );
    }
}
