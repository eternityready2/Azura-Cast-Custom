<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps AI DJ generation tied to live AutoDJ queue decisions only.
 *
 * The Linear Log reuses the normal queue-selection pipeline to plan many hours
 * ahead. Running the AI DJ listener during that projection would mutate live
 * cooldown/shift cache state and could generate real audio for future timestamps.
 * The production AI DJ behavior is therefore delegated unchanged for live events,
 * while projection events simply pass through to the normal music selectors.
 */
final class AiDjProjectionSafeListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AiDjQueueListener $delegate,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', 1],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($event->isProjection()) {
            return;
        }

        $this->delegate->onBuildQueue($event);
    }
}
