<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use DateTimeImmutable;
use DateTimeInterface;

final class AiDjScheduler
{
    public function __construct(
        private readonly AiDjScheduleRepository $scheduleRepo,
        private readonly StationRepository $stationRepo,
    ) {
    }

    public function findActiveDj(int $stationId, ?DateTimeInterface $timestamp = null): ?AiDj
    {
        return $this->findActiveSchedule($stationId, $timestamp)?->getAiDj();
    }

    public function findActiveSchedule(
        int $stationId,
        ?DateTimeInterface $timestamp = null,
    ): ?AiDjSchedule {
        $station = $this->stationRepo->find($stationId);
        if (null === $station) {
            return null;
        }

        $stationTime = $this->getStationTime($station, $timestamp);

        return $this->scheduleRepo->findActiveForTimeSlot(
            $stationId,
            (int)$stationTime->format('N'),
            $stationTime->format('H:i:s'),
        );
    }

    /**
     * Resolve the concrete start/end timestamps for one occurrence of a repeating
     * AI DJ schedule. The returned dates are in the station timezone.
     *
     * @return array{starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}
     */
    public function getShiftWindow(
        Station $station,
        AiDjSchedule $schedule,
        DateTimeInterface $timestamp,
    ): array {
        $stationTime = DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone($station->getTimezoneObject());

        $startParts = array_map('intval', explode(':', $schedule->getStartTime()->format('H:i:s')));
        $endParts = array_map('intval', explode(':', $schedule->getEndTime()->format('H:i:s')));

        $day = $stationTime->setTime(0, 0, 0);
        $startsAt = $day->setTime($startParts[0], $startParts[1], $startParts[2]);
        $endsAt = $day->setTime($endParts[0], $endParts[1], $endParts[2]);

        if ($endsAt <= $startsAt) {
            if ($stationTime < $endsAt) {
                $startsAt = $startsAt->modify('-1 day');
            } else {
                $endsAt = $endsAt->modify('+1 day');
            }
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    private function getStationTime(
        Station $station,
        ?DateTimeInterface $timestamp,
    ): DateTimeImmutable {
        $now = null !== $timestamp
            ? DateTimeImmutable::createFromInterface($timestamp)
            : new DateTimeImmutable('now');

        return $now->setTimezone($station->getTimezoneObject());
    }
}
