<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Container\LoggerAwareTrait;
use App\Doctrine\Platform\MariaDbPlatform;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Doctrine\Repository;
use App\Entity\Listener;
use App\Entity\Station;
use App\Entity\Traits\TruncateStrings;
use App\Service\DeviceDetector;
use App\Service\IpGeolocation;
use App\Utilities\File;
use App\Utilities\Time;
use DateTimeImmutable;
use DI\Attribute\Inject;
use Doctrine\DBAL\Connection;
use League\Csv\Writer;
use NowPlaying\Result\Client;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * @extends Repository<Listener>
 */
final class ListenerRepository extends Repository
{
    use LoggerAwareTrait;
    use TruncateStrings;

    protected string $entityClass = Listener::class;

    private string $tableName;

    private Connection $conn;

    public function __construct(
        private readonly DeviceDetector $deviceDetector,
        private readonly IpGeolocation $ipGeolocation
    ) {
    }

    #[Inject]
    public function setEntityManager(ReloadableEntityManagerInterface $em): void
    {
        parent::setEntityManager($em);

        $this->tableName = $this->em->getClassMetadata(Listener::class)->getTableName();
        $this->conn = $this->em->getConnection();
    }

    /**
     * Get the number of unique listeners for a station during a specified time period.
     *
     * @param Station $station
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     * @return int
     */
    public function getUniqueListeners(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $excludeBots = false,
    ): int {
        $dql = <<<'DQL'
                SELECT COUNT(DISTINCT l.listener_hash)
                FROM App\Entity\Listener l
                WHERE l.station = :station
                AND l.timestamp_start <= :time_end
                AND l.timestamp_end >= :time_start
            DQL;

        if ($excludeBots) {
            $dql .= ' AND l.device.is_bot = 0';
        }

        return (int)$this->em->createQuery($dql)
            ->setParameter('station', $station)
            ->setParameter('time_end', $end)
            ->setParameter('time_start', $start)
            ->getSingleScalarResult();
    }

    /**
     * @return array{
     *     total_sessions: int,
     *     human_sessions: int,
     *     bot_sessions: int,
     *     bot_percent: float|null
     * }
     */
    public function getSessionBreakdown(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $statsRaw = $this->conn->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(l.id) AS total_sessions,
                       SUM(CASE WHEN l.device_is_bot = 1 THEN 1 ELSE 0 END) AS bot_sessions
                FROM listener l
                WHERE l.station_id = :station_id
                AND l.timestamp_start <= :time_end
                AND l.timestamp_end >= :time_start
            SQL,
            [
                'station_id' => $station->id,
                'time_end' => $end,
                'time_start' => $start,
            ],
        );

        $total = (int)($statsRaw['total_sessions'] ?? 0);
        $bots = (int)($statsRaw['bot_sessions'] ?? 0);
        $human = max(0, $total - $bots);

