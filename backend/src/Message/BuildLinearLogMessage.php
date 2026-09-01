<?php

declare(strict_types=1);

namespace App\Message;

use App\MessageQueue\QueueNames;

final class BuildLinearLogMessage extends AbstractUniqueMessage
{
    public function __construct(
        public readonly int $stationId,
        public readonly int $hours,
        public readonly bool $force = false,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'BuildLinearLogMessage_station_' . $this->stationId;
    }

    public function getTtl(): float
    {
        return 3600;
    }

    public function getQueue(): QueueNames
    {
        return QueueNames::LowPriority;
    }
}
