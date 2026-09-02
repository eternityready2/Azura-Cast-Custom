<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Station;
use App\Service\AiDjScheduler;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeImmutable;

final class AiDjSchedulerTest extends Unit
{
    private AiDjScheduler $scheduler;

    private ReloadableEntityManagerInterface $em;

    private Station $station;

    protected function _inject(Module $testsModule): void
    {
        $this->scheduler = $testsModule->container->get(AiDjScheduler::class);
        $this->em = $testsModule->em;
    }

    protected function _before(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        $this->station = new Station();
        $this->station->name = 'AI DJ Test Station';
        $this->station->short_name = 'ai_dj_test';
        $this->station->timezone = 'UTC';
    }

    protected function _after(): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }
    }

    public function testReturnsNullWhenNoStationFound(): void
    {
        $result = $this->scheduler->findActiveDj(999999);

        self::assertNull($result);
    }

    public function testReturnsNullWhenNoScheduleExists(): void
    {
        $station = $this->persistStation();

        try {
            $result = $this->scheduler->findActiveDj($station->id);

            self::assertNull($result);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testReturnsNullWhenScheduleNotEnabled(): void
    {
        $station = $this->persistStation();

        $dj = $this->createDj($station, 'Disabled DJ', false);

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([1, 2, 3, 4, 5, 6, 7]);
        $schedule->setStartTime(new DateTimeImmutable('00:00'));
        $schedule->setEndTime(new DateTimeImmutable('23:59'));
        $schedule->setIsEnabled(false);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $result = $this->scheduler->findActiveDj($station->id);

            self::assertNull($result);
        } finally {
            $this->removeStation($station);
        }
    }

    public function testReturnsDjWhenScheduleIsActive(): void
    {
        $station = $this->persistStation();

        $dj = $this->createDj($station, 'Active DJ');

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([1, 2, 3, 4, 5, 6, 7]);
        $schedule->setStartTime(new DateTimeImmutable('00:00'));
        $schedule->setEndTime(new DateTimeImmutable('23:59'));
        $schedule->setIsEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $result = $this->scheduler->findActiveDj($station->id);

            self::assertInstanceOf(AiDj::class, $result);
            self::assertSame('Active DJ', $result->getName());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testRespectsDayOfWeek(): void
    {
        $station = $this->persistStation();

        $dj = $this->createDj($station, 'Weekend DJ');

        $schedule = new AiDjSchedule($dj);
        $schedule->setLoopDays([6, 7]); // Saturday, Sunday only
        $schedule->setStartTime(new DateTimeImmutable('00:00'));
        $schedule->setEndTime(new DateTimeImmutable('23:59'));
        $schedule->setIsEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            // Wednesday (day 3)
            $wednesday = CarbonImmutable::parse('2026-05-27 10:00:00', 'UTC');
            $result = $this->scheduler->findActiveDj($station->id, $wednesday);
            self::assertNull($result);

            // Saturday (day 6)
            $saturday = CarbonImmutable::parse('2026-05-30 10:00:00', 'UTC');
            $result = $this->scheduler->findActiveDj($station->id, $saturday);
            self::assertInstanceOf(AiDj::class, $result);
            self::assertSame('Weekend DJ', $result->getName());
        } finally {
            $this->removeStation($station);
        }
    }

    public function testReturnsActiveScheduleAndConcreteShiftWindow(): void
    {
        $station = $this->persistStation();

        $dj = $this->createDj($station, 'Afternoon DJ');

        $schedule = new AiDjSchedule($dj);
        $schedule->setName('Afternoon Shift');
        $schedule->setLoopDays([1, 2, 3, 4, 5, 6, 7]);
        $schedule->setStartTime(new DateTimeImmutable('12:01'));
        $schedule->setEndTime(new DateTimeImmutable('16:55'));
        $schedule->setIsEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $timestamp = new DateTimeImmutable('2026-09-02 15:00:00 UTC');
            $activeSchedule = $this->scheduler->findActiveSchedule($station->id, $timestamp);

            self::assertInstanceOf(AiDjSchedule::class, $activeSchedule);
            self::assertSame($schedule->getId(), $activeSchedule->getId());

            $window = $this->scheduler->getShiftWindow($station, $activeSchedule, $timestamp);
            self::assertSame('2026-09-02 12:01:00', $window['starts_at']->format('Y-m-d H:i:s'));
            self::assertSame('2026-09-02 16:55:00', $window['ends_at']->format('Y-m-d H:i:s'));
        } finally {
            $this->removeStation($station);
        }
    }

    public function testConcreteShiftWindowHandlesCrossMidnightSchedule(): void
    {
        $station = $this->persistStation();

        $dj = $this->createDj($station, 'Overnight DJ');

        $schedule = new AiDjSchedule($dj);
        $schedule->setName('Overnight Shift');
        $schedule->setLoopDays([1, 2, 3, 4, 5, 6, 7]);
        $schedule->setStartTime(new DateTimeImmutable('22:00'));
        $schedule->setEndTime(new DateTimeImmutable('06:00'));
        $schedule->setIsEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            $timestamp = new DateTimeImmutable('2026-09-03 02:00:00 UTC');
            $activeSchedule = $this->scheduler->findActiveSchedule($station->id, $timestamp);

            self::assertInstanceOf(AiDjSchedule::class, $activeSchedule);

            $window = $this->scheduler->getShiftWindow($station, $activeSchedule, $timestamp);
            self::assertSame('2026-09-02 22:00:00', $window['starts_at']->format('Y-m-d H:i:s'));
            self::assertSame('2026-09-03 06:00:00', $window['ends_at']->format('Y-m-d H:i:s'));
        } finally {
            $this->removeStation($station);
        }
    }

    public function testConcreteShiftWindowUsesStationTimezone(): void
    {
        $station = $this->persistStation();
        $station->timezone = 'America/Chicago';
        $this->em->persist($station);
        $this->em->flush();

        $dj = $this->createDj($station, 'Central Time DJ');

        $schedule = new AiDjSchedule($dj);
        $schedule->setName('Central Afternoon Shift');
        $schedule->setLoopDays([1, 2, 3, 4, 5, 6, 7]);
        $schedule->setStartTime(new DateTimeImmutable('12:01'));
        $schedule->setEndTime(new DateTimeImmutable('16:55'));
        $schedule->setIsEnabled(true);

        $this->em->persist($dj);
        $this->em->persist($schedule);
        $this->em->flush();

        try {
            // 20:00 UTC is 15:00 CDT on Sep 2, 2026.
            $timestamp = new DateTimeImmutable('2026-09-02 20:00:00 UTC');
            $activeSchedule = $this->scheduler->findActiveSchedule($station->id, $timestamp);

            self::assertInstanceOf(AiDjSchedule::class, $activeSchedule);

            $window = $this->scheduler->getShiftWindow($station, $activeSchedule, $timestamp);
            self::assertSame('2026-09-02 12:01:00', $window['starts_at']->format('Y-m-d H:i:s'));
            self::assertSame('2026-09-02 16:55:00', $window['ends_at']->format('Y-m-d H:i:s'));
            self::assertSame('America/Chicago', $window['starts_at']->getTimezone()->getName());
        } finally {
            $this->removeStation($station);
        }
    }

    private function createDj(Station $station, string $name, bool $enabled = true): AiDj
    {
        $dj = new AiDj();
        $dj->setStation($station);
        $dj->setName($name);
        $dj->setIsEnabled($enabled);

        return $dj;
    }

    private function persistStation(): Station
    {
        $station = new Station();
        $station->name = 'AI DJ DB Test';
        $station->short_name = 'ai_dj_db_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $this->em->persist($station->media_storage_location);
        $this->em->persist($station->recordings_storage_location);
        $this->em->persist($station->podcasts_storage_location);
        $this->em->persist($station);
        $this->em->flush();

        return $station;
    }

    private function removeStation(Station $station): void
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        $this->em->createQuery('DELETE FROM App\Entity\AiDjSchedule s WHERE s.ai_dj IN (SELECT d FROM App\Entity\AiDj d WHERE d.station = :station)')
            ->setParameter('station', $station)
            ->execute();

        $this->em->createQuery('DELETE FROM App\Entity\AiDj d WHERE d.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $this->em->remove($station);
        $this->em->remove($station->media_storage_location);
        $this->em->remove($station->recordings_storage_location);
        $this->em->remove($station->podcasts_storage_location);
        $this->em->flush();
        $this->em->clear();
    }
}
