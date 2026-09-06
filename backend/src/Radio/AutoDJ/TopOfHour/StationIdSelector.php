<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\TopOfHour;

use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Selects only media explicitly tagged as a station ID.
 *
 * A top-of-hour ID is regulatory/station identity content, so this selector never
 * substitutes promos, liners or commercials. Rotation is least-recently-played
 * among IDs that fit completely inside minute :59.
 */
final class StationIdSelector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationQueueRepository $queueRepo,
    ) {
    }

    public function select(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $maxDurationSeconds,
    ): ?StationMedia {
        $candidates = $this->loadCandidates($station, $maxDurationSeconds);
        if ([] === $candidates) {
            return null;
        }

        $history = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            24 * 60,
        );

        $lastPlayed = [];
        foreach ($history as $row) {
            $songId = (string)($row['song_id'] ?? '');
            if ('' === $songId) {
                continue;
            }

            $timestamp = $row['timestamp_played'] ?? 0;
            if ($timestamp instanceof \DateTimeInterface) {
                $timestamp = $timestamp->getTimestamp();
            }
            $timestamp = (int)$timestamp;

            if (!isset($lastPlayed[$songId]) || $timestamp > $lastPlayed[$songId]) {
                $lastPlayed[$songId] = $timestamp;
            }
        }

        usort(
            $candidates,
            static function (StationMedia $a, StationMedia $b) use ($lastPlayed): int {
                $aLast = $lastPlayed[$a->song_id] ?? 0;
                $bLast = $lastPlayed[$b->song_id] ?? 0;

                return $aLast <=> $bLast ?: $a->id <=> $b->id;
            }
        );

        return $candidates[0];
    }

    /** @return list<StationMedia> */
    private function loadCandidates(Station $station, int $maxDurationSeconds): array
    {
        /** @var list<StationMedia> $media */
        $media = $this->em->createQuery(
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

        return array_values(array_filter(
            $media,
            static function (StationMedia $candidate) use ($maxDurationSeconds): bool {
                $length = $candidate->getCalculatedLength();
                return $length > 0.0 && $length <= (float)$maxDurationSeconds;
            }
        ));
    }
}
