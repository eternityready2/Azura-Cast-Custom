<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Controller\Api\Stations\Features\FeatureSuiteController;

final class AirCheckTask extends AbstractTask
{
    public function __construct(
        private readonly FeatureSuiteController $featureSuiteController
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

            $this->featureSuiteController->runAirCheck($station);
        }
    }
}
