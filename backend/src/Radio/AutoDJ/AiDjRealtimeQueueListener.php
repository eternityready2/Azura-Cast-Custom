<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Event\Radio\BuildQueue;
use App\Utilities\Time;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs AI DJ decisions against real airtime instead of a deep queue projection.
 *
 * The normal AutoDJ queue may be built many minutes (or, for the linear log,
 * many hours) ahead. AI DJ clips are inserted directly into Liquidsoap's live
 * requests queue, so using the projected BuildQueue timestamp can suppress a
 * live break merely because a future projected slot happens to be near :00.
 */
final class AiDjRealtimeQueueListener implements EventSubscriberInterface
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
        if ($event->isInterrupting() || $event->getNextSongs() !== []) {
            return;
        }

        $now = Time::nowUtc()->toDateTimeImmutable();
        $liveEvent = new BuildQueue(
            $event->getStation(),
            $now,
            $now,
            $event->getLastPlayedSongId(),
            false,
        );

        $this->delegate->onBuildQueue($liveEvent);
    }
}
