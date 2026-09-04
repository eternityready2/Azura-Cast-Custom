<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity\Enums\PlaylistSources;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Service\StationDiagnostics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records high-signal runtime outcomes after AutoDJ decisions are finalized.
 *
 * This deliberately ignores Linear Log preview builds so projected queue rows
 * never appear as live station executions in the diagnostics dashboard.
 */
final readonly class StationDiagnosticsRuntimeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StationDiagnostics $diagnostics,
        private LinearLogPreviewContext $linearLogPreviewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => ['onBuildQueue', -200],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($this->linearLogPreviewContext->isActive()) {
            return;
        }

        $station = $event->getStation();
        foreach ($event->getNextSongs() as $queue) {
            if (!$queue instanceof StationQueue) {
                continue;
            }

            $playlist = $queue->playlist;
            $queueId = isset($queue->id) ? $queue->id : null;

            if ($playlist?->source === PlaylistSources::RemoteUrl) {
                $this->diagnostics->info(
                    $station,
                    'remote stream',
                    'Remote stream item queued.',
                    [
                        'queue_id' => $queueId,
                        'playlist_id' => $playlist->id,
                        'playlist_name' => $playlist->name,
                        'remote_type' => $playlist->remote_type?->value,
                        'expected_play_time' => $event->getExpectedPlayTime()->getTimestamp(),
                    ]
                );
            }

            if (null !== $queue->clock_wheel_stretch_ratio) {
                $this->diagnostics->info(
                    $station,
                    'stretch squeeze',
                    'Stretch / Squeeze timing applied.',
                    [
                        'queue_id' => $queueId,
                        'media_id' => $queue->media?->id,
                        'playlist_id' => $playlist?->id,
                        'stretch_ratio' => round($queue->clock_wheel_stretch_ratio, 6),
                        'duration_seconds' => null !== $queue->duration ? round($queue->duration, 3) : null,
                        'expected_play_time' => $event->getExpectedPlayTime()->getTimestamp(),
                    ]
                );
            }

            if ($queue->hour_boundary_enforce_cap) {
                $this->diagnostics->warning(
                    $station,
                    'stretch squeeze',
                    'Hard duration cap used because the track could not fit the protected boundary normally.',
                    [
                        'queue_id' => $queueId,
                        'media_id' => $queue->media?->id,
                        'playlist_id' => $playlist?->id,
                        'max_play_seconds' => $queue->hour_boundary_max_play_seconds,
                        'expected_play_time' => $event->getExpectedPlayTime()->getTimestamp(),
                    ]
                );
            }

            if ($queue->top_of_hour_pre_id_fade) {
                $this->diagnostics->info(
                    $station,
                    'playout controls',
                    'Top-of-hour pre-ID fade applied.',
                    [
                        'queue_id' => $queueId,
                        'media_id' => $queue->media?->id,
                        'playlist_id' => $playlist?->id,
                        'fade_seconds' => $queue->top_of_hour_pre_id_fade_seconds,
                        'expected_play_time' => $event->getExpectedPlayTime()->getTimestamp(),
                    ]
                );
            }
        }
    }
}
