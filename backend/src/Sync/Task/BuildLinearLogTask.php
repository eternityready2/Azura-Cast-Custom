<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Radio\AutoDJ\LinearLogBuilder;
use Monolog\LogRecord;
use Throwable;

/**
 * Hourly top-up for any station with `linear_log_enabled` turned on: keeps its
 * playout log built out to `linear_log_hours` ahead of real time, on top of
 * whatever the live AutoDJ short lookahead is already doing. Off by default
 * per-station -- see StationBackendConfiguration::$linear_log_enabled.
 */
final class BuildLinearLogTask extends AbstractTask
{
    public function __construct(
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '7 * * * *';
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$station->backend_config->linear_log_enabled) {
                continue;
            }

            if (!$station->supportsAutoDjQueue()) {
                continue;
            }

            $this->logger->pushProcessor(
                function (LogRecord $record) use ($station) {
                    $record->extra['station'] = [
                        'id' => $station->id,
                        'name' => $station->name,
                    ];
                    return $record;
                }
            );

            try {
                $this->linearLogBuilder->build($station);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Linear log build failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            } finally {
                $this->logger->popProcessor();
            }
        }
    }
}