        return [
            'total_sessions' => $total,
            'human_sessions' => $human,
            'bot_sessions' => $bots,
            'bot_percent' => $total > 0 ? round(($bots / $total) * 100, 1) : null,
        ];
    }

    /**
     * @return array{
     *     unique_listeners: int,
     *     repeat_listeners: int,
     *     loyalty_rate_percent: float|null,
     *     avg_sessions_per_listener: float|null
     * }
     */
    public function getListenerLoyaltyStats(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $excludeBots = false,
    ): array {
        $botFilter = $excludeBots ? ' AND l.device_is_bot = 0' : '';

        $rows = $this->conn->fetchAllAssociative(
            <<<SQL
                SELECT l.listener_hash, COUNT(l.id) AS session_count
                FROM listener l
                WHERE l.station_id = :station_id
                AND l.timestamp_start <= :time_end
                AND l.timestamp_end >= :time_start
                {$botFilter}
                GROUP BY l.listener_hash
            SQL,
            [
                'station_id' => $station->id,
                'time_end' => $end,
                'time_start' => $start,
            ],
        );

        $unique = count($rows);
        $repeat = 0;
        $totalSessions = 0;

        foreach ($rows as $row) {
            $sessions = (int)$row['session_count'];
            $totalSessions += $sessions;
            if ($sessions > 1) {
                $repeat++;
            }
        }

        return [
            'unique_listeners' => $unique,
            'repeat_listeners' => $repeat,
            'loyalty_rate_percent' => $unique > 0 ? round(($repeat / $unique) * 100, 1) : null,
            'avg_sessions_per_listener' => $unique > 0
                ? round($totalSessions / $unique, 2)
                : null,
        ];
    }

    /**
     * @return array<int, array{hour: int, first_period: int, second_period: int, growth_percent: float|null}>
     */
    public function getHourlyGrowthTrend(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $excludeBots = false,
    ): array {
        $midpoint = $start->add(
            new \DateInterval('PT' . (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 2) . 'S'),
        );

        $botFilter = $excludeBots ? ' AND l.device_is_bot = 0' : '';
        $stationId = $station->id;

        $tz = $station->getTimezoneObject();
        $tzOffset = $tz->getOffset($start);
        $tzFormatted = sprintf(
            '%+03d:%02d',
            intdiv($tzOffset, 3600),
            abs(intdiv($tzOffset % 3600, 60)),
        );

        $fetchWithTz = function (DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd) use (
            $stationId,
            $botFilter,
            $tzFormatted,
        ): array {
            $rows = $this->conn->fetchAllAssociative(
                <<<SQL
                    SELECT HOUR(CONVERT_TZ(l.timestamp_start, '+00:00', :tz)) AS hour,
                           COUNT(DISTINCT l.listener_hash) AS listeners
                    FROM listener l
                    WHERE l.station_id = :station_id
                    AND l.timestamp_start <= :time_end
                    AND l.timestamp_end >= :time_start
                    {$botFilter}
                    GROUP BY hour
                SQL,
                [
                    'station_id' => $stationId,
                    'time_start' => $periodStart,
                    'time_end' => $periodEnd,
                    'tz' => $tzFormatted,
                ],
            );

            $byHour = array_fill(0, 24, 0);
            foreach ($rows as $row) {
                $hour = (int)$row['hour'];
                if ($hour >= 0 && $hour <= 23) {
                    $byHour[$hour] = (int)$row['listeners'];
                }
            }

            return $byHour;
        };

        $firstPeriod = $fetchWithTz($start, $midpoint);
        $secondPeriod = $fetchWithTz($midpoint, $end);

        $trend = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $first = $firstPeriod[$hour];
            $second = $secondPeriod[$hour];
            $growth = null;
            if ($first > 0) {
                $growth = round((($second - $first) / $first) * 100, 1);
            } elseif ($second > 0) {
                $growth = 100.0;
            }

            $trend[] = [
                'hour' => $hour,
                'first_period' => $first,
                'second_period' => $second,
                'growth_percent' => $growth,
            ];
        }

        return $trend;
    }

    /**
     * @return array{
     *     total_sessions: int,
     *     checkpoints: array<int, array{minute: int, listeners: int, percent: float|null}>
     * }
     */
    public function getRetentionCurve(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $excludeBots = false,
    ): array {
        $botFilter = $excludeBots ? ' AND l.device_is_bot = 0' : '';

        $rows = $this->conn->fetchAllAssociative(
            <<<SQL
                SELECT TIMESTAMPDIFF(SECOND, l.timestamp_start, l.timestamp_end) AS duration_seconds
                FROM listener l
                WHERE l.station_id = :station_id
                AND l.timestamp_end IS NOT NULL
                AND l.timestamp_start <= :time_end
                AND l.timestamp_end >= :time_start
                {$botFilter}
            SQL,
            [
                'station_id' => $station->id,
                'time_start' => $start,
                'time_end' => $end,
            ],
        );

        $total = count($rows);
        $checkpoints = [1, 5, 10, 15, 30, 45, 60, 90, 120];
        $curve = [];

        foreach ($checkpoints as $minute) {
            $threshold = $minute * 60;
            $surviving = 0;

            foreach ($rows as $row) {
                if ((int)$row['duration_seconds'] >= $threshold) {
                    $surviving++;
                }
            }

            $curve[] = [
                'minute' => $minute,
                'listeners' => $surviving,
                'percent' => $total > 0 ? round(($surviving / $total) * 100, 1) : null,
            ];
        }

        return [
            'total_sessions' => $total,
            'checkpoints' => $curve,
        ];
    }

    /**
     * @param array<int, array{
     *     id: int,
     *     name: string,
     *     start_hour: int,
     *     end_hour: int,
     *     is_active: bool
     * }> $dayparts
     *
     * @return array<int, array{
     *     daypart_id: int,
     *     name: string,
     *     start_hour: int,
     *     end_hour: int,
     *     unique_listeners: int,
     *     session_count: int,
     *     avg_session_seconds: float|null
     * }>
     */
    public function getDaypartAudienceStats(
        Station $station,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $dayparts,
        bool $excludeBots = false,
    ): array {
        if ($dayparts === []) {
            return [];
        }

        $botFilter = $excludeBots ? ' AND l.device_is_bot = 0' : '';
        $tz = $station->getTimezoneObject();
        $tzOffset = $tz->getOffset($start);
        $tzFormatted = sprintf(
            '%+03d:%02d',
            intdiv($tzOffset, 3600),
            abs(intdiv($tzOffset % 3600, 60)),
        );

        $results = [];

        foreach ($dayparts as $daypart) {
            $hours = $this->hoursInDaypartRange(
                (int)$daypart['start_hour'],
                (int)$daypart['end_hour'],
            );

            if ($hours === []) {
                continue;
            }

            $hourList = implode(',', $hours);

            $stats = $this->conn->fetchAssociative(
                <<<SQL
                    SELECT COUNT(l.id) AS session_count,
                           COUNT(DISTINCT l.listener_hash) AS unique_listeners,
                           AVG(TIMESTAMPDIFF(SECOND, l.timestamp_start, l.timestamp_end)) AS avg_seconds
                    FROM listener l
                    WHERE l.station_id = :station_id
                    AND l.timestamp_end IS NOT NULL
                    AND l.timestamp_start <= :time_end
                    AND l.timestamp_end >= :time_start
                    AND HOUR(CONVERT_TZ(l.timestamp_start, '+00:00', :tz)) IN ({$hourList})
                    {$botFilter}
                SQL,
                [
                    'station_id' => $station->id,
                    'time_start' => $start,
                    'time_end' => $end,
                    'tz' => $tzFormatted,
                ],
            );

            $results[] = [
                'daypart_id' => (int)$daypart['id'],
                'name' => $daypart['name'],
                'start_hour' => (int)$daypart['start_hour'],
                'end_hour' => (int)$daypart['end_hour'],
                'unique_listeners' => (int)($stats['unique_listeners'] ?? 0),
                'session_count' => (int)($stats['session_count'] ?? 0),
                'avg_session_seconds' => is_numeric($stats['avg_seconds'] ?? null)
                    ? round((float)$stats['avg_seconds'], 1)
                    : null,
            ];
        }

        usort(
            $results,
            static fn (array $a, array $b): int => $b['unique_listeners'] <=> $a['unique_listeners'],
        );

        return $results;
    }

    /**
     * @return int[]
     */
    private function hoursInDaypartRange(int $startHour, int $endHour): array
    {
        $hours = [];

        if ($startHour <= $endHour) {
            for ($h = $startHour; $h <= $endHour; $h++) {
                $hours[] = $h;
            }
        } else {
            for ($h = $startHour; $h <= 23; $h++) {
                $hours[] = $h;
            }
            for ($h = 0; $h <= $endHour; $h++) {
                $hours[] = $h;
            }
        }

        return $hours;
    }

    public function iterateLiveListenersArray(Station $station): iterable
    {
        $query = $this->em->createQuery(
            <<<'DQL'
                SELECT
                    l,
                    IDENTITY(l.mount) AS mount_id,
                    IDENTITY(l.remote) AS remote_id,
                    IDENTITY(l.hls_stream) AS hls_stream_id
                FROM App\Entity\Listener l
                WHERE l.station = :station
                AND l.timestamp_end IS NULL
                ORDER BY l.timestamp_start ASC
            DQL
        )->setParameter('station', $station);

        foreach ($query->toIterable([], $query::HYDRATE_ARRAY) as $row) {
            yield [
                ...$row[0],
                'mount_id' => $row['mount_id'],
                'remote_id' => $row['remote_id'],
                'hls_stream_id' => $row['hls_stream_id'],
            ];
        }
    }

    /**
     * Update listener data for a station.
     *
     * @param Station $station
     * @param Client[] $clients
     */
    public function update(Station $station, array $clients): void
    {
        $this->em->wrapInTransaction(
            function () use ($station, $clients): void {
                $existingClientsRaw = $this->em->createQuery(
                    <<<'DQL'
                        SELECT l.id, l.listener_hash
                        FROM App\Entity\Listener l
                        WHERE l.station = :station
                        AND l.timestamp_end IS NULL
                    DQL
                )->setParameter('station', $station);

                $existingClientsIterator = $existingClientsRaw->toIterable([], $existingClientsRaw::HYDRATE_ARRAY);
                $existingClients = [];
                foreach ($existingClientsIterator as $client) {
                    $existingClients[$client['listener_hash']] = $client['id'];
                }

                $this->batchAddClients(
                    $station,
                    $clients,
                    $existingClients
                );

                // Mark the end of all other clients on this station.
                if (!empty($existingClients)) {
                    $this->em->createQuery(
                        <<<'DQL'
                            UPDATE App\Entity\Listener l
                            SET l.timestamp_end = :time
                            WHERE l.id IN (:ids)
                        DQL
                    )->setParameter('time', Time::nowUtc())
                        ->setParameter('ids', array_values($existingClients))
                        ->execute();
                }
            }
        );
    }

    private function batchAddClients(
        Station $station,
        array &$clients,
        array &$existingClients
    ): void {
        $tempCsvPath = File::generateTempPath('mariadb_listeners.csv');
        new Filesystem()->chmod($tempCsvPath, 0o777);

        $csv = Writer::from($tempCsvPath);
        $csv->setEscape('');
        $csv->addFormatter(function ($row) {
            return array_map(function ($col) {
                if (null === $col) {
                    return '\N';
                }

                return is_string($col)
                    ? str_replace('"', '""', $col)
                    : $col;
            }, $row);
        });

        $csvColumns = null;
        $now = Time::nowUtc()->format(MariaDbPlatform::DB_DATETIME_FORMAT);

        foreach ($clients as $client) {
            $identifier = Listener::calculateListenerHash($client);

            // Check for an existing record for this client.
            if (isset($existingClients[$identifier])) {
                unset($existingClients[$identifier]);
            } else {
                // Create a new record.
                $record = $this->batchAddRow($station, $client, $now);

                if (null === $csvColumns) {
                    $csvColumns = array_keys($record);
                }

                $csv->insertOne($record);
            }
        }

        if (null === $csvColumns) {
            @unlink($tempCsvPath);
            return;
        }

        // Use LOAD DATA INFILE for listener dumps
        $csvLoadQuery = sprintf(
            <<<'SQL'
                LOAD DATA LOCAL INFILE %s IGNORE
                INTO TABLE %s
                FIELDS TERMINATED BY ','
                OPTIONALLY ENCLOSED BY '"'
                LINES TERMINATED BY '\n'
                (%s)
            SQL,
            $this->conn->quote($tempCsvPath),
            $this->conn->quoteSingleIdentifier($this->tableName),
            implode(
                ',',
                array_map(
                    fn($col) => $this->conn->quoteSingleIdentifier($col),
                    $csvColumns
                )
            )
        );

        try {
            $this->conn->executeQuery($csvLoadQuery);
        } finally {
            @unlink($tempCsvPath);
        }

        $this->deviceDetector->saveCache();
        $this->ipGeolocation->saveCache();
    }

    private function batchAddRow(Station $station, Client $client, string $now): array
    {
        $record = [
            'station_id' => $station->id,
            'timestamp_start' => $now,
            'listener_uid' => (int)$client->uid,
            'listener_user_agent' => $this->truncateString($client->userAgent ?? ''),
            'listener_ip' => $client->ip,
            'listener_hash' => Listener::calculateListenerHash($client),
            'mount_id' => null,
            'remote_id' => null,
            'hls_stream_id' => null,
            'device_client' => null,
            'device_is_browser' => null,
            'device_is_mobile' => null,
            'device_is_bot' => null,
            'device_browser_family' => null,
            'device_os_family' => null,
            'location_description' => null,
            'location_region' => null,
            'location_city' => null,
            'location_country' => null,
            'location_lat' => null,
            'location_lon' => null,
        ];

        if (!empty($client->mount)) {
            [$mountType, $mountId] = explode('_', $client->mount, 2);

            if ('local' === $mountType) {
                $record['mount_id'] = (int)$mountId;
            } elseif ('remote' === $mountType) {
                $record['remote_id'] = (int)$mountId;
            } elseif ('hls' === $mountType) {
                $record['hls_stream_id'] = (int)$mountId;
            }
        }

        $this->batchAddDeviceDetails($record);
        $this->batchAddLocationDetails($record);

        return $record;
    }

    private function batchAddDeviceDetails(array &$record): void
    {
        $userAgent = $record['listener_user_agent'];

        try {
            $browserResult = $this->deviceDetector->parse($userAgent);

            $record['device_client'] = $this->truncateNullableString($browserResult->client);
            $record['device_is_browser'] = $browserResult->isBrowser ? 1 : 0;
            $record['device_is_mobile'] = $browserResult->isMobile ? 1 : 0;
            $record['device_is_bot'] = $browserResult->isBot ? 1 : 0;
            $record['device_browser_family'] = $this->truncateNullableString($browserResult->browserFamily, 150);
            $record['device_os_family'] = $this->truncateNullableString($browserResult->osFamily, 150);
        } catch (Throwable $e) {
            $this->logger->error('Device Detector error: ' . $e->getMessage(), [
                'user_agent' => $userAgent,
                'exception' => $e,
            ]);
        }
    }

    private function batchAddLocationDetails(array &$record): void
    {
        $ip = $record['listener_ip'];

        try {
            $ipInfo = $this->ipGeolocation->getLocationInfo($ip);

            $record['location_description'] = $this->truncateString($ipInfo->description);
            $record['location_region'] = $this->truncateNullableString($ipInfo->region, 150);
            $record['location_city'] = $this->truncateNullableString($ipInfo->city, 150);
            $record['location_country'] = $this->truncateNullableString($ipInfo->country, 2);
            $record['location_lat'] = $ipInfo->lat;
            $record['location_lon'] = $ipInfo->lon;
        } catch (Throwable $e) {
            $this->logger->error('IP Geolocation error: ' . $e->getMessage(), [
                'ip' => $ip,
                'exception' => $e,
            ]);

            $record['location_description'] = 'Unknown';
        }
    }

    public function clearAll(): void
    {
        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\Listener l
            DQL
        )->execute();
    }

    public function cleanup(int $daysToKeep): void
    {
        $threshold = Time::nowUtc()->subDays($daysToKeep);

        $this->em->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\Listener sh
                WHERE sh.timestamp_start <= :threshold
            DQL
        )->setParameter('threshold', $threshold)
            ->execute();
    }
}
