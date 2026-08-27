<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StrictStartGraceTest extends TestCase
{
    public function testStrictStartsHaveTwoMinuteOneShotCatchUpWindow(): void
    {
        $scheduler = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/Scheduler.php'
        );
        $repository = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Entity/Repository/StationQueueRepository.php'
        );

        self::assertIsString($scheduler);
        self::assertIsString($repository);
        self::assertStringContainsString('STRICT_START_GRACE_SECONDS = 120', $scheduler);
        self::assertStringContainsString('foreach ([$now, $now->subDay()] as $candidateDay)', $scheduler);
        self::assertStringContainsString('hasPlayedPlaylistSince(', $scheduler);
        self::assertStringContainsString('sq.is_played = 1', $repository);
        self::assertStringContainsString('sq.sent_to_autodj = 1', $repository);
        self::assertStringContainsString('sq.timestamp_played >= :since', $repository);
    }

    public function testTopOfHourNewsStillOwnsPriorityAheadOfStrictProgramming(): void
    {
        $news = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/TopOfHourNewsConfigWriter.php'
        );
        $task = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Sync/Task/QueueInterruptingTracks.php'
        );

        self::assertIsString($news);
        self::assertIsString($task);
        self::assertStringContainsString(
            '[top_of_hour_queue, top_news_bulletin_queue, radio_before_top_of_hour]',
            $news,
        );
        self::assertStringContainsString(
            'SCHEDULED_START_GRACE_SECONDS = Scheduler::STRICT_START_GRACE_SECONDS',
            $task,
        );
        self::assertStringContainsString('$sq->sent_to_autodj = false;', $task);
        self::assertStringContainsString('$sq->sent_to_autodj = true;', $task);
        self::assertStringContainsString('discardUndeliveredInterruptingRow', $task);
    }
}
