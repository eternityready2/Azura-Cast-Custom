<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\Enums\CrossfadeModes;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class TopOfHourPlanningReservationTest extends Unit
{
    private Station $station;

    private HourBoundaryPlanner $planner;

    private Module $testsModule;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
    }

    protected function _before(): void
    {
        $this->station = $this->persistStation($this->testsModule->em);
        $this->planner = new HourBoundaryPlanner(
            $this->testsModule->container->get(StationQueueRepository::class),
        );
    }

    protected function _after(): void
    {
        $this->removeStation($this->testsModule->em, $this->station);
    }

    public function testNoReservationOutsideProtectedWindow(): void
    {
        $this->enableTopOfHour();

        self::assertNull(
            $this->planner->getTopOfHourPlanningReservationEnd(
                $this->station,
                CarbonImmutable::parse('2026-05-26 09:57:30', 'UTC'),
            )
        );
    }

    public function testReservationAdvancesPlanningClockToNextHour(): void
    {
        $this->enableTopOfHour();

        $reservationEnd = $this->planner->getTopOfHourPlanningReservationEnd(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC'),
        );

        self::assertNotNull($reservationEnd);
        self::assertSame('2026-05-26 10:00:00', $reservationEnd->format('Y-m-d H:i:s'));
    }

    public function testReservationIncludesCrossfadePlanningOverlap(): void
    {
        $this->enableTopOfHour();

        $config = $this->station->backend_config;
        $config->crossfade_type = CrossfadeModes::Normal->value;
        $config->crossfade = 2.0;
        $this->station->backend_config = $config;

        $reservationEnd = $this->planner->getTopOfHourPlanningReservationEnd(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:57:57', 'UTC'),
        );

        self::assertNotNull($reservationEnd);
        self::assertSame('2026-05-26 10:00:00', $reservationEnd->format('Y-m-d H:i:s'));
    }

    public function testReservationIsDisabledWithTopOfHourProtection(): void
    {
        self::assertNull(
            $this->planner->getTopOfHourPlanningReservationEnd(
                $this->station,
                CarbonImmutable::parse('2026-05-26 09:58:30', 'UTC'),
            )
        );
    }

    private function enableTopOfHour(): void
    {
        $config = $this->station->backend_config;
        $config->top_of_hour_id_enabled = true;
        $config->top_of_hour_lookahead_minutes = 10;
        $config->top_of_hour_finish_buffer_seconds = 15;
        $config->top_of_hour_id_max_seconds = 60;
        $this->station->backend_config = $config;

        $this->testsModule->em->persist($this->station);
        $this->testsModule->em->flush();
    }

    private function persistStation(ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Top of Hour Planning Test';
        $station->short_name = 'toh_plan_' . substr(uniqid('', true), -8);
        $station->timezone = 'UTC';
        $station->ensureDirectoriesExist();

        $em->persist($station->media_storage_location);
        $em->persist($station->recordings_storage_location);
        $em->persist($station->podcasts_storage_location);
        $em->persist($station);
        $em->flush();

        return $station;
    }

    private function removeStation(ReloadableEntityManagerInterface $em, Station $station): void
    {
        if (!$em->isOpen()) {
            $em->open();
        }

        $em->createQuery('DELETE FROM App\\Entity\\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $em->remove($station);
        $em->remove($station->media_storage_location);
        $em->remove($station->recordings_storage_location);
        $em->remove($station->podcasts_storage_location);
        $em->flush();
    }
}
