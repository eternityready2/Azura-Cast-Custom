<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Message\BuildLinearLogMessage;
use App\Radio\AutoDJ\LinearLogSnapshotStore;
use Monolog\LogRecord;
use Symfony\Component\Messenger\MessageBus;
use Throwable;

final class BuildLinearLogTask extends AbstractTask
{
    public function __construct(
        private readonly MessageBus $messageBus,
        private readonly LinearLogSnapshotStore $snapshotStore,
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

            try {
                $hours = $station->backend_config->linear_log_hours;
                $this->snapshotStore->markQueued($station, $hours);
                $this->messageBus->dispatch(new BuildLinearLogMessage($station->id, $hours));
            } catch (Throwable $e) {
                $this->logger->error(
                    'Unable to queue Linear Log build: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            } finally {
                $this->logger->popProcessor();
            }
        }
    }
}
