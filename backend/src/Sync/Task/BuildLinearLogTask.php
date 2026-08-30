<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Radio\AutoDJ\LinearLogBuilder;
use Monolog\LogRecord;
use Throwable;

final class BuildLinearLogTask extends AbstractTask
{
    public function __construct(
        private readonly LinearLogBuilder $linearLogBuilder,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        // Every 12 hours (00:07 and 12:07). The build is incremental (it extends the
        // existing queue tail rather than rebuilding from scratch), so this only needs
        // to run often enough to keep the horizon topped up -- hourly was unnecessary
        // and, combined with the report page's own on-load build, made the AutoDJ
        // queue get rebuilt far more often than intended.
        return '7 */12 * * *';
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            if (!$force && !$station->backend_config->linear_log_enabled) {
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
