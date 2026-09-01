<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Repository\AiDjScheduleRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Message\AbstractMessage;
use App\Message\BuildLinearLogMessage;
use App\Utilities\Time;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;

final class LinearLogBuilder
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Queue $queue,
        private readonly StationQueueRepository $queueRepo,
        private readonly StationRepository $stationRepo,
        private readonly LinearLogSnapshotStore $snapshotStore,
        private readonly LinearLogPreviewContext $previewContext,
        private readonly CacheInterface $cache,
        private readonly AiDjScheduleRepository $aiDjScheduleRepo,
    ) {
    }

    public function __invoke(AbstractMessage $message): void
    {
        if (!$message instanceof BuildLinearLogMessage) {
            return;
        }

        $station = $this->stationRepo->findByIdentifier((string)$message->stationId);
        if (!$station instanceof Station || !$station->supportsAutoDjQueue()) {
            return;
        }

        $this->build($station, $message->hours);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Station $station, ?int $hoursOverride = null): array
    {
        $hours = max(1, min(48, $hoursOverride ?? $station->backend_config->linear_log_hours));
        $lookaheadMinutes = $hours * 60;
        $maxTracks = max(1000, $lookaheadMinutes * 2);
        $buildStartedAt = time();
        $projectionStart = Time::nowUtc();
        $projectionStartTs = $projectionStart->getTimestamp();
        $projectionEndTs = $projectionStart->modify('+' . $hours . ' hours')->getTimestamp();

        $liveQueueIds = [];
        foreach ($this->queueRepo->getUnplayedQueue($station) as $queueRow) {
            $liveQueueIds[$queueRow->id] = true;
        }

        $this->snapshotStore->markBuilding($station, $hours);

        $cacheState = $this->captureMutableCacheState($station);
        $this->previewContext->begin();

        $connection = $this->em->getConnection();
        $entries = [];
        $gaps = [];
        $coverageEnd = $projectionStartTs;

        try {
            $connection->beginTransaction();

            $gaps = $this->queue->buildQueue(
                $station,
                $lookaheadMinutes,
                $maxTracks,
                true,
            );

            $rows = $this->queueRepo->getUnplayedQueue($station);
            usort(
                $rows,
                static fn(StationQueue $a, StationQueue $b): int =>
                    ($a->timestamp_played?->getTimestamp() ?? 0) <=> ($b->timestamp_played?->getTimestamp() ?? 0)
            );

            $sequence = 0;
            foreach ($rows as $row) {
                $playedAt = $row->timestamp_played?->getTimestamp();
                if (null === $playedAt) {
                    continue;
                }

                if ($playedAt < ($projectionStartTs - 300) || $playedAt > $projectionEndTs) {
                    continue;
                }

                $duration = max(5.0, $row->duration ?? 0.0);
                $coverageEnd = max($coverageEnd, $playedAt + (int)ceil($duration));
                $entries[] = $this->mapQueueRow(
                    $row,
                    ++$sequence,
                    isset($liveQueueIds[$row->id]),
                );
            }

            foreach ($gaps as $gap) {
                $coverageEnd = max(
                    $coverageEnd,
                    (int)$gap['started_at'] + (int)$gap['duration'],
                );
            }
        } catch (Throwable $e) {
            $this->snapshotStore->markFailed($station, $hours, $e->getMessage());
            throw $e;
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->previewContext->end();
            $this->restoreMutableCacheState($cacheState);
            $this->em->clear();
        }

        $aiDjShifts = $this->buildAiDjShifts($station, $projectionStartTs, $projectionEndTs);

        $this->snapshotStore->storeReady(
            $station,
            $hours,
            $buildStartedAt,
            $projectionStartTs,
            min($coverageEnd, $projectionEndTs),
            $entries,
            $gaps,
            $aiDjShifts,
        );

        return $this->snapshotStore->get($station);
    }

    /**
     * @return array<string, array{exists:bool, value:mixed}>
     */
    private function captureMutableCacheState(Station $station): array
    {
        $keys = [];
        foreach ($station->playlists as $playlist) {
            $keys[] = 'playlist_queue.' . $playlist->id;
        }

        $state = [];
        foreach (array_unique($keys) as $key) {
            $exists = $this->cache->has($key);
            $state[$key] = [
                'exists' => $exists,
                'value' => $exists ? $this->cache->get($key) : null,
            ];
        }

        return $state;
    }

    /**
     * @param array<string, array{exists:bool, value:mixed}> $state
     */
    private function restoreMutableCacheState(array $state): void
    {
        foreach ($state as $key => $item) {
            if ($item['exists']) {
                $this->cache->set($key, $item['value'], 6000);
            } else {
                $this->cache->delete($key);
            }
        }
    }

    /**
     * AI DJ speech is deliberately not generated by the preview. These rows only
     * describe scheduled work shifts so the log still shows who is expected to be
     * on-air while all speech timing and content remain live.
     *
     * @return list<array<string, mixed>>
     */
    private function buildAiDjShifts(Station $station, int $startTs, int $endTs): array
    {
        $timezone = $station->getTimezoneObject();
        $firstDay = (new DateTimeImmutable('@' . $startTs))
            ->setTimezone($timezone)
            ->setTime(0, 0)
            ->modify('-1 day');
        $lastDay = (new DateTimeImmutable('@' . $endTs))
            ->setTimezone($timezone)
            ->setTime(0, 0);

        $shifts = [];
        foreach ($this->aiDjScheduleRepo->findByStation($station->id) as $schedule) {
            $dj = $schedule->getAiDj();
            if (!$schedule->isEnabled() || !$dj->isEnabled()) {
                continue;
            }

            for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) {
                if (!in_array((int)$day->format('N'), $schedule->getLoopDays(), true)) {
                    continue;
                }

                $startParts = array_map('intval', explode(':', $schedule->getStartTime()->format('H:i:s')));
                $endParts = array_map('intval', explode(':', $schedule->getEndTime()->format('H:i:s')));

                $shiftStart = $day->setTime($startParts[0], $startParts[1], $startParts[2]);
                $shiftEnd = $day->setTime($endParts[0], $endParts[1], $endParts[2]);
                if ($shiftEnd <= $shiftStart) {
                    $shiftEnd = $shiftEnd->modify('+1 day');
                }

                $shiftStartTs = $shiftStart->getTimestamp();
                $shiftEndTs = $shiftEnd->getTimestamp();
                if ($shiftEndTs <= $startTs || $shiftStartTs >= $endTs) {
                    continue;
                }

                $shifts[] = [
                    'schedule_id' => $schedule->getId(),
                    'schedule_name' => $schedule->getName(),
                    'dj_id' => $dj->getId(),
                    'dj_name' => $dj->getName(),
                    'starts_at' => $shiftStartTs,
                    'ends_at' => $shiftEndTs,
                ];
            }
        }

        usort($shifts, static fn(array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $shifts;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapQueueRow(StationQueue $row, int $sequence, bool $isLiveQueue): array
    {
        $mediaType = match (true) {
            $row->top_of_hour_legal_id => 'id',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->media => $row->media->type,
            default => 'music',
        };

        $sourceType = match (true) {
            null !== $row->request => 'request',
            null !== $row->clock_wheel => 'clock_wheel',
            null !== $row->autodj_custom_uri => 'stream',
            null !== $row->playlist => 'playlist',
            default => 'autodj',
        };

        return [
            'id' => 'projection-' . $sequence,
            'queue_id' => $row->id,
            'song_id' => $row->song_id,
            'played_at' => $row->timestamp_played?->getTimestamp(),
            'cued_at' => $row->timestamp_cued->getTimestamp(),
            'duration' => max(5.0, $row->duration ?? 0.0),
            'title' => $row->title,
            'artist' => $row->artist,
            'album' => $row->album,
            'text' => $row->text,
            'playlist' => $row->playlist?->name,
            'playlist_id' => $row->playlist?->id,
            'playlist_chain' => $row->playlist_chain,
            'clock_wheel' => $row->clock_wheel?->name,
            'clock_wheel_id' => $row->clock_wheel?->id,
            'media_type' => $mediaType,
            'source_type' => $sourceType,
            'is_request' => null !== $row->request,
            'is_live_queue' => $isLiveQueue,
            'sent_to_autodj' => $row->sent_to_autodj,
            'top_of_hour_legal_id' => $row->top_of_hour_legal_id,
            'autodj_custom_uri' => $row->autodj_custom_uri,
            'clock_wheel_schedule_mode' => $row->clock_wheel_schedule_mode,
            'clock_wheel_enforce_cap' => $row->clock_wheel_enforce_cap,
            'clock_wheel_stretch_ratio' => $row->clock_wheel_stretch_ratio,
            'clock_wheel_legal_id_substitute' => $row->clock_wheel_legal_id_substitute,
            'hour_boundary_enforce_cap' => $row->hour_boundary_enforce_cap,
            'hour_boundary_max_play_seconds' => $row->hour_boundary_max_play_seconds,
            'top_of_hour_pre_id_fade' => $row->top_of_hour_pre_id_fade,
        ];
    }
}
