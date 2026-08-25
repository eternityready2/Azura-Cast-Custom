<?php

declare(strict_types=1);

namespace Unit;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Tests\Module;
use Carbon\CarbonImmutable;
use Codeception\Test\Unit;

final class TopOfHourQueueBarrierTest extends Unit
{
    private Station $station;

    private StationQueueRepository $queueRepo;

    private Module $testsModule;

    protected function _inject(Module $testsModule): void
    {
        $this->testsModule = $testsModule;
    }

    protected function _before(): void
    {
        $this->station = $this->persistStation($this->testsModule->em);
        $this->queueRepo = $this->testsModule->container->get(StationQueueRepository::class);
    }

    protected function _after(): void
    {
        $this->removeStation($this->testsModule->em, $this->station);
    }

    public function testNormalAutoDjStopsAtPlannedLegalIdUntilItIsConsumed(): void
    {
        $before = $this->makeQueueRow('Artist - Before', '2026-05-26 09:55:00');
        $legalId = $this->makeQueueRow('Station - Legal ID', '2026-05-26 09:58:00');
        $legalId->top_of_hour_legal_id = true;
        $after = $this->makeQueueRow('Artist - After', '2026-05-26 09:58:40');

        $this->testsModule->em->persist($before);
        $this->testsModule->em->persist($legalId);
        $this->testsModule->em->persist($after);
        $this->testsModule->em->flush();

        self::assertSame($before->id, $this->queueRepo->getNextToSendToAutoDj($this->station)?->id);

        $before->sent_to_autodj = true;
        $this->testsModule->em->persist($before);
        $this->testsModule->em->flush();

        self::assertNull($this->queueRepo->getNextToSendToAutoDj($this->station));

        $planned = $this->queueRepo->findUnplayedTopOfHourLegalIdBetween(
            $this->station,
            CarbonImmutable::parse('2026-05-26 09:58:00', 'UTC'),
            CarbonImmutable::parse('2026-05-26 10:00:00', 'UTC'),
        );
        self::assertSame($legalId->id, $planned?->id);

        $legalId->is_played = true;
        $this->testsModule->em->persist($legalId);
        $this->testsModule->em->flush();

        self::assertSame($after->id, $this->queueRepo->getNextToSendToAutoDj($this->station)?->id);
    }

    private function makeQueueRow(string $songText, string $timestamp): StationQueue
    {
        $row = new StationQueue($this->station, Song::createFromText($songText));
        $row->timestamp_cued = CarbonImmutable::parse($timestamp, 'UTC');
        $row->timestamp_played = CarbonImmutable::parse($timestamp, 'UTC');
        $row->duration = 60.0;
        $row->is_played = false;
        $row->sent_to_autodj = false;

        return $row;
    }

    private function persistStation(ReloadableEntityManagerInterface $em): Station
    {
        $station = new Station();
        $station->name = 'TOH Queue Barrier Test';
        $station->short_name = 'toh_barrier_' . substr(uniqid('', true), -8);
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
