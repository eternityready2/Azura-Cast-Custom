<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use App\Service\StationDiagnostics;
use Monolog\LogRecord;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

final class BuildLinearLogTask extends AbstractTask
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '7 */12 * * *';
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

            $hours = $station->backend_config->linear_log_hours;

            try {
                $this->snapshotStore->markQueued($station, $hours);
                $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours, $force));
            } catch (Throwable $e) {
                $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
                $this->logger->error(
                    'Unable to queue Linear Log build: ' . $e->getMessage(),
                    ['exception' => $e]
                );
                $this->diagnostics->error(
                    $station,
                    'Linear Log',
                    'Unable to queue Linear Log build.',
                    [
                        'hours' => $hours,
                        'error' => $e->getMessage(),
                    ]
                );
            } finally {
                $this->logger->popProcessor();
            }
        }
    }
}
