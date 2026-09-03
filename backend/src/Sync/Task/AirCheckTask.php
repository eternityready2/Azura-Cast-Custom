<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Controller\Api\Stations\Features\FeatureSuiteController;
use App\Service\AirCheckDiagnosticsRecorder;
use App\Service\AirCheckHealthMonitor;
use Psr\SimpleCache\CacheInterface;

final class AirCheckTask extends AbstractTask
{
    private const int HEALTH_STATE_TTL = 604800;

    public function __construct(
        private readonly FeatureSuiteController $featureSuiteController,
        private readonly AirCheckDiagnosticsRecorder $diagnosticsRecorder,
        private readonly AirCheckHealthMonitor $healthMonitor,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$force && !$station->backend_config->aircheck_enabled) {
                continue;
            }

            $result = $this->featureSuiteController->runAirCheck($station);
            if (!($result['checked'] ?? false)) {
                continue;
            }

            $this->diagnosticsRecorder->recordRecoveryResult($station, $result);

            $health = $this->healthMonitor->getSnapshot($station);
            foreach ((array)($health['system_services'] ?? []) as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $key = (string)($service['key'] ?? 'unknown');
                $running = $service['running'] ?? null;
                if (!is_bool($running)) {
                    continue;
                }

                $cacheKey = sprintf('aircheck_system_health_%d_%s', $station->id, $key);
                $previousState = $this->cache->get($cacheKey);
                $previousState = is_bool($previousState) ? $previousState : null;

                $this->diagnosticsRecorder->recordSystemServiceTransition(
                    $station,
                    $service,
                    $previousState
                );

                $this->cache->set($cacheKey, $running, self::HEALTH_STATE_TTL);
            }
        }
    }
}
