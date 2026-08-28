<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AiDjRealtimeQueueListenerTest extends TestCase
{
    public function testAiDjUsesActualOnAirItemsAndRetriesTemporaryDeferrals(): void
    {
        $wrapper = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/AiDjRealtimeQueueListener.php'
        );
        $listener = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/AiDjQueueListener.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/NowPlaying/Task/BuildQueueTask.php'
        );
        $events = file_get_contents(dirname(__DIR__, 2) . '/backend/config/events.php');

        self::assertIsString($wrapper);
        self::assertIsString($listener);
        self::assertIsString($task);
        self::assertIsString($events);

        self::assertStringContainsString("'ai_dj_realtime_item_'", $wrapper);
        self::assertStringContainsString('$current->timestamp_start->getTimestamp()', $wrapper);
        self::assertStringContainsString('ATTEMPT_TTL_SECONDS = 30', $wrapper);
        self::assertStringContainsString('$handled = $this->delegate->onBuildQueue(', $wrapper);
        self::assertStringContainsString('$this->cache->delete($cacheKey);', $wrapper);

        self::assertStringContainsString(
            'public function onBuildQueue(BuildQueue $event): bool',
            $listener,
        );
        self::assertMatchesRegularExpression(
            "/requests queue is not empty\\.'\\);\\s*return false;/",
            $listener,
        );
        self::assertMatchesRegularExpression(
            "/cooldown active\\.'.*?return false;/s",
            $listener,
        );
        self::assertMatchesRegularExpression(
            "/DJ clip is already queued ahead\\.'\\);\\s*return false;/",
            $listener,
        );

        self::assertStringContainsString('$this->aiDjRealtimeQueueListener->run($station);', $task);
        self::assertStringNotContainsString(
            'App\\Radio\\AutoDJ\\AiDjQueueListener::class,',
            $events,
        );
    }
}
