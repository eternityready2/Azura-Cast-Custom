<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\ClockWheelEvent;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\AutoDJ\TopOfHour\TopOfHourPlan;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Utilities\Time;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Pre-stage the next automatic Station ID well before its wall-clock deadline.
 *
 * This task is intentionally not the clock trigger. It resolves the exact queue
 * row/request and pushes absolute target/boundary epochs into Liquidsoap. The
 * Liquidsoap source switch then performs the real :59:ss cut at frame accuracy.
 */
final class StageTopOfHourStationIdTask extends AbstractTask
{
    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockWheelEventRepository $eventRepo,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            try {
                $this->stageForStation($station);
            } catch (Throwable $e) {
                $this->logger->error(
                    'Top-of-Hour ID staging failed for station.',
                    [
                        'station_id' => $station->id,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    private function stageForStation(Station $station): void
    {
        if (!$station->supportsAutoDjQueue()) {
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        // These runtime controls are cheap and make page/config changes converge
        // without requiring the once-per-hour request to be rebuilt.
        $this->syncRuntimeControls($station, $backend);

        if (!$this->clock->isEnabled($station)) {
            $this->clearRuntimeQueueIfNeeded($station, $backend);
            $this->removeAllUnplayedStationWideIds($station);
            return;
        }

        $now = Time::nowUtc();
        $plan = $this->clock->plan($station, $now);
        if (!$plan instanceof TopOfHourPlan) {
            $this->clearRuntimeQueueIfNeeded($station, $backend);
            return;
        }

        if ($now >= $plan->boundaryAt) {
            return;
        }

        $secondsToTarget = (float)$plan->targetStartAt->format('U.u') - (float)$now->format('U.u');
        $lookaheadSeconds = $this->clock->getLookaheadMinutes($station) * 60;

        // Resolve early enough that the request is already ready when the fade
        // starts. If the task recovers late during :59, still stage immediately;
        // Liquidsoap will take it as soon as it is ready, but HARD :00 still wins.
        if ($secondsToTarget > $lookaheadSeconds) {
            return;
        }

        $this->setRuntimePlan($station, $backend, $plan);

        $queueRow = $this->findBoundaryRow($station, $plan->boundaryAt);
        if ($queueRow instanceof StationQueue && $queueRow->is_played) {
            return;
        }

        $isNew = false;

        // If ID-library/settings changes select a different ID before airtime,
        // replace the staged row and its not-yet-aired compliance event cleanly.
        if (
            $queueRow instanceof StationQueue
            && $queueRow->media?->id !== $plan->media->id
        ) {
            $existingEvent = $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued(
                $station,
                $queueRow->id,
            );
            if ($existingEvent instanceof ClockWheelEvent) {
                $this->em->remove($existingEvent);
            }

            $this->em->remove($queueRow);
            $this->em->flush();
            $this->clearRuntimeQueue($station, $backend);
            $queueRow = null;
        }

        if (!$queueRow instanceof StationQueue) {
            $queueRow = StationQueue::fromMedia($station, $plan->media);
            $queueRow->top_of_hour_legal_id = true;
            $queueRow->top_of_hour_boundary_at = $plan->boundaryAt;
            $queueRow->sent_to_autodj = true;
            $queueRow->updateVisibility();
            $isNew = true;
        }

        // The staged row deliberately carries its real playout target so the
        // Upcoming Queue and compliance layer display the station clock, not the
        // minute when PHP happened to pre-stage the request.
        $queueRow->duration = $plan->durationSeconds;
        $queueRow->timestamp_cued = $plan->targetStartAt;
        $queueRow->timestamp_played = $plan->targetStartAt;
        $queueRow->sent_to_autodj = true;
        $this->em->persist($queueRow);
        $this->em->flush();

        if (!$isNew) {
            $existingEvent = $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued(
                $station,
                $queueRow->id,
            );
            if ($existingEvent instanceof ClockWheelEvent) {
                $existingEvent->expected_play_at = $plan->targetStartAt;
                $this->em->persist($existingEvent);
                $this->em->flush();
            }
        }

        if ($isNew) {
            // Never allow a stale unresolved request from the previous hour to
            // sit ahead of the newly selected boundary ID.
            $this->clearRuntimeQueue($station, $backend);
        } elseif (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
            // Request is already resolved and waiting. Runtime target may have
            // changed above; no need to resolve/push the same audio twice.
            return;
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, true);
        $this->eventDispatcher->dispatch($event);

        // The outgoing source receives the slow pre-fade. The ID itself starts
        // immediately at full level on the exact target.
        $event->addAnnotations([
            'autocue_fade_in' => 0.0,
            'autocue_fade_out' => 0.0,
        ]);

        // AnnotateNextSong normally records API-send time. Restore the actual
        // planned station-clock target after annotation for this external lane.
        $queueRow->sent_to_autodj = true;
        $queueRow->timestamp_cued = $plan->targetStartAt;
        $queueRow->timestamp_played = $plan->targetStartAt;
        $this->em->persist($queueRow);
        $this->em->flush();

        $track = $event->buildAnnotations();
        $backend->enqueue($station, LiquidsoapQueues::TopOfHour, $track);

        $this->logger->info(
            'Top-of-Hour Station ID staged for exact wall-clock playout.',
            [
                'station_id' => $station->id,
                'queue_id' => $queueRow->id,
                'media_id' => $queueRow->media?->id,
                'mode' => $plan->mode->value,
                'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
                'boundary_at' => $plan->boundaryAt->format(DateTimeImmutable::ATOM),
            ]
        );
    }

    private function syncRuntimeControls(Station $station, Liquidsoap $backend): void
    {
        $enabled = $this->clock->isEnabled($station) ? 'true' : 'false';
        $fadeSeconds = number_format($this->clock->getIdFadeSeconds($station), 1, '.', '');

        $backend->command($station, 'top_of_hour_id_control.enabled ' . $enabled);
        $backend->command($station, 'top_of_hour_id_control.fade_seconds ' . $fadeSeconds);
    }

    private function setRuntimePlan(
        Station $station,
        Liquidsoap $backend,
        TopOfHourPlan $plan,
    ): void {
        $targetEpoch = number_format((float)$plan->targetStartAt->format('U.u'), 3, '.', '');
        $boundaryEpoch = number_format((float)$plan->boundaryAt->format('U.u'), 3, '.', '');

        $backend->command($station, 'top_of_hour_id_control.target_epoch ' . $targetEpoch);
        $backend->command($station, 'top_of_hour_id_control.boundary_epoch ' . $boundaryEpoch);
        $backend->command(
            $station,
            'top_of_hour_id_control.hard ' . ($plan->isHard() ? 'true' : 'false')
        );
    }

    private function clearRuntimeQueueIfNeeded(Station $station, Liquidsoap $backend): void
    {
        try {
            if (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
                $this->clearRuntimeQueue($station, $backend);
            }
        } catch (Throwable $e) {
            // A station may be stopped/restarting while the minute task runs.
            $this->logger->debug(
                'Top-of-Hour runtime queue status unavailable.',
                ['station_id' => $station->id, 'exception' => $e->getMessage()]
            );
        }
    }

    private function clearRuntimeQueue(Station $station, Liquidsoap $backend): void
    {
        $backend->command($station, 'top_of_hour_id_control.clear');
    }

    private function findBoundaryRow(
        Station $station,
        DateTimeImmutable $boundary,
    ): ?StationQueue {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT q FROM App\Entity\StationQueue q
                WHERE q.station = :station
                AND q.top_of_hour_legal_id = true
                AND q.top_of_hour_boundary_at = :boundary
            DQL
        )->setParameters([
            'station' => $station,
            'boundary' => Time::toUtcCarbonImmutable($boundary),
        ])->setMaxResults(1)
            ->getOneOrNullResult();
    }

    private function removeAllUnplayedStationWideIds(Station $station): void
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT q FROM App\Entity\StationQueue q
                WHERE q.station = :station
                AND q.top_of_hour_legal_id = true
                AND q.is_played = false
            DQL
        )->setParameter('station', $station)
            ->getResult();

        foreach ($rows as $row) {
            if ($row instanceof StationQueue) {
                $this->em->remove($row);
            }
        }

        if ([] !== $rows) {
            $this->em->flush();
        }
    }
}
