<?php

declare(strict_types=1);

namespace App\MessageQueue;

use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Message\BuildLinearLogMessage;
use App\Service\StationDiagnostics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Converts background worker outcomes into station-scoped operational evidence.
 */
final readonly class StationDiagnosticsWorkerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StationRepository $stationRepo,
        private StationDiagnostics $diagnostics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onHandled',
            WorkerMessageFailedEvent::class => 'onFailed',
        ];
    }

    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof BuildLinearLogMessage) {
            return;
        }

        $station = $this->findStation($message->stationId);
        if (!$station instanceof Station) {
            return;
        }

        $this->diagnostics->info(
            $station,
            'linear log',
            'Linear Log build completed.',
            [
                'hours' => $message->hours,
                'forced' => $message->force,
            ]
        );
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof BuildLinearLogMessage) {
            return;
        }

        $station = $this->findStation($message->stationId);
        if (!$station instanceof Station) {
            return;
        }

        $error = str_replace(
            $station->getFilteredPasswords(),
            '(PASSWORD)',
            $event->getThrowable()->getMessage()
        );

        $this->diagnostics->error(
            $station,
            'linear log',
            'Linear Log build failed.',
            [
                'hours' => $message->hours,
                'forced' => $message->force,
                'error' => $error,
            ]
        );
    }

    private function findStation(int $stationId): ?Station
    {
        $station = $this->stationRepo->findByIdentifier((string)$stationId);
        return $station instanceof Station ? $station : null;
    }
}
