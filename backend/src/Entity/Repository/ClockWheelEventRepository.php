<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Entity\ClockWheelEvent;
use App\Entity\Station;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClockWheelEvent>
 */
final class ClockWheelEventRepository extends ServiceEntityRepository
{
    public const int DEFAULT_RETENTION_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClockWheelEvent::class);
    }

    public function deleteOlderThan(Station $station, int $days = self::DEFAULT_RETENTION_DAYS): int
    {
        $cutoff = new \DateTimeImmutable('-' . $days . ' days', $station->getTimezoneObject());

        return (int)$this->getEntityManager()->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\ClockWheelEvent e
                WHERE e.station = :station AND e.event_timestamp < :cutoff
            DQL
        )->setParameter('station', $station)
            ->setParameter('cutoff', $cutoff)
            ->execute();
    }
}
