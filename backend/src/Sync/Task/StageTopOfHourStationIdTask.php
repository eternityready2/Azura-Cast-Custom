<?php

declare(strict_types=1);

namespace App\Sync\Task;

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
 * Pre-stages exactly one station-wide Top-of-Hour ID for the next boundary.
 *
 * This task is deliberately not the clock trigger. It runs once per minute only
 * to make sure the selected request is resolved and waiting in Liquidsoap well
 * before airtime. Liquidsoap's wall-clock switch owns the exact :59:ss cut.
 */
final class StageTopOfHourStationIdTask extends AbstractTask
{
    public function __construct(
        private readonly TopOfHourClock $clock,
        private readonly Adapters $adapters,
        private readonly EventDispatcherInterface $eventDispatcher,
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

        // Keep the running Liquidsoap instance synchronized with the page/API.
        // The runtime block always exists, so these settings do not depend on a
        // station restart once the updated configuration has been deployed.
        $this->syncRuntimeControls($station, $backend);

        if (!$this->clock->isEnabled($station)) {
            $this->clearRuntimeQueueIfNeeded($station, $backend);
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

        // An explicit Clock Wheel legal-ID slot is the one special ownership
        // exception retained from the clock-wheel engine. Do not stack two IDs.
        if ($this->clock->clockWheelOwnsBoundary($station, $plan->boundaryAt)) {
            $this->removeStagedBoundaryRow($station, $plan->boundaryAt);
            $this->clearRuntimeQueueIfNeeded($station, $backend);
            return;
        }

        $secondsToTarget = (float)$plan->targetStartAt->format('U.u') - (float)$now->format('U.u');
        $lookaheadSeconds = $this->clock->getLookaheadMinutes($station) * 60;

        // Stage in advance during the configured lookahead. If a task tick lands
        // after the target but still inside minute :59, allow late recovery so an
        // ID is not silently missed because the backend was briefly unavailable.
        if ($secondsToTarget > $lookaheadSeconds) {
            return;
        }

        $this->setRuntimeHardBoundary($station, $backend, $plan->isHard());

        $queueRow = $this->findBoundaryRow($station, $plan->boundaryAt);
        if ($queueRow instanceof StationQueue && $queueRow->is_played) {
            return;
        }

        $isNew = false;
        if (!$queueRow instanceof StationQueue) {
            $queueRow = StationQueue::fromMedia($station, $plan->media);
            $queueRow->top_of_hour_legal_id = true;
            $queueRow->top_of_hour_boundary_at = $plan->boundaryAt;
            $queueRow->duration = $plan->durationSeconds;
            $queueRow->timestamp_cued = $plan->targetStartAt;
            $queueRow->timestamp_played = $plan->targetStartAt;
            $queueRow->sent_to_autodj = true;
            $queueRow->updateVisibility();

            $this->em->persist($queueRow);
            $this->em->flush();
            $isNew = true;
        }

        if ($isNew) {
            // A new hour must never sit behind an unresolved stale request left
            // in the dedicated source after a restart or previous hard boundary.
            $this->clearRuntimeQueue($station, $backend);
        } elseif (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
            // Already staged and waiting in Liquidsoap.
            return;
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, true);
        $this->eventDispatcher->dispatch($event);

        // The outgoing programme/music is what fades. The ID itself begins at
        // full level at the wall-clock target so a long ID fade-in cannot make
        // the legal identification sound late.
        $event->addAnnotations([
            'autocue_fade_in' => 0.0,
            'autocue_fade_out' => 0.0,
        ]);

        // AnnotateNextSong records the API-send time as timestamp_cued. For this
        // externally staged row, preserve the real planned clock position instead;
        // feedback later uses sq_id to mark this exact row actually played.
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
        $startSecond = $this->clock->getIdStartSecond($station);
        $fadeSeconds = number_format($this->clock->getIdFadeSeconds($station), 1, '.', '');

        $backend->command($station, 'top_of_hour_id_control.enabled ' . $enabled);
        $backend->command($station, 'top_of_hour_id_control.start_second ' . $startSecond);
        $backend->command($station, 'top_of_hour_id_control.fade_seconds ' . $fadeSeconds);
    }

    private function setRuntimeHardBoundary(
        Station $station,
        Liquidsoap $backend,
        bool $isHard,
    ): void {
        $backend->command(
            $station,
            'top_of_hour_id_control.hard ' . ($isHard ? 'true' : 'false')
        );
    }

    private function clearRuntimeQueueIfNeeded(Station $station, Liquidsoap $backend): void
    {
        try {
            if (!$backend->isQueueEmpty($station, LiquidsoapQueues::TopOfHour)) {
                $this->clearRuntimeQueue($station, $backend);
            }
        } catch (Throwable $e) {
            // A station can be stopped/restarting while the sync task runs.
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

    private function removeStagedBoundaryRow(
        Station $station,
        DateTimeImmutable $boundary,
    ): void {
        $row = $this->findBoundaryRow($station, $boundary);
        if (!$row instanceof StationQueue || $row->is_played) {
            return;
        }

        $this->em->remove($row);
        $this->em->flush();
    }
}
