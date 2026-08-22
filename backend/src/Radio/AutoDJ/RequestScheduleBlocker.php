<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Event\Radio\BuildQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes a pick from the automatic listener-request queue when an active
 * playlist schedule explicitly blocks requests. The normal AutoDJ selector
 * then continues and chooses a non-request track.
 *
 * This runs after QueueBuilder's automatic request selector (priority 5) and
 * before its normal playlist selector (priority 0), keeping the fork's custom
 * queue, clock-wheel and AI-DJ selection logic untouched.
 */
final class RequestScheduleBlocker implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['blockAutomaticRequests', 4],
            ],
        ];
    }

    public function blockAutomaticRequests(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        $nextSongs = $event->getNextSongs();
        if (empty($nextSongs)) {
            return;
        }

        // Only undo a selection made by the automatic request queue.
        if (!array_any($nextSongs, static fn($queueEntry): bool => null !== $queueEntry->request)) {
            return;
        }

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();
        $stationTz = $station->getTimezoneObject();

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            foreach ($playlist->schedule_items as $scheduleItem) {
                if (!$scheduleItem->prevent_requests) {
                    continue;
                }

                if (
                    $this->scheduler->shouldSchedulePlayNow(
                        $scheduleItem,
                        $stationTz,
                        $expectedPlayTime,
                        excludeSpecialRules: true
                    )
                ) {
                    $this->logger->debug(
                        sprintf(
                            'Schedule item on playlist "%s" is blocking the automatic request queue.',
                            $playlist->name
                        )
                    );

                    $event->setNextSongs(null);
                    return;
                }
            }
        }
    }
}
