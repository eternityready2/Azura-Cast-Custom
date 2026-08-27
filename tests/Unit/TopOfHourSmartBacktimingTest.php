<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourSmartBacktimingTest extends TestCase
{
    public function testQueueBuilderUsesFullLookaheadSequencePlanningAndNoRoutineFade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('TopOfHourSequencePlanner $topOfHourSequencePlanner', $source);
        self::assertStringContainsString('rankFirstCandidates(', $source);
        self::assertStringContainsString('getTopOfHourFutureMusicLengths(', $source);
        self::assertStringNotContainsString('TOH_PRECISION_BACKTIME_SECONDS', $source);
        self::assertStringNotContainsString('$queueEntry->top_of_hour_pre_id_fade = true;', $source);
        self::assertStringContainsString('routine cut/fade is refused', $source);
    }

    public function testRequestsDoNotCreateAnUnfillableLateHourOverrun(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString('private function requestCanFitTopOfHourBoundary(', $source);
        self::assertStringContainsString('canStartTrack(', $source);
        self::assertStringContainsString(
            'Listener request deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
        self::assertStringContainsString(
            'Request playlist item deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
    }

    public function testStretchMetadataHandoffRemainsSynchronous(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/Backend/Liquidsoap/ConfigWriter.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString(
            'source.methods(radio).on_track(synchronous=true, fun (m) -> begin',
            $source,
        );
    }
}
