<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\SongHistoryRepository;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Service\StationDiagnostics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies content-type crossfade matrix fades on AutoDJ annotations (Master Plan §7).
 */
final class ContentTypeCrossfadeAnnotator implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ContentTypeCrossfadeService $crossfadeService,
        private readonly SongHistoryRepository $historyRepo,
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnnotateNextSong::class => [
                ['applyContentTypeCrossfade', 8],
            ],
        ];
    }

    public function applyContentTypeCrossfade(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $media = $event->getMedia();
        if (!$media instanceof StationMedia) {
            return;
        }

        $queue = $event->getQueue();
        if (!$queue instanceof StationQueue) {
            return;
        }

        // Legal ID quick-cut (priority 9) overrides this for legal_id rows.
        if (($queue->top_of_hour_legal_id ?? false)
            || ($queue->clock_wheel_legal_id_substitute ?? false)
            || StationMediaTypes::isStationId($media->type)
        ) {
            return;
        }

        // HourBoundaryAnnotator::applyTopOfHourPreIdFade (priority 10) already set
        // a deliberate fade_out for this row so it ends cleanly before a due
        // top-of-hour ID -- don't let the generic content-type matrix clobber it.
        if ($queue->top_of_hour_pre_id_fade ?? false) {
            return;
        }

        $station = $event->getStation();
        $fromType = $this->historyRepo->getLastPlayedMediaType($station) ?? 'music';
        $toType = $media->type ?? 'music';

        $fades = $this->crossfadeService->resolveTransitionFades(
            $station,
            $fromType,
            $toType,
            $queue->playlist,
        );

        if (null === $fades) {
            return;
        }

        $event->addAnnotations([
            'autocue_fade_in' => $fades['fade_in'],
            'autocue_fade_out' => $fades['fade_out'],
        ]);

        $this->diagnostics->info(
            $station,
            'crossfade',
            'Content-type crossfade transition applied.',
            [
                'queue_id' => isset($queue->id) ? $queue->id : null,
                'media_id' => $media->id,
                'playlist_id' => $queue->playlist?->id,
                'previous_type' => $fromType,
                'current_type' => $toType,
                'profile' => $queue->playlist?->crossfade_profile,
                'fade_in_seconds' => (float)$fades['fade_in'],
                'fade_out_seconds' => (float)$fades['fade_out'],
            ]
        );
    }
}
