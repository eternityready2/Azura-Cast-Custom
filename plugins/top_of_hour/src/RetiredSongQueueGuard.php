<?php

declare(strict_types=1);

namespace Plugin\TopOfHour;

use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\AutoDjRetirementService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Final queue-selection guard for a song retired by a wall-clock takeover.
 *
 * This runs after normal selectors/validators, so no selector mode, backend-merge
 * batch or final retry can re-introduce the interrupted song while quarantine is
 * active. The guard exists only while the Top-of-Hour plugin is loaded.
 */
final readonly class RetiredSongQueueGuard implements EventSubscriberInterface
{
    public function __construct(
        private AutoDjRetirementService $retirement,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['guardSelection', -1000],
        ];
    }

    public function guardSelection(BuildQueue $event): void
    {
        $excludedSongId = $this->retirement->getExcludedSongId($event->getStation());
        if (null === $excludedSongId) {
            return;
        }

        $nextSongs = $event->getNextSongs();
        if ([] === $nextSongs) {
            return;
        }

        $filtered = array_values(array_filter(
            $nextSongs,
            static fn ($queueRow): bool => !hash_equals($excludedSongId, $queueRow->song_id),
        ));

        if (count($filtered) === count($nextSongs)) {
            return;
        }

        $event->setNextSongs([] !== $filtered ? $filtered : null);
    }
}
