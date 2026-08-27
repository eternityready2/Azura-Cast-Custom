<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QueueTimelineOrderTest extends TestCase
{
    public function testUpcomingQueueOverridesRuntimePriorityWithChronologicalDisplayOrder(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Controller/Api/Stations/QueueController.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString("->resetDQLPart('orderBy')", $source);
        self::assertStringContainsString("->orderBy('sq.timestamp_played', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.timestamp_cued', 'ASC')", $source);
        self::assertStringContainsString("->addOrderBy('sq.id', 'ASC')", $source);
    }
}
