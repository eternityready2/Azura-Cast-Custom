<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ\ClockWheel;

use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\ClockWheelFallbackReason;
use App\Entity\Enums\ClockWheelScheduleMode;
use App\Entity\Enums\ClockWheelSlotAlgorithms;
use App\Entity\Enums\ClockWheelSlotPoolModes;
use App\Entity\Enums\ClockWheelSlotTypes;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\StationPlaylist;
use App\Entity\StationSchedule;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationClockWheel;
use App\Entity\StationClockWheelSlot;
use App\Entity\StationMedia;
use App\Entity\StationQueue;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\AutoDJ\MediaPlayability;
use App\Radio\AutoDJ\QueueBuilder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Duration-aware format clock planner: selects the active anchor slot for the
 * current second in the hour and picks media that fits before the next anchor.
 */
final class ClockWheelPlaybackPlanner
{
    private const int HOUR_SECONDS = 3600;

    /** Minimum playable window (seconds) before deferring a flexible music slot. */
    private const int MIN_MUSIC_WINDOW_SECONDS = 30;

    private const int MIN_TALK_WINDOW_SECONDS = 45;

    private const int MIN_SHORT_FORM_WINDOW_SECONDS = 10;

    /** @deprecated Use HourBoundaryPlanner::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS or station config. */
    public const int LEGAL_ID_COMPLIANCE_TOLERANCE_SECONDS = HourBoundaryPlanner::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StationQueueRepository $queueRepo,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly SeparationRulesChecker $separationChecker,
        private readonly ClockWheelEventLogger $eventLogger,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly QueueBuilder $queueBuilder,
        private readonly ClockWheelStretchCalculator $stretchCalculator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    public function resolveNextQueueEntry(
        StationClockWheel $wheel,
        array $recentHistory,
        DateTimeImmutable $expectedPlayTime,
        StationSchedule $activeSchedule,
    ): ?StationQueue {
        $slots = $this->sortSlots($wheel->slots->toArray());

        $station = $wheel->station;

        if ($slots === []) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                null,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlots,
            );

