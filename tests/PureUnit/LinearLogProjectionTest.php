<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Station;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AiDjProjectionSafeListener;
use App\Radio\AutoDJ\LinearLogBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LinearLogProjectionTest extends TestCase
{
    public function testLinearLogHorizonIsAlwaysBetweenTwentyFourAndFortyEightHours(): void
    {
        self::assertSame(24, LinearLogBuilder::normalizeHours(1));
        self::assertSame(24, LinearLogBuilder::normalizeHours(12));
        self::assertSame(24, LinearLogBuilder::normalizeHours(24));
        self::assertSame(36, LinearLogBuilder::normalizeHours(36));
        self::assertSame(48, LinearLogBuilder::normalizeHours(48));
        self::assertSame(48, LinearLogBuilder::normalizeHours(72));
    }

    public function testProjectionFlagIsExplicitAndDefaultsToLiveMode(): void
    {
        $station = new Station();
        $time = new DateTimeImmutable('2026-08-28 12:00:00 UTC');

        $live = new BuildQueue($station, $time, $time);
        $projection = new BuildQueue($station, $time, $time, null, false, true);

        self::assertFalse($live->isProjection());
        self::assertTrue($projection->isProjection());
    }

    public function testProjectedQueueDecisionDoesNotInvokeLiveAiDjDelegate(): void
    {
        // Deliberately construct without the delegate. A projection must return before
        // the live AI DJ dependency is accessed; if it does not, this test errors.
        $listener = (new ReflectionClass(AiDjProjectionSafeListener::class))
            ->newInstanceWithoutConstructor();

        $station = new Station();
        $time = new DateTimeImmutable('2026-08-28 12:00:00 UTC');
        $projection = new BuildQueue($station, $time, $time, null, false, true);

        $listener->onBuildQueue($projection);

        self::assertTrue($projection->isProjection());
    }
}
