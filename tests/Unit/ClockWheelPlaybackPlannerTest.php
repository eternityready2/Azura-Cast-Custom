<?php

declare(strict_types=1);

namespace Unit;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\Scheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ClockWheelPlaybackPlannerTest extends Unit
{
    private ClockWheelPlaybackPlanner $planner;

    private Station $station;

    /** @var StationQueueRepository&MockObject */
    private StationQueueRepository $queueRepo;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    protected function _inject(Module $testsModule): void
    {
        $this->station = new Station();
        $this->station->name = 'Planner Test';
        $this->station->short_name = 'planner_test';
        $this->station->timezone = 'UTC';

        $this->queueRepo = $this->createMock(StationQueueRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->planner = new ClockWheelPlaybackPlanner(
            $this->em,
            $this->queueRepo,
            $this->createMock(DuplicatePrevention::class),
            $testsModule->container->get(Scheduler::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testSequentialSlotIndexLoopsByPlayCount(): void
    {
        $wheel = $this->makeWheelWithSlots([
            ClockWheelSlotTypes::Id,
            ClockWheelSlotTypes::Music,
            ClockWheelSlotTypes::Ad,
        ]);

        $slots = $this->invokeSortSlots($wheel->slots->toArray());

        self::assertSame(0, $this->invokeSlotIndex($slots, 0));
        self::assertSame(1, $this->invokeSlotIndex($slots, 1));
        self::assertSame(2, $this->invokeSlotIndex($slots, 2));
        self::assertSame(0, $this->invokeSlotIndex($slots, 3));
        self::assertSame(1, $this->invokeSlotIndex($slots, 4));
    }

    public function testSlotsSortBySlotOrderOnly(): void
    {
        $wheel = new StationClockWheel($this->station);

        $second = new StationClockWheelSlot($wheel);
        $second->slot_order = 1;
        $second->type = ClockWheelSlotTypes::Ad;

        $first = new StationClockWheelSlot($wheel);
        $first->slot_order = 0;
        $first->type = ClockWheelSlotTypes::Id;

        $sorted = $this->invokeSortSlots([$second, $first]);

        self::assertSame(0, $sorted[0]->slot_order);
        self::assertSame(ClockWheelSlotTypes::Id, $sorted[0]->type);
        self::assertSame(1, $sorted[1]->slot_order);
    }

    /**
     * @param ClockWheelSlotTypes[] $types
     */
    private function makeWheelWithSlots(array $types): StationClockWheel
    {
        $wheel = new StationClockWheel($this->station);
        $order = 0;

        foreach ($types as $type) {
            $slot = new StationClockWheelSlot($wheel);
            $slot->slot_order = $order++;
            $slot->type = $type;
            $wheel->addSlot($slot);
        }

        return $wheel;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     *
     * @return StationClockWheelSlot[]
     */
    private function invokeSortSlots(array $slots): array
    {
        $method = new ReflectionMethod(ClockWheelPlaybackPlanner::class, 'sortSlots');

        return $method->invoke($this->planner, $slots);
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function invokeSlotIndex(array $slots, int $playIndex): int
    {
        return $playIndex % count($slots);
    }
}
