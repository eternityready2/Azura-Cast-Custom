<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationQueue;
use App\Entity\StationSchedule;
use App\Entity\Song;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel\ClockWheelPlaybackPlanner;
use App\Radio\AutoDJ\ClockWheelScheduler;
use App\Radio\AutoDJ\Scheduler;
use App\Radio\Schedule\ScheduleConflictChecker;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

final class ClockWheelSchedulerTest extends Unit
{
    private Scheduler $scheduler;

    private Station $station;

    private Module $testsModule;

    private MockInterface $scheduleRepo;

    /** @var ScheduleConflictChecker&MockObject */
    private ScheduleConflictChecker $conflictChecker;

    /** @var ClockWheelPlaybackPlanner&MockObject */
    private ClockWheelPlaybackPlanner $planner;

    private MockInterface $queueRepo;

    private ClockWheelScheduler $clockWheelScheduler;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
        $this->scheduler = $testsModule->container->get(Scheduler::class);
    }

    protected function _before(): void
    {
        $this->station = new Station();
        $this->station->name = 'Scheduler Test';
        $this->station->short_name = 'scheduler_test';
        $this->station->timezone = 'UTC';

        $realScheduleRepo = $this->testsModule->container->get(StationScheduleRepository::class);
        $this->scheduleRepo = Mockery::mock($realScheduleRepo);

        $realQueueRepo = $this->testsModule->container->get(StationQueueRepository::class);
        $this->queueRepo = Mockery::mock($realQueueRepo);
        $this->queueRepo->allows('getRecentlyPlayedByTimeRange')->andReturn([]);

        $this->conflictChecker = $this->createMock(ScheduleConflictChecker::class);
        $this->planner = $this->createMock(ClockWheelPlaybackPlanner::class);

        $this->clockWheelScheduler = new ClockWheelScheduler(
            $this->queueRepo,
            $this->scheduleRepo,
            $this->scheduler,
            $this->planner,
            $this->conflictChecker,
        );

        $em = $this->createMock(ReloadableEntityManagerInterface::class);
        $em->expects(self::any())->method('flush');

        $this->clockWheelScheduler->setEntityManager($em);
        $this->clockWheelScheduler->setLogger($this->createMock(LoggerInterface::class));
    }

    protected function _after(): void
    {
        Mockery::close();
    }

    public function testSkipsWhenNextSongsAlreadySet(): void
    {
        $event = $this->makeEvent();
        $existing = new StationQueue($this->station, Song::createFromText('Artist - Existing'));
        $event->setNextSongs($existing);

        $this->planner->expects(self::never())->method('resolveNextQueueEntry');

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertCount(1, $event->getNextSongs());
    }

    public function testSkipsWhenAnotherPlaylistOrStreamerScheduleIsActive(): void
    {
        $event = $this->makeEvent($this->activePlayTime());

        $this->conflictChecker->method('hasNonClockWheelScheduleActive')->willReturn(true);
        $this->planner->expects(self::never())->method('resolveNextQueueEntry');

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertSame([], $event->getNextSongs());
    }

    public function testSkipsWhenNoClockWheelScheduleIsActive(): void
    {
        $event = $this->makeEvent($this->activePlayTime());

        $this->conflictChecker->method('hasNonClockWheelScheduleActive')->willReturn(false);
        $this->scheduleRepo->allows('getAllScheduledItemsForStation')->andReturn([]);
        $this->planner->expects(self::never())->method('resolveNextQueueEntry');

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertSame([], $event->getNextSongs());
    }

    public function testSkipsWhenWheelIsInactive(): void
    {
        $event = $this->makeEvent($this->activePlayTime());
        $wheel = $this->makeActiveWheel(false);
        $schedule = $this->makeWheelSchedule($wheel);

        $this->conflictChecker->method('hasNonClockWheelScheduleActive')->willReturn(false);
        $this->scheduleRepo->allows('getAllScheduledItemsForStation')->andReturn([$schedule]);
        $this->planner->expects(self::never())->method('resolveNextQueueEntry');

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertSame([], $event->getNextSongs());
    }

    public function testSetsNextSongWhenWheelIsActiveAndPlannerResolves(): void
    {
        $playTime = $this->activePlayTime();
        $event = $this->makeEvent($playTime);
        $wheel = $this->makeActiveWheel(true);
        $schedule = $this->makeWheelSchedule($wheel);
        $resolved = new StationQueue($this->station, Song::createFromText('ID Artist - Sweeper'));

        $this->conflictChecker->method('hasNonClockWheelScheduleActive')->willReturn(false);
        $this->scheduleRepo->allows('getAllScheduledItemsForStation')->andReturn([$schedule]);
        $this->planner->expects(self::once())
            ->method('resolveNextQueueEntry')
            ->with($wheel, [], $playTime, $schedule)
            ->willReturn($resolved);

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertCount(1, $event->getNextSongs());
        self::assertSame($resolved, $event->getNextSongs()[0]);
    }

    public function testDoesNotSetNextSongWhenPlannerReturnsNull(): void
    {
        $playTime = $this->activePlayTime();
        $event = $this->makeEvent($playTime);
        $wheel = $this->makeActiveWheel(true);
        $schedule = $this->makeWheelSchedule($wheel);

        $this->conflictChecker->method('hasNonClockWheelScheduleActive')->willReturn(false);
        $this->scheduleRepo->allows('getAllScheduledItemsForStation')->andReturn([$schedule]);
        $this->planner->method('resolveNextQueueEntry')->willReturn(null);

        $this->clockWheelScheduler->buildFromClockWheel($event);

        self::assertSame([], $event->getNextSongs());
    }

    private function makeEvent(?DateTimeImmutable $expectedPlayTime = null): BuildQueue
    {
        return new BuildQueue(
            $this->station,
            $expectedPlayTime,
            $expectedPlayTime,
        );
    }

    private function activePlayTime(): DateTimeImmutable
    {
        return CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC');
    }

    private function makeActiveWheel(bool $isActive): StationClockWheel
    {
        $wheel = new StationClockWheel($this->station);
        $wheel->name = 'Morning Wheel';
        $wheel->is_active = $isActive;
        $this->setEntityId($wheel, 1);

        return $wheel;
    }

    private function makeWheelSchedule(StationClockWheel $wheel): StationSchedule
    {
        $schedule = new StationSchedule($wheel);
        $schedule->start_time = 900;
        $schedule->end_time = 1700;
        $schedule->start_date = '2026-01-01';
        $schedule->end_date = '2026-12-31';
        $schedule->days = [1, 2, 3, 4, 5];

        return $schedule;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
