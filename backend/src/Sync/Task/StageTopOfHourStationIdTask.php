<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\ClockWheelEvent;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Adapters;
use App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger;
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
        private readonly ClockWheelEventLogger $eventLogger,
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

        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            $this->removeBoundaryRow($station, $plan->boundaryAt);
            $this->clearRuntimeQueueIfNeeded($station, $backend);
            return;
        }

        $secondsToTarget = (float)$plan->targetStartAt->format('U.u') - (float)$now->format('U.u');
        $lookaheadSeconds = $this->clock->getLookaheadMinutes($station) * 60;
        if ($secondsToTarget > $lookaheadSeconds) {
            return;
        }

        $targetHasArrived = $now >= $plan->targetStartAt;
        $this->setRuntimePlan($station, $backend, $plan);

        $queueRow = $this->findBoundaryRow($station, $plan->boundaryAt);
        if ($queueRow instanceof StationQueue && $queueRow->is_played) {
            return;
        }

        $isNew = false;

        if (
            $queueRow instanceof StationQueue
            && $queueRow->media?->id !== $plan->media->id
        ) {
            $this->removeComplianceEventForRow($station, $queueRow);
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

        $queueRow->duration = $plan->durationSeconds;
        $queueRow->timestamp_cued = $plan->targetStartAt;
        $queueRow->timestamp_played = $plan->targetStartAt;
        $queueRow->sent_to_autodj = true;
        $this->em->persist($queueRow);
        $this->em->flush();

        $existingEvent = $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued(
            $station,
            $queueRow->id,
        );

        if ($existingEvent instanceof ClockWheelEvent) {
            $existingEvent->expected_play_at = $plan->targetStartAt;
            $this->em->persist($existingEvent);
        } else {
            $this->eventLogger->recordTopOfHourLegalIdQueued(
                $station,
                $plan->media,
                $plan->targetStartAt,
                $queueRow,
            );
        }
        $this->em->flush();

        if ($isNew) {
            $this->clearRuntimeQueue($station, $backend);
        } elseif ($targetHasArrived) {
            // request.queue removes the currently-playing request from its waiting
            // queue. During minute :59 that makes queue() look empty while the ID
            // is already on air. Never interpret that as permission to enqueue the
            // same boundary row a second time.
            $this->logger->debug(
                'Top-of-Hour Station ID target already arrived; refusing to restage an existing boundary row.',
                [
                    'station_id' => $station->id,
                    'queue_id' => $queueRow->id,
                    'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
                ]
            );
            return;
        } elseif (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
            return;
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, true);
        $this->eventDispatcher->dispatch($event);
        $event->addAnnotations([
            'autocue_fade_in' => 0.0,
            'autocue_fade_out' => 0.0,
        ]);

        $queueRow->sent_to_autodj = true;
        $queueRow->timestamp_cued = $plan->targetStartAt;
        $queueRow->timestamp_played = $plan->targetStartAt;
        $this->em->persist($queueRow);
        $this->em->flush();

        $backend->enqueue(
            $station,
            LiquidsoapQueues::TopOfHour,
            $event->buildAnnotations(),
        );

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
        $backend->command(
            $station,
            'top_of_hour_id_control.enabled ' . ($this->clock->isEnabled($station) ? 'true' : 'false')
        );
        $backend->command(
            $station,
            'top_of_hour_id_control.fade_seconds '
            . number_format($this->clock->getIdFadeSeconds($station), 1, '.', '')
        );
    }

    private function setRuntimePlan(
        Station $station,
        Liquidsoap $backend,
        TopOfHourPlan $plan,
    ): void {
        $backend->command(
            $station,
            'top_of_hour_id_control.target_epoch '
            . number_format((float)$plan->targetStartAt->format('U.u'), 3, '.', '')
        );
        $backend->command(
            $station,
            'top_of_hour_id_control.boundary_epoch '
            . number_format((float)$plan->boundaryAt->format('U.u'), 3, '.', '')
        );
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

    private function removeBoundaryRow(
        Station $station,
        DateTimeImmutable $boundary,
    ): void {
        $row = $this->findBoundaryRow($station, $boundary);
        if (!$row instanceof StationQueue || $row->is_played) {
            return;
        }

        $this->removeComplianceEventForRow($station, $row);
        $this->em->remove($row);
        $this->em->flush();
    }

    private function removeComplianceEventForRow(Station $station, StationQueue $row): void
    {
        $event = $this->eventRepo->findLatestUnplayedTopOfHourLegalIdQueued($station, $row->id);
        if ($event instanceof ClockWheelEvent) {
            $this->em->remove($event);
        }
    }

    private function removeAllUnplayedStationWideIds(Station $station): void
    {
        /** @var list<StationQueue> $rows */
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
            $this->removeComplianceEventForRow($station, $row);
            $this->em->remove($row);
        }

        if ([] !== $rows) {
            $this->em->flush();
        }
    }
}
