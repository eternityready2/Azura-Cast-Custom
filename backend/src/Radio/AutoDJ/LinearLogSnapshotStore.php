<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Station;
use Psr\SimpleCache\CacheInterface;

final class LinearLogSnapshotStore
{
    private const int CACHE_TTL = 172800;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Station $station): array
    {
        $snapshot = $this->cache->get($this->getKey($station));

        if (!is_array($snapshot)) {
            return $this->emptySnapshot($station);
        }

        return [
            ...$this->emptySnapshot($station),
            ...$snapshot,
        ];
    }

    public function markQueued(Station $station, int $hours): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'queued';
        $snapshot['hours'] = $hours;
        $snapshot['requested_at'] = time();
        $snapshot['error'] = null;

        $this->save($station, $snapshot);
    }

    public function markBuilding(Station $station, int $hours): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'building';
        $snapshot['hours'] = $hours;
        $snapshot['started_at'] = time();
        $snapshot['error'] = null;

        $this->save($station, $snapshot);
    }

    public function cancelQueued(Station $station): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = null !== $snapshot['built_at'] ? 'ready' : 'idle';
        $snapshot['error'] = null;

        $this->save($station, $snapshot);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $gaps
     * @param list<array<string, mixed>> $aiDjShifts
     */
    public function storeReady(
        Station $station,
        int $hours,
        int $buildStartedAt,
        int $coverageStart,
        int $coverageEnd,
        array $entries,
        array $gaps,
        array $aiDjShifts,
    ): void {
        $this->save(
            $station,
            [
                'version' => 2,
                'station_id' => $station->id,
                'status' => 'ready',
                'hours' => $hours,
                'requested_at' => $this->get($station)['requested_at'],
                'started_at' => $buildStartedAt,
                'built_at' => time(),
                'coverage_start' => $coverageStart,
                'coverage_end' => $coverageEnd,
                'entries' => $entries,
                'gaps' => $gaps,
                'ai_dj_shifts' => $aiDjShifts,
                'error' => null,
            ]
        );
    }

    public function markFailed(Station $station, int $hours, string $error): void
    {
        $snapshot = $this->get($station);
        $snapshot['status'] = 'failed';
        $snapshot['hours'] = $hours;
        $snapshot['failed_at'] = time();
        $snapshot['error'] = $error;

        $this->save($station, $snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot(Station $station): array
    {
        return [
            'version' => 2,
            'station_id' => $station->id,
            'status' => 'idle',
            'hours' => $station->backend_config->linear_log_hours,
            'requested_at' => null,
            'started_at' => null,
            'built_at' => null,
            'failed_at' => null,
            'coverage_start' => null,
            'coverage_end' => null,
            'entries' => [],
            'gaps' => [],
            'ai_dj_shifts' => [],
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function save(Station $station, array $snapshot): void
    {
        $this->cache->set($this->getKey($station), $snapshot, self::CACHE_TTL);
    }

    private function getKey(Station $station): string
    {
        return 'linear_log_v2.station_' . $station->id;
    }
}
