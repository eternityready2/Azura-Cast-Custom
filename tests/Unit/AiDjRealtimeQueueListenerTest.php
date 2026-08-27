<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AiDjRealtimeQueueListenerTest extends TestCase
{
    public function testAiDjRunsFromActualOnAirItemsNotProjectedLinearLog(): void
    {
        $wrapper = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/AiDjRealtimeQueueListener.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/NowPlaying/Task/BuildQueueTask.php'
        );
        $events = file_get_contents(dirname(__DIR__, 2) . '/backend/config/events.php');

        self::assertIsString($wrapper);
        self::assertIsString($task);
        self::assertIsString($events);
        self::assertStringContainsString("'ai_dj_realtime_item_'", $wrapper);
        self::assertStringContainsString('$current->timestamp_start->getTimestamp()', $wrapper);
        self::assertStringContainsString('new BuildQueue($station, $now, $now, $current->song_id, false)', $wrapper);
        self::assertStringContainsString('$this->aiDjRealtimeQueueListener->run($station);', $task);
        self::assertStringNotContainsString('App\\Radio\\AutoDJ\\AiDjQueueListener::class,', $events);
    }
}
