<?php

declare(strict_types=1);

namespace App\Radio\Backend\Liquidsoap\Command;

use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;

final class QueueStatusCommand extends AbstractCommand
{
    public function __construct(
        private readonly StationQueueRepository $queueRepo
    ) {
    }

    protected function doRun(
        Station $station,
        bool $asAutoDj = false,
        array $payload = []
    ): array {
        return [
            'ready' => $station->supportsAutoDjQueue()
                && null !== $this->queueRepo->getNextToSendToAutoDj($station),
        ];
    }
}
