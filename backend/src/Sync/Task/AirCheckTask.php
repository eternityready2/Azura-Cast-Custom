<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Controller\Api\Stations\Features\FeatureSuiteController;
use App\Service\StationDiagnostics;

final class AirCheckTask extends AbstractTask
{
    public function __construct(
        private readonly FeatureSuiteController $featureSuiteController,
        private readonly StationDiagnostics $diagnostics,
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

            foreach ((array)($result['restarted'] ?? []) as $service) {
                $this->diagnostics->warning(
                    $station,
                    'AirCheck',
                    'AirCheck restarted a failed station service.',
                    ['service' => (string)$service]
                );
            }

            foreach ((array)($result['failures'] ?? []) as $failure) {
                $this->diagnostics->error(
                    $station,
                    'AirCheck',
                    'AirCheck could not complete a station recovery action.',
                    ['error' => (string)$failure]
                );
            }
        }
    }
}
