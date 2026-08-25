<?php

declare(strict_types=1);

namespace Unit;

use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use Codeception\Test\Unit;

final class ClockWheelStretchCalculatorTest extends Unit
{
    public function testNearOverrunCanBeCompressedToBoundary(): void
    {
        $calculator = new ClockWheelStretchCalculator();

        self::assertSame(0.9945, $calculator->calculate(181.0, 180));
    }

    public function testNearGapCanBeExpandedToBoundary(): void
    {
        $calculator = new ClockWheelStretchCalculator();

        self::assertSame(1.0465, $calculator->calculate(172.0, 180));
    }

    public function testUnsafeStretchIsRejected(): void
    {
        $calculator = new ClockWheelStretchCalculator();

        self::assertNull($calculator->calculate(200.0, 180));
    }

    public function testLiquidsoapAnnotationKeepsFourDecimalRatio(): void
    {
        $annotation = ConfigWriter::annotateArray([
            'liq_stretch_ratio' => 0.9945,
        ]);

        self::assertSame('liq_stretch_ratio="0.9945"', $annotation);
    }
}
