<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Radio\AutoDJ\ClockWheel\HourTimeline;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;
use Mockery;
use Monolog\Logger;

class HourTimelineRigidTest extends Unit
{
    private HourTimeline $timeline;

    private Station $station;

    protected function _before(): void
    {
        $this->timeline = new HourTimeline();
        $this->timeline->setLogger(new Logger('test'));

        /** @var Station $station */
        $station = Mockery::mock(Station::class);
        $station->shouldReceive('getTimezoneObject')->andReturn(new DateTimeZone('UTC'));
        $this->station = $station;
    }

    public function testRigidSlotPreemptsUpcomingWhenCrossedWithinGrace(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => true],
            ['position' => 1800, 'order' => 1, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(30));

        self::assertNotNull($plan);
        self::assertSame(0,    $plan->slot->position_seconds);
        self::assertTrue($plan->slot->is_rigid);
        self::assertSame(30,   $plan->currentT);
        self::assertSame(1770, $plan->availableSeconds);
    }

    public function testRigidSlotIsAbandonedAfterGracePeriodExpires(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => true],
            ['position' => 1800, 'order' => 1, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext(
            $wheel,
            $this->topOfHour()->addSeconds(HourTimeline::RIGID_GRACE_SECONDS + 1)
        );

        self::assertNotNull($plan);
        self::assertSame(
            1800,
            $plan->slot->position_seconds,
            'Once the rigid grace window expires the timeline must fall through to the next upcoming slot.'
        );
    }

    public function testNonRigidSlotIsNeverPreemptedAfterCrossing(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => false],
            ['position' => 1800, 'order' => 1, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(30));

        self::assertNotNull($plan);
        self::assertSame(
            1800,
            $plan->slot->position_seconds,
            'A non-rigid slot that has already been crossed must never preempt the rotation.'
        );
    }

    public function testMostRecentlyCrossedRigidWins(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => true],
            ['position' => 60,   'order' => 1, 'rigid' => true],
            ['position' => 1800, 'order' => 2, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(80));

        self::assertNotNull($plan);
        self::assertSame(
            60,
            $plan->slot->position_seconds,
            'When several rigid slots are inside the grace window the most recently crossed one must win.'
        );
    }

    public function testFutureRigidSlotIsPickedByStandardUpcomingPath(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => false],
            ['position' => 1800, 'order' => 1, 'rigid' => true],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(900));

        self::assertNotNull($plan);
        self::assertSame(1800, $plan->slot->position_seconds);
        self::assertTrue($plan->slot->is_rigid);
    }

    public function testRigidAtTopOfHourWinsAgainstAlreadyPassedNonRigid(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => true],
            ['position' => 30,   'order' => 1, 'rigid' => false],
            ['position' => 1800, 'order' => 2, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext($wheel, $this->topOfHour()->addSeconds(45));

        self::assertNotNull($plan);
        self::assertSame(
            0,
            $plan->slot->position_seconds,
            'A rigid top-of-hour slot must beat a non-rigid slot that was crossed more recently.'
        );
    }

    public function testRigidGracePeriodIsExactlyInclusive(): void
    {
        $wheel = $this->buildWheel([
            ['position' => 0,    'order' => 0, 'rigid' => true],
            ['position' => 1800, 'order' => 1, 'rigid' => false],
        ]);

        $plan = $this->timeline->planNext(
            $wheel,
            $this->topOfHour()->addSeconds(HourTimeline::RIGID_GRACE_SECONDS)
        );

        self::assertNotNull($plan);
        self::assertSame(
            0,
            $plan->slot->position_seconds,
            'The grace window is inclusive of its upper bound, so a slot crossed exactly RIGID_GRACE_SECONDS ago still wins.'
        );
    }

    /**
     * @param array<int, array{position:int, order:int, rigid:bool}> $entries
     */
    private function buildWheel(array $entries): StationClockWheel
    {
        $wheel = new StationClockWheel($this->station);
        foreach ($entries as $entry) {
            $slot = new StationClockWheelSlot($wheel);
            $slot->position_seconds = $entry['position'];
            $slot->slot_order       = $entry['order'];
            $slot->is_rigid         = $entry['rigid'];
            $wheel->slots->add($slot);
        }
        return $wheel;
    }

    private function topOfHour(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 5, 23, 19, 0, 0, new DateTimeZone('UTC'));
    }
}
