<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TopOfHourSmartBacktimingTest extends TestCase
{
    public function testQueueBuilderUsesPrecisionBacktimingAndStretchBeforeFade(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            'private const int TOH_PRECISION_BACKTIME_SECONDS = 360;',
            $source,
        );
        self::assertStringContainsString('$this->preparePlaylistQueue(', $source);
        self::assertStringContainsString(
            'Hour boundary: precision-backtimed music to the TOH handoff.',
            $source,
        );

        $timingStart = strpos($source, 'private function applyTopOfHourTimingToQueueEntry');
        self::assertNotFalse($timingStart);
        $timingBlock = substr($source, $timingStart, 4500);
        $stretch = strpos($timingBlock, '$this->stretchCalculator->calculate');
        $fade = strpos($timingBlock, '$queueEntry->top_of_hour_pre_id_fade = true;');
        self::assertNotFalse($stretch);
        self::assertNotFalse($fade);
        self::assertLessThan($fade, $stretch);
    }

    public function testRequestsCannotForceAnOverrunAtTopOfHour(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/backend/src/Radio/AutoDJ/QueueBuilder.php'
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            'private function requestCanFitTopOfHourBoundary(',
            $source,
        );
        self::assertStringContainsString(
            'Listener request deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
        self::assertStringContainsString(
            'Request playlist item deferred because it cannot fit the approaching top-of-hour boundary.',
            $source,
        );
    }

    public function testStretchMetadataHandoffIsSynchronous(): void
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
