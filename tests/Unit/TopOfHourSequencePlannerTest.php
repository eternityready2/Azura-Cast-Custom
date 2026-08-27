<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Radio\AutoDJ\ClockWheel\ClockWheelStretchCalculator;
use App\Radio\AutoDJ\TopOfHourSequencePlanner;
use PHPUnit\Framework\TestCase;

final class TopOfHourSequencePlannerTest extends TestCase
{
    private TopOfHourSequencePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new TopOfHourSequencePlanner(new ClockWheelStretchCalculator());
    }

    public function testLongTrackThatStrandsFiftySevenSecondsIsRejected(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 333.0, 'order' => 0],
                ['key' => 2, 'length' => 205.0, 'order' => 1],
            ],
            [185.0, 205.0, 333.0],
            390.0,
            2.0,
        );

        self::assertNotEmpty($ranked);
        self::assertSame(2, $ranked[0]['key']);
        self::assertNotContains(1, array_column($ranked, 'key'));
    }

    public function testImpossibleFiftySevenSecondSlotStartsNoFullSong(): void
    {
        $ranked = $this->planner->rankFirstCandidates(
            [
                ['key' => 1, 'length' => 180.0],
                ['key' => 2, 'length' => 240.0],
            ],
            [180.0, 240.0],
            57.0,
            2.0,
        );

        self::assertSame([], $ranked);
    }

    public function testFinalStretchAccountsForCrossfadeOverlap(): void
    {
        self::assertSame(1.05, $this->planner->getStretchRatioToFill(180.0, 187.0, 2.0));
    }
}
