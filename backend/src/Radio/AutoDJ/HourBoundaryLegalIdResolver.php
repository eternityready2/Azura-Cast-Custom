<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
use App\Radio\AutoDJ\DuplicatePrevention;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves and queues mandatory legal_id media for station-wide top-of-hour protection.
 */
final class HourBoundaryLegalIdResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function resolveMandatoryLegalId(
        Station $station,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
    ): ?StationQueue {
        $legalIdExpectedAt = $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $usedSubstitute = false;

        $candidates = $this->loadStationIdCandidates($station);

        if ($candidates === []) {
            $candidates = $this->loadMediaCandidates($station, ClockWheelSlotTypes::Promo);
            $usedSubstitute = true;
        }

        if ($candidates === []) {
            $this->logger->error(
                'Top-of-hour ID: no id or promo media available.',
                ['station_id' => $station->id]
            );

            return null;
        }

        $maxDuration = (float)min(120, $this->hourBoundaryPlanner->getIdMaxSeconds($station));
        $allCandidates = $candidates;
        $candidates = $this->filterByDuration($allCandidates, $maxDuration);

        if ($candidates === [] && $allCandidates !== []) {
            usort(
                $allCandidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $a->getCalculatedLength() <=> $b->getCalculatedLength()
            );
            $candidates = [$allCandidates[0]];
        }

        if ($candidates === []) {
            return null;
        }

        $mediaQueue = [];
        foreach ($candidates as $media) {
            $q = new StationPlaylistQueue();
            $q->media_id = $media->id;
            $q->spm_id = 0;
            $q->song_id = $media->song_id;
            $q->artist = $media->artist ?? '';
            $q->title = $media->title ?? '';
            $mediaQueue[] = $q;
        }

        $mediaQueue = $this->applySequentialAlgorithm($mediaQueue, $candidates, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true)
            ?? $mediaQueue[0] ?? null;

        if ($validTrack === null) {
            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->top_of_hour_legal_id = true;
        $queueEntry->clock_wheel_legal_id_substitute = $usedSubstitute;
        $this->em->persist($queueEntry);

        $this->eventLogger->recordTopOfHourLegalIdQueued(
            $station,
            $media,
            $legalIdExpectedAt,
            $queueEntry,
        );

        if ($usedSubstitute) {
            $this->eventLogger->recordTopOfHourFallback(
                $station,
                $legalIdExpectedAt,
                ClockWheelFallbackReason::LegalIdMissingUsedPromo,
            );
        }

        $this->logger->info('Top-of-hour legal_id queued.', [
            'station_id' => $station->id,
            'media_id' => $media->id,
            'substitute' => $usedSubstitute,
            'expected_top_of_hour' => $legalIdExpectedAt->format(DateTimeImmutable::ATOM),
        ]);

        return $queueEntry;
    }

    /**
     * @return StationMedia[]
     */
    private function loadStationIdCandidates(Station $station): array
    {
        /** @var StationMedia[] $result */
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT m FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation
                AND m.type IN (:types)
                ORDER BY m.id ASC
            DQL
        )->setParameters([
            'storageLocation' => $station->media_storage_location,
            'types' => StationMediaTypes::stationIdTypeValues(),
        ])->getResult();

        return $result;
    }

    /**
     * @return StationMedia[]
     */
    private function loadMediaCandidates(Station $station, ClockWheelSlotTypes $type): array
    {
        /** @var StationMedia[] $result */
        $result = $this->em->createQuery(
            <<<'DQL'
                SELECT m FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation
                AND m.type = :type
                ORDER BY m.id ASC
            DQL
        )->setParameters([
            'storageLocation' => $station->media_storage_location,
            'type' => $type->value,
        ])->getResult();

        return $result;
    }

    /**
     * @param StationMedia[] $candidates
     *
     * @return StationMedia[]
     */
    private function filterByDuration(array $candidates, float $maxDuration): array
    {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            return $fitting;
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static fn (StationMedia $a, StationMedia $b): int =>
                $a->getCalculatedLength() <=> $b->getCalculatedLength()
        );

        return [$candidates[0]];
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return StationPlaylistQueue[]
     */
    private function applySequentialAlgorithm(
        array $mediaQueue,
        array $candidates,
        array $recentHistory,
    ): array {
        $histTimestamp = [];
        foreach ($recentHistory as $h) {
            $songId = $h['song_id'];
            $ts = $h['timestamp_played'];
            if ($ts instanceof \DateTimeInterface) {
                $ts = $ts->getTimestamp();
            }
            $ts = (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
        }

        $candidateBySongId = [];
        foreach ($candidates as $media) {
            $candidateBySongId[$media->song_id] = $media;
        }

        usort(
            $mediaQueue,
            static function (StationPlaylistQueue $a, StationPlaylistQueue $b) use ($histTimestamp, $candidateBySongId): int {
                $aTs = $histTimestamp[$a->song_id] ?? 0;
                $bTs = $histTimestamp[$b->song_id] ?? 0;

                if ($aTs !== $bTs) {
                    return $aTs <=> $bTs;
                }

                $aId = $candidateBySongId[$a->song_id]->id ?? 0;
                $bId = $candidateBySongId[$b->song_id]->id ?? 0;

                return $aId <=> $bId;
            }
        );

        return $mediaQueue;
    }
}