            return null;
        }
        $tz = $station->getTimezoneObject();
        $secondsIntoHour = $this->hourBoundaryPlanner->getPlannedSecondsIntoHour($station, $expectedPlayTime, $tz);

        $activeIndex = $this->getActiveSlotIndex($slots, $secondsIntoHour);
        $activeSlot = $slots[$activeIndex];
        $nextAnchor = $this->getNextAnchorSeconds($slots, $activeIndex);
        $availableSeconds = max(1, $nextAnchor - $secondsIntoHour);
        $minWindow = $this->getMinWindowSeconds($activeSlot);

        $this->logger->info('Clock Wheel slot selection.', [
            'clock_wheel_id' => $wheel->id,
            'seconds_into_hour' => $secondsIntoHour,
            'expected_play_time' => $expectedPlayTime->format(DateTimeImmutable::ATOM),
            'active_slot_order' => $activeSlot->slot_order,
            'active_position_seconds' => $activeSlot->position_seconds,
            'next_anchor_seconds' => $nextAnchor,
            'available_seconds' => $availableSeconds,
            'min_window_seconds' => $minWindow,
            'slot_type' => $activeSlot->type?->value,
        ]);

        $scheduleMode = $activeSchedule->clock_wheel_mode ?? ClockWheelScheduleMode::Flexible;

        if (ClockWheelSlotTypes::isMandatoryTopOfHourSlot($activeSlot->type, $activeSlot->position_seconds)) {
            if (!$this->hourBoundaryPlanner->isTopOfHourProtectionEnabled($station)) {
                $this->logger->info(
                    'Clock Wheel mandatory top-of-hour ID skipped: station Top-of-Hour ID is disabled.',
                    ['clock_wheel_id' => $wheel->id, 'slot_id' => $activeSlot->id]
                );
                $this->eventLogger->recordFallback(
                    $station,
                    $wheel,
                    $activeSlot,
                    $expectedPlayTime,
                    ClockWheelFallbackReason::TopOfHourDisabled,
                    $secondsIntoHour,
                );

                return null;
            }

            return $this->resolveMandatoryLegalIdSlot(
                $wheel,
                $activeSlot,
                $recentHistory,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $availableSeconds,
            );
        }

        if ($this->isFlexibleMusicSlot($activeSlot) && $availableSeconds < $minWindow) {
            if ($activeSlot->is_hard_anchor) {
                if ($availableSeconds < self::MIN_SHORT_FORM_WINDOW_SECONDS) {
                    $this->logger->warning(
                        'Clock Wheel: hard anchor missed — insufficient window before next anchor.',
                        [
                            'available_seconds' => $availableSeconds,
                            'position_seconds' => $activeSlot->position_seconds,
                        ]
                    );
                    $this->eventLogger->recordFallback(
                        $station,
                        $wheel,
                        $activeSlot,
                        $expectedPlayTime,
                        ClockWheelFallbackReason::HardAnchorMissed,
                        $secondsIntoHour,
                    );

                    return null;
                }

                $this->logger->info(
                    'Clock Wheel: hard anchor — forcing shortest fit in tight window.',
                    [
                        'available_seconds' => $availableSeconds,
                        'min_window_seconds' => $minWindow,
                    ]
                );
            } else {
                $this->logger->info(
                    'Clock Wheel: insufficient time before next anchor; deferring to next BuildQueue tick.',
                    [
                        'available_seconds' => $availableSeconds,
                        'min_window_seconds' => $minWindow,
                        'slot_type' => $activeSlot->type?->value,
                    ]
                );
                $this->eventLogger->recordDeferred(
                    $station,
                    $wheel,
                    $activeSlot,
                    $expectedPlayTime,
                    ClockWheelFallbackReason::DeferredInsufficientWindow,
                    $secondsIntoHour,
                );

                return null;
            }
        }

        if (
            $activeSlot->is_hard_anchor
            && $secondsIntoHour > $activeSlot->position_seconds + 5
        ) {
            $this->logger->warning(
                'Clock Wheel: hard anchor playhead is past anchor position.',
                [
                    'position_seconds' => $activeSlot->position_seconds,
                    'seconds_into_hour' => $secondsIntoHour,
                ]
            );
        }

        $isEndOfHour = $this->isEndOfHourMusicSlot($slots, $activeIndex);

        $queueEntry = $this->resolveSlot(
            $wheel,
            $activeSlot,
            $recentHistory,
            $availableSeconds,
            $scheduleMode,
            $station,
            $expectedPlayTime,
            $secondsIntoHour,
            $isEndOfHour,
        );

        if (null === $queueEntry && $activeSlot->is_hard_anchor) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $activeSlot,
                $expectedPlayTime,
                ClockWheelFallbackReason::HardAnchorMissed,
                $secondsIntoHour,
            );
        }

        return $queueEntry;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     *
     * @return StationClockWheelSlot[]
     */
    private function sortSlots(array $slots): array
    {
        usort(
            $slots,
            static fn (StationClockWheelSlot $a, StationClockWheelSlot $b): int =>
                $a->position_seconds <=> $b->position_seconds
                ?: $a->slot_order <=> $b->slot_order
        );

        return $slots;
    }

    /**
     * Planned position within the broadcast hour (0–3599), using expected play time
     * and already-queued items in the same hour so anchors stay aligned when the
     * AutoDJ queue is built ahead of wall clock time.
     */
    private function getPlannedSecondsIntoHour(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        DateTimeZone $tz,
    ): int {
        return $this->hourBoundaryPlanner->getPlannedSecondsIntoHour($station, $expectedPlayTime, $tz);
    }

    private function getMinWindowSeconds(StationClockWheelSlot $slot): int
    {
        if (!$this->isFlexibleMusicSlot($slot)) {
            return self::MIN_SHORT_FORM_WINDOW_SECONDS;
        }

        return match ($slot->type) {
            ClockWheelSlotTypes::Talk => self::MIN_TALK_WINDOW_SECONDS,
            ClockWheelSlotTypes::Music, null => self::MIN_MUSIC_WINDOW_SECONDS,
            default => self::MIN_MUSIC_WINDOW_SECONDS,
        };
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getActiveSlotIndex(array $slots, int $secondsIntoHour): int
    {
        $activeIndex = 0;

        foreach ($slots as $index => $slot) {
            if ($slot->position_seconds <= $secondsIntoHour) {
                $activeIndex = $index;
            } else {
                break;
            }
        }

        return $activeIndex;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function getNextAnchorSeconds(array $slots, int $activeIndex): int
    {
        if (isset($slots[$activeIndex + 1])) {
            return $slots[$activeIndex + 1]->position_seconds;
        }

        return self::HOUR_SECONDS;
    }

    private function isFlexibleMusicSlot(StationClockWheelSlot $slot): bool
    {
        if ($slot->duration_seconds !== null) {
            return false;
        }

        $type = $slot->type;

        return $type === null
            || $type === ClockWheelSlotTypes::Music
            || $type === ClockWheelSlotTypes::Talk;
    }

    /**
     * @param StationClockWheelSlot[] $slots
     */
    private function isEndOfHourMusicSlot(array $slots, int $activeIndex): bool
    {
        if (!isset($slots[$activeIndex])) {
            return false;
        }

        $slot = $slots[$activeIndex];
        if (!$this->isFlexibleMusicSlot($slot)) {
            return false;
        }

        return $this->getNextAnchorSeconds($slots, $activeIndex) >= HourBoundaryPlanner::HOUR_SECONDS;
    }

    private function resolveTopOfHourExpectedPlayAt(
        Station $station,
        DateTimeImmutable $expectedPlayTime,
    ): DateTimeImmutable {
        return $this->hourBoundaryPlanner->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        bool $isEndOfHour = false,
    ): ?StationQueue {
        $type = $slot->type;
        $categoryId = $slot->category_id;
        $playlistId = $slot->playlist_id;

        if ($type === null) {
            $this->logger->warning('Clock Wheel slot has no type — skipping.');
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoSlotType,
                $secondsIntoHour,
            );

            return null;
        }

        if (
            ClockWheelSlotPoolModes::PlaylistRotation === $slot->pool_mode
            && $playlistId !== null
            && $playlistId > 0
        ) {
            return $this->resolveSlotViaPlaylistRotation(
                $wheel,
                $slot,
                $recentHistory,
                $availableSeconds,
                $scheduleMode,
                $station,
                $expectedPlayTime,
                $secondsIntoHour,
                $isEndOfHour,
            );
        }

        $params = ['storageLocation' => $station->media_storage_location];
        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation';

        if ($playlistId !== null && $playlistId > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $playlistId;
        }

        $dql .= ' AND m.type = :type';
        $params['type'] = $type->value;

        if ($categoryId !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        /** @var StationMedia[] $candidates */
        $candidates = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        if ($candidates === []) {
            $this->logger->warning(
                sprintf(
                    'Clock Wheel slot: no media found (type=%s, category=%s, playlist=%s).',
                    $type?->value ?? '(any)',
                    $categoryId ?? '(any)',
                    $playlistId ?? '(any)',
                )
            );
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $strictSchedule = ClockWheelScheduleMode::Strict === $scheduleMode;

        $candidates = $this->filterByDuration(
            $candidates,
            $maxDuration,
            $slot,
            $strictSchedule || $isEndOfHour,
            $isEndOfHour,
        );

        if ($isEndOfHour && $candidates !== []) {
            usort(
                $candidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $b->getCalculatedLength() <=> $a->getCalculatedLength()
            );
        }

        $separationSettings = ClockWheelSeparationSettings::resolveForSlot($slot, $wheel);
        $separationResult = $this->separationChecker->apply(
            $candidates,
            $recentHistory,
            $separationSettings,
            $expectedPlayTime,
            $slot->category_id,
        );
        $candidates = $separationResult->candidates;

        if ($candidates === []) {
            $this->logger->warning(
                'Clock Wheel slot: no media fits the available window.',
                ['available_seconds' => $availableSeconds, 'max_duration' => $maxDuration]
            );
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaFitsWindow,
                $secondsIntoHour,
            );

            return null;
        }

        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Random;
        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true);

        if ($validTrack === null) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::DuplicatePreventionEmpty,
                $secondsIntoHour,
            );

            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::MediaNotFound,
                $secondsIntoHour,
            );

            return null;
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->clock_wheel = $slot->clock_wheel;
        $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
        $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
        $queueEntry->clock_wheel_enforce_cap = $this->shouldEnforcePlaybackCap(
            $slot,
            $scheduleMode,
            $media,
            $maxDuration,
            $isEndOfHour,
        );

        // Every slot in the log is eligible for stretch/squeeze -- not just the
        // one before a mandatory anchor -- so gaps close smoothly station-wide.
        // Still only within the safe +/-5% range computed in the calculator.
        $queueEntry->clock_wheel_stretch_ratio = $this->stretchCalculator->calculate(
            $media->getCalculatedLength(),
            $availableSeconds,
        );

        $this->em->persist($queueEntry);

        $this->eventLogger->recordTrackQueued(
            $station,
            $wheel,
            $slot,
            $media,
            $expectedPlayTime,
            $secondsIntoHour,
            $separationResult->separationRelaxed,
            $separationResult->burnRateWarning,
            $queueEntry,
        );

        $this->logger->info('Clock Wheel resolved track.', [
            'media_id' => $media->id,
            'title' => $media->title,
            'effective_length' => $media->getCalculatedLength(),
            'available_seconds' => $availableSeconds,
            'clock_wheel_schedule_mode' => $scheduleMode->value,
            'clock_wheel_enforce_cap' => $queueEntry->clock_wheel_enforce_cap,
        ]);

        return $queueEntry;
    }

    /**
     * Mandatory legal_id at top of hour — never returns null; promo fallback if needed.
     *
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveMandatoryLegalIdSlot(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        int $availableSeconds,
    ): ?StationQueue {
        $legalIdExpectedAt = $this->resolveTopOfHourExpectedPlayAt($station, $expectedPlayTime);
        $usedSubstitute = false;
        $fallbackReason = null;

        $candidates = $this->loadStationIdCandidates($station, $slot);

        if ($candidates === []) {
            $candidates = $this->loadMediaCandidates($station, ClockWheelSlotTypes::Promo, $slot);
            $usedSubstitute = true;
            $fallbackReason = ClockWheelFallbackReason::LegalIdMissingUsedPromo;
        }

        if ($candidates === []) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            $this->logger->error(
                'Clock Wheel top-of-hour ID: no id or promo media available — cannot queue mandatory ID.',
                ['clock_wheel_id' => $wheel->id]
            );

            return null;
        }

        // A mandatory legal ID/promo still must not overrun into the next anchor
        // slot. Use the real window before the next anchor (with a small floor
        // so a very tight window doesn't reject every candidate outright); was
        // previously hardcoded to 120s regardless of actual available time,
        // which could let a >availableSeconds clip bleed into the next slot.
        $maxDuration = $this->resolveMaxDuration($slot, max(self::MIN_SHORT_FORM_WINDOW_SECONDS, $availableSeconds));
        $allCandidates = $candidates;
        $candidates = $this->filterByDuration($allCandidates, $maxDuration, $slot, true, false);

        if ($candidates === [] && $allCandidates !== []) {
            usort(
                $allCandidates,
                static fn (StationMedia $a, StationMedia $b): int =>
                    $a->getCalculatedLength() <=> $b->getCalculatedLength()
            );
            $candidates = [$allCandidates[0]];
        }

        $mediaQueue = [];
        foreach ($candidates as $m) {
            $q = new StationPlaylistQueue();
            $q->media_id = $m->id;
            $q->spm_id = 0;
            $q->song_id = $m->song_id;
            $q->artist = $m->artist ?? '';
            $q->title = $m->title ?? '';
            $mediaQueue[] = $q;
        }

        $algorithm = $slot->algorithm ?? ClockWheelSlotAlgorithms::Sequential;
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            $algorithm = ClockWheelSlotAlgorithms::Sequential;
        }

        $mediaQueue = $this->applyAlgorithm($mediaQueue, $candidates, $algorithm, $recentHistory);

        $validTrack = $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, false)
            ?? $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentHistory, true)
            ?? $mediaQueue[0] ?? null;

        if ($validTrack === null) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                ClockWheelFallbackReason::DuplicatePreventionEmpty,
                $secondsIntoHour,
            );

            return null;
        }

        $media = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$media instanceof StationMedia) {
            return null;
        }

        if ($fallbackReason instanceof ClockWheelFallbackReason) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $legalIdExpectedAt,
                $fallbackReason,
                $secondsIntoHour,
            );
        }

        $queueEntry = StationQueue::fromMedia($station, $media);
        $queueEntry->clock_wheel = $slot->clock_wheel;
        $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
        $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
        $queueEntry->clock_wheel_enforce_cap = true;
        $queueEntry->clock_wheel_legal_id_substitute = $usedSubstitute;
        $this->em->persist($queueEntry);

        $this->eventLogger->recordTrackQueued(
            $station,
            $wheel,
            $slot,
            $media,
            $expectedPlayTime,
            $secondsIntoHour,
            false,
            false,
            $queueEntry,
            $legalIdExpectedAt,
        );

        $this->logger->info('Clock Wheel resolved mandatory legal_id.', [
            'media_id' => $media->id,
            'substitute' => $usedSubstitute,
            'expected_top_of_hour' => $legalIdExpectedAt->format(DateTimeImmutable::ATOM),
        ]);

        return $queueEntry;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     */
    private function resolveSlotViaPlaylistRotation(
        StationClockWheel $wheel,
        StationClockWheelSlot $slot,
        array $recentHistory,
        int $availableSeconds,
        ClockWheelScheduleMode $scheduleMode,
        Station $station,
        DateTimeImmutable $expectedPlayTime,
        int $secondsIntoHour,
        bool $isEndOfHour = false,
    ): ?StationQueue {
        $type = $slot->type;
        $playlist = $this->em->find(StationPlaylist::class, $slot->playlist_id);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            $this->eventLogger->recordFallback(
                $station,
                $wheel,
                $slot,
                $expectedPlayTime,
                ClockWheelFallbackReason::NoMediaCandidates,
                $secondsIntoHour,
            );

            return null;
        }

        $maxDuration = $this->resolveMaxDuration($slot, $availableSeconds);
        $strictSchedule = ClockWheelScheduleMode::Strict === $scheduleMode;
        $separationSettings = ClockWheelSeparationSettings::resolveForSlot($slot, $wheel);
        $attemptHistory = $recentHistory;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $validTrack = $this->queueBuilder->pickNextTrackFromPlaylist(
                $playlist,
                $attemptHistory,
                $attempt > 0,
            );

            if ($validTrack === null) {
                break;
            }

            $media = $this->em->find(StationMedia::class, $validTrack->media_id);
            if (!$media instanceof StationMedia) {
                continue;
            }

            if ($type !== null && $media->type !== $type->value) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            if ($slot->category_id !== null && $media->category_id !== $slot->category_id) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $durationCandidates = $this->filterByDuration(
                [$media],
                $maxDuration,
                $slot,
                $strictSchedule || $isEndOfHour,
                $isEndOfHour,
            );

            if ($durationCandidates === []) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $separationResult = $this->separationChecker->apply(
                $durationCandidates,
                $attemptHistory,
                $separationSettings,
                $expectedPlayTime,
                $slot->category_id,
            );

            if ($separationResult->candidates === []) {
                $attemptHistory = $this->appendSyntheticHistory($attemptHistory, $media, $expectedPlayTime);
                continue;
            }

            $media = $separationResult->candidates[0];

            $queueEntry = StationQueue::fromMedia($station, $media);
            $queueEntry->clock_wheel = $slot->clock_wheel;
            $queueEntry->playlist = $playlist;
            $queueEntry->clock_wheel_max_play_seconds = (int)floor($maxDuration);
            $queueEntry->clock_wheel_schedule_mode = $scheduleMode->value;
            $queueEntry->clock_wheel_enforce_cap = $this->shouldEnforcePlaybackCap(
                $slot,
                $scheduleMode,
                $media,
                $maxDuration,
                $isEndOfHour,
            );
            $queueEntry->clock_wheel_stretch_ratio = $this->stretchCalculator->calculate(
                $media->getCalculatedLength(),
                $availableSeconds,
            );
            $this->em->persist($queueEntry);

            $this->eventLogger->recordTrackQueued(
                $station,
                $wheel,
                $slot,
                $media,
                $expectedPlayTime,
                $secondsIntoHour,
                $separationResult->separationRelaxed,
                $separationResult->burnRateWarning,
                $queueEntry,
            );

            return $queueEntry;
        }

        $this->eventLogger->recordFallback(
            $station,
            $wheel,
            $slot,
            $expectedPlayTime,
            ClockWheelFallbackReason::NoMediaCandidates,
            $secondsIntoHour,
        );

        return null;
    }

    /**
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}>
     */
    private function appendSyntheticHistory(
        array $recentHistory,
        StationMedia $media,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        array_unshift($recentHistory, [
            'song_id' => $media->song_id,
            'timestamp_played' => $expectedPlayTime->getTimestamp(),
            'title' => $media->title,
            'artist' => $media->artist,
        ]);

        return $recentHistory;
    }

    /**
     * @return StationMedia[]
     */
    private function loadStationIdCandidates(
        Station $station,
        StationClockWheelSlot $slot,
    ): array {
        $params = [
            'storageLocation' => $station->media_storage_location,
            'types' => StationMediaTypes::stationIdTypeValues(),
        ];

        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation
             AND m.type IN (:types)';

        if ($slot->playlist_id !== null && $slot->playlist_id > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $slot->playlist_id;
        }

        if ($slot->category_id !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $slot->category_id;
        }

        /** @var StationMedia[] $result */
        $result = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        return array_values(array_filter(
            $result,
            static fn (StationMedia $media): bool => MediaPlayability::isEligibleForPlayback($media, null, $station->getTimezoneObject()),
        ));
    }

    /**
     * @return StationMedia[]
     */
    private function loadMediaCandidates(
        Station $station,
        ClockWheelSlotTypes $type,
        StationClockWheelSlot $slot,
    ): array {
        $params = [
            'storageLocation' => $station->media_storage_location,
            'type' => $type->value,
        ];

        $dql = 'SELECT m FROM App\Entity\StationMedia m
             WHERE m.storage_location = :storageLocation
             AND m.type = :type';

        if ($slot->playlist_id !== null && $slot->playlist_id > 0) {
            $dql .= ' AND EXISTS (
                SELECT 1 FROM App\Entity\StationPlaylistMedia spm
                WHERE spm.media = m AND spm.playlist = :playlistId
            )';
            $params['playlistId'] = $slot->playlist_id;
        }

        if ($slot->category_id !== null) {
            $dql .= ' AND m.category_id = :categoryId';
            $params['categoryId'] = $slot->category_id;
        }

        /** @var StationMedia[] $result */
        $result = $this->em->createQuery($dql)
            ->setParameters($params)
            ->getResult();

        return array_values(array_filter(
            $result,
            static fn (StationMedia $media): bool => MediaPlayability::isEligibleForPlayback($media, null, $station->getTimezoneObject()),
        ));
    }

    private function resolveMaxDuration(StationClockWheelSlot $slot, int $availableSeconds): float
    {
        if ($slot->duration_seconds !== null && $slot->duration_seconds > 0) {
            return (float)min($slot->duration_seconds, $availableSeconds);
        }

        return (float)$availableSeconds;
    }

    /**
     * When true, AutoDJ applies a cue_out cap (Liquidsoap) as a fallback after PHP track selection.
     */
    private function shouldEnforcePlaybackCap(
        StationClockWheelSlot $slot,
        ClockWheelScheduleMode $scheduleMode,
        StationMedia $media,
        float $maxDuration,
        bool $isEndOfHour = false,
    ): bool {
        if ($isEndOfHour) {
            return true;
        }

        if (ClockWheelScheduleMode::Strict === $scheduleMode) {
            return true;
        }

        if (!$this->isFlexibleMusicSlot($slot)) {
            return true;
        }

        return $media->getCalculatedLength() > $maxDuration;
    }

    private function filterByDuration(
        array $candidates,
        float $maxDuration,
        StationClockWheelSlot $slot,
        bool $strictSchedule,
        bool $isEndOfHour = false,
    ): array {
        $fitting = array_values(array_filter(
            $candidates,
            static fn (StationMedia $m): bool => $m->getCalculatedLength() <= $maxDuration
        ));

        if ($fitting !== []) {
            return $fitting;
        }

        if ($strictSchedule || $isEndOfHour) {
            $this->logger->warning(
                'Clock Wheel: no track fits before hour boundary; end-of-hour lookahead could not place a song.',
                [
                    'available_seconds' => $maxDuration,
                    'slot_type' => $slot->type?->value,
                ]
            );

            return [];
        }

        if ($candidates === []) {
            return [];
        }

        usort(
            $candidates,
            static fn (StationMedia $a, StationMedia $b): int =>
                $a->getCalculatedLength() <=> $b->getCalculatedLength()
        );

        $shortest = $candidates[0];
        $logKey = $this->isFlexibleMusicSlot($slot)
            ? 'Clock Wheel: no track fits the available window; using shortest music/talk candidate.'
            : 'Clock Wheel: no short-form track fits the available window; using shortest candidate with playback cap.';

        $this->logger->warning(
            $logKey,
            [
                'available_seconds' => $maxDuration,
                'media_id' => $shortest->id,
                'effective_length' => $shortest->getCalculatedLength(),
                'slot_type' => $slot->type?->value,
            ]
        );

        return [$shortest];
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     * @param StationMedia[] $candidates
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentHistory
     *
     * @return StationPlaylistQueue[]
     */
    private function applyAlgorithm(
        array $mediaQueue,
        array $candidates,
        ClockWheelSlotAlgorithms $algorithm,
        array $recentHistory,
    ): array {
        if ($algorithm === ClockWheelSlotAlgorithms::Random) {
            shuffle($mediaQueue);
            return $mediaQueue;
        }

        if ($algorithm === ClockWheelSlotAlgorithms::Sequential) {
            $algorithm = ClockWheelSlotAlgorithms::OldestTrack;
        }

        $histTimestamp = [];
        $histArtist = [];
        foreach ($recentHistory as $h) {
            $songId = $h['song_id'];
            $ts = $h['timestamp_played'];
            if ($ts instanceof \DateTimeInterface) {
                $ts = $ts->getTimestamp();
            }
            $ts = (int)$ts;
            if (!isset($histTimestamp[$songId]) || $ts > $histTimestamp[$songId]) {
                $histTimestamp[$songId] = $ts;
            }
            $histArtist[$songId] = $h['artist'] ?? '';
        }

        if ($algorithm === ClockWheelSlotAlgorithms::OldestTrack) {
            usort(
                $mediaQueue,
                static function (StationPlaylistQueue $a, StationPlaylistQueue $b) use ($histTimestamp): int {
                    $tsA = $histTimestamp[$a->song_id] ?? 0;
                    $tsB = $histTimestamp[$b->song_id] ?? 0;
                    return $tsA <=> $tsB;
                }
            );
            return $mediaQueue;
        }

        $isAlbum = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::MostRecentAlbum,
        ], true);
        $isOldest = in_array($algorithm, [
            ClockWheelSlotAlgorithms::OldestAlbum,
            ClockWheelSlotAlgorithms::OldestArtist,
        ], true);

        $candidatesById = [];
        $candidatesBySongId = [];
        foreach ($candidates as $m) {
            $candidatesById[$m->id] = $m;
            $candidatesBySongId[$m->song_id] = $m;
        }

        $getGroupKey = static function (StationPlaylistQueue $q) use ($candidatesById, $isAlbum): string {
            $m = $candidatesById[$q->media_id] ?? null;
            if ($m === null) {
                return '';
            }
            return strtolower(trim((string)($isAlbum ? ($m->album ?? '') : ($m->artist ?? ''))));
        };

        $groups = [];
        foreach ($mediaQueue as $q) {
            $groups[$getGroupKey($q)][] = $q;
        }

        $groupLastPlayed = array_fill_keys(array_keys($groups), 0);

        if ($isAlbum) {
            $histSongIds = array_keys($histTimestamp);
            $histAlbum = [];

            foreach ($histSongIds as $songId) {
                if (isset($candidatesBySongId[$songId])) {
                    $histAlbum[$songId] = strtolower(trim((string)($candidatesBySongId[$songId]->album ?? '')));
                }
            }

            $missingSongIds = array_diff($histSongIds, array_keys($histAlbum));
            if ($missingSongIds !== []) {
                $rows = $this->em->createQuery(
                    'SELECT m.song_id, m.album FROM App\Entity\StationMedia m WHERE m.song_id IN (:ids)'
                )->setParameter('ids', array_values($missingSongIds))->getArrayResult();
                foreach ($rows as $row) {
                    $histAlbum[$row['song_id']] = strtolower(trim((string)($row['album'] ?? '')));
                }
            }

            foreach ($histSongIds as $songId) {
                $albumKey = $histAlbum[$songId] ?? '';
                if (!array_key_exists($albumKey, $groupLastPlayed)) {
                    continue;
                }
                $ts = $histTimestamp[$songId];
                if ($ts > $groupLastPlayed[$albumKey]) {
                    $groupLastPlayed[$albumKey] = $ts;
                }
            }
        } else {
            foreach ($histTimestamp as $songId => $ts) {
                $artistKey = strtolower(trim((string)($histArtist[$songId] ?? '')));
                if (!array_key_exists($artistKey, $groupLastPlayed)) {
                    continue;
                }
                if ($ts > $groupLastPlayed[$artistKey]) {
                    $groupLastPlayed[$artistKey] = $ts;
                }
            }
        }

        $groupKeys = array_keys($groups);
        shuffle($groupKeys);
        usort($groupKeys, static function (string $a, string $b) use ($groupLastPlayed, $isOldest): int {
            $tsA = $groupLastPlayed[$a];
            $tsB = $groupLastPlayed[$b];
            return $isOldest ? ($tsA <=> $tsB) : ($tsB <=> $tsA);
        });

        $sorted = [];
        foreach ($groupKeys as $key) {
            $groupItems = $groups[$key];
            shuffle($groupItems);
            foreach ($groupItems as $q) {
                $sorted[] = $q;
            }
        }

        return $sorted;
    }
}
