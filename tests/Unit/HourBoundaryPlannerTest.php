<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Entity\Song;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;
use DateTimeZone;

final class HourBoundaryPlannerTest extends Unit
{
    private HourBoundaryPlanner $planner;

    private Station $station;

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

    public function testPlannedSecondsUsesMinuteAndSecondNotHourOfDay(): void
    {
        $expected = CarbonImmutable::parse('2026-05-26 10:30:15', 'UTC');
        $seconds = $this->planner->getPlannedSecondsIntoHour($this->station, $expected, new DateTimeZone('UTC'));

        self::assertSame(30 * 60 + 15, $seconds);
    }

    public function testPlannedSecondsAdvancesPastQueuedItemsInSameHour(): void
    {
        $em = $this->testsModule->em;
        $station = $this->persistStation($em);

        $queued = new StationQueue($station, Song::createFromText('Artist - Test'));
        $queued->timestamp_played = CarbonImmutable::parse('2026-05-26 09:50:00', 'UTC');
        $queued->duration = 600.0;
        $queued->sent_to_autodj = false;
        $queued->is_played = false;
        $queued->timestamp_cued = CarbonImmutable::parse('2026-05-26 09:49:00', 'UTC');
        $em->persist($queued);
        $em->flush();

        $planner = new HourBoundaryPlanner(
            $this->testsModule->container->get(StationQueueRepository::class),
        );

        try {
            $expected = CarbonImmutable::parse('2026-05-26 09:55:00', 'UTC');
            $seconds = $planner->getPlannedSecondsIntoHour($station, $expected, new DateTimeZone('UTC'));

            self::assertSame(3599, $seconds);
        } finally {
            $this->removeStation($em, $station);
        }
    }

    public function testIsInLookaheadZoneWhenEnabled(): void
    {
        $this->enableTopOfHour();

        $inZone = CarbonImmutable::parse('2026-05-26 09:55:00', 'UTC');
        $outOfZone = CarbonImmutable::parse('2026-05-26 09:40:00', 'UTC');

        self::assertTrue($this->planner->isInLookaheadZone($this->station, $inZone));
        self::assertFalse($this->planner->isInLookaheadZone($this->station, $outOfZone));
    }

    public function testLegalIdWindowBeginsAt58(): void
    {
        $this->enableTopOfHour();

        self::assertFalse($this->planner->isTopOfHourIdDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:57:59', 'UTC'),
        ));
        self::assertTrue($this->planner->isTopOfHourIdDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC'),
        ));
        self::assertTrue($this->planner->isTopOfHourIdDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:59:30', 'UTC'),
        ));
        self::assertFalse($this->planner->isTopOfHourIdDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC'),
        ));
    }

    public function testMaxMusicDurationStopsAtProtectedIdWindow(): void
    {
        $this->enableTopOfHour();

        $expectedPlayTime = CarbonImmutable::parse('2026-05-26 09:55:00', 'UTC');
        $maxDuration = $this->planner->maxMusicDurationBeforeTopOfHour($this->station, $expectedPlayTime);

        self::assertSame(180.0, $maxDuration);
    }

    public function testMusicCapHandsOffAt58(): void
    {
        $this->enableTopOfHour();

        $expectedPlayTime = CarbonImmutable::parse('2026-05-26 09:57:30', 'UTC');
        $maxDuration = $this->planner->maxMusicDurationBeforeTopOfHour($this->station, $expectedPlayTime);

        self::assertSame(30.0, $maxDuration);

        $nextExpectedPlayTime = $expectedPlayTime->addSeconds((int)$maxDuration);
        self::assertSame('2026-05-26 09:58:00', $nextExpectedPlayTime->format('Y-m-d H:i:s'));
        self::assertTrue($this->planner->isTopOfHourIdDue($this->station, $nextExpectedPlayTime));
    }

    public function testActualIdDurationCanUseMinute59WhenItStillFits(): void
    {
        $this->enableTopOfHour();

        self::assertTrue($this->planner->canLegalIdFinishBeforeTop(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:59:00', 'UTC'),
            37.83,
        ));
        self::assertFalse($this->planner->canLegalIdFinishBeforeTop(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:59:08', 'UTC'),
            37.83,
        ));
    }

    public function testInterruptFallbackNeverMovesIntoNextHour(): void
    {
        $this->enableTopOfHour();

        self::assertFalse($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:57:59', 'UTC'),
        ));
        self::assertTrue($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC'),
        ));
        self::assertTrue($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:59:59', 'UTC'),
        ));
        self::assertFalse($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC'),
        ));
        self::assertFalse($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 10:00:10', 'UTC'),
        ));
    }

    public function testTopOfHourInterruptIsNotQueuedTwiceForSameBoundary(): void
    {
        $this->enableTopOfHour();

        $queued = new StationQueue($this->station, Song::createFromText('Station - Legal ID'));
        $queued->top_of_hour_legal_id = true;
        $queued->timestamp_cued = CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC');
        $queued->timestamp_played = CarbonImmutable::parse('2026-05-26 09:58:05', 'UTC');
        $queued->is_played = true;
        $this->testsModule->em->persist($queued);
        $this->testsModule->em->flush();

        self::assertFalse($this->planner->isTopOfHourInterruptDue(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:59:00', 'UTC'),
        ));
    }

    public function testShouldSuppressOncePerHourPlaylistAtTopOfHour(): void
    {
        $playlist = new StationPlaylist($this->station);
        $playlist->type = PlaylistTypes::OncePerHour;
        $playlist->play_per_hour_minute = 0;

        self::assertFalse($this->planner->shouldSuppressOncePerHourPlaylist($playlist));

        $backendConfig = $this->station->backend_config;
        $backendConfig->top_of_hour_id_enabled = true;
        $this->station->backend_config = $backendConfig;

        self::assertTrue($this->planner->shouldSuppressOncePerHourPlaylist($playlist));

        $playlist->play_per_hour_minute = 30;
        self::assertFalse($this->planner->shouldSuppressOncePerHourPlaylist($playlist));
    }

    private function enableTopOfHour(): void
    {
        $backendConfig = $this->station->backend_config;
        $backendConfig->top_of_hour_id_enabled = true;
        $backendConfig->top_of_hour_lookahead_minutes = 10;
        $backendConfig->top_of_hour_finish_buffer_seconds = 15;
        $backendConfig->top_of_hour_id_max_seconds = 60;
        $this->station->backend_config = $backendConfig;
        $this->testsModule->em->persist($this->station);
        $this->testsModule->em->flush();
    }

    private function persistStation(ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'Hour Boundary Test';
        $station->short_name = 'hour_boundary_' . substr(uniqid('', true), -8);
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

        $em->createQuery('DELETE FROM App\Entity\StationQueue sq WHERE sq.station = :station')
            ->setParameter('station', $station)
            ->execute();

        $em->remove($station);
        $em->remove($station->media_storage_location);
        $em->remove($station->recordings_storage_location);
        $em->remove($station->podcasts_storage_location);
        $em->flush();
    }
}
