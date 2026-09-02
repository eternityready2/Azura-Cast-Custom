<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\AiDjSchedule;
use App\Entity\Song;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use DateTimeZone;
use FFMpeg\FFProbe;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Keeps AI DJ welcomes, sign-offs and schedule boundaries tied to one concrete
 * scheduled shift instead of volatile process state.
 */
final class AiDjShiftLifecycleListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    private const int WELCOME_WINDOW_SECONDS = 1800;

    private const int OUTRO_WINDOW_SECONDS = 300;

    private const int OUTRO_SCAN_SECONDS = 3600;

    private const int OUTRO_SCAN_STEP_SECONDS = 30;

    private const int DIRECT_REQUEST_PREFETCH_GUARD_SECONDS = 8;

    private const int OUTRO_TAIL_RESERVE_SECONDS = 60;

    private const int STATE_GRACE_SECONDS = 3600;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly AiDjGenerator $generator,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly LinearLogPreviewContext $linearLogPreviewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run before AiDjQueueListener (priority 1). This listener only manages
        // lifecycle state and may enqueue the one deterministic shift sign-off.
        return [
            BuildQueue::class => ['onBuildQueue', 2],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($this->linearLogPreviewContext->isActive() || $event->isInterrupting()) {
            return;
        }

        if (!empty($event->getNextSongs())) {
            return;
        }

        $station = $event->getStation();
        $backend = $this->adapters->getBackendAdapter($station);
        if (!$backend instanceof Liquidsoap) {
            return;
        }

        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $expectedPlayTime = $event->getExpectedPlayTime()
            ->setTimezone($station->getTimezoneObject());
        $estimatedAirTime = $this->resolveDirectRequestAirTime($station, $now);
        if (!$estimatedAirTime instanceof DateTimeImmutable) {
            $this->blockLegacyListener($station);
            return;
        }

        $scheduleNow = $this->scheduler->findActiveSchedule($station->id, $now);
        $scheduleAtExpectedTime = $this->scheduler->findActiveSchedule($station->id, $expectedPlayTime);
        $scheduleAtAirTime = $this->scheduler->findActiveSchedule($station->id, $estimatedAirTime);

        if (
            !$scheduleNow instanceof AiDjSchedule
            || !$scheduleAtAirTime instanceof AiDjSchedule
            || $scheduleNow->getId() !== $scheduleAtAirTime->getId()
        ) {
            $this->blockLegacyListener($station);
            return;
        }

        $dj = $scheduleNow->getAiDj();
        $shift = $this->scheduler->getShiftWindow($station, $scheduleNow, $now);
        $startsAt = $shift['starts_at'];
        $endsAt = $shift['ends_at'];

        $legacyScheduleAligned = $scheduleAtExpectedTime instanceof AiDjSchedule
            && $scheduleNow->getId() === $scheduleAtExpectedTime->getId();

        if ($legacyScheduleAligned) {
            $this->syncWelcomeGuard(
                $station,
                $scheduleNow,
                $dj,
                $startsAt,
                $endsAt,
                $now,
                $estimatedAirTime,
            );
        } else {
            // AiDjQueueListener still chooses its DJ from BuildQueue's projected
            // database slot. If that slot has crossed into another shift, suppress
            // ordinary speech while allowing this listener's direct sign-off to use
            // the real next playback boundary.
            $this->blockLegacyListener($station);
        }

        $outroWindow = $this->resolveOutroWindow($station, $startsAt, $endsAt);
        if (null === $outroWindow || $estimatedAirTime < $outroWindow['starts_at']) {
            return;
        }

        // Once the final sign-off window begins, normal random talk stops. This
        // guarantees there is never ordinary chatter after the DJ has said goodbye.
        $this->blockLegacyListener($station);

        $outroKey = $this->getOutroKey($station, $scheduleNow, $startsAt);
        $alreadySignedOff = $this->cache->get($outroKey)
            || $this->hasDurableShiftMarker($station, $dj, 'AI DJ Sign-off', $startsAt, $endsAt);

        if ($alreadySignedOff) {
            $this->cache->set($outroKey, true, $this->getStateTtl($endsAt, $now));
            return;
        }

        if ($estimatedAirTime > $outroWindow['ends_at']) {
            $this->logger->warning('AI DJ: Shift sign-off window was missed.', [
                'dj' => $dj->getName(),
                'shift_end' => $endsAt->format(DATE_ATOM),
                'estimated_air_time' => $estimatedAirTime->format(DATE_ATOM),
            ]);
            return;
        }

        // The five-minute sign-off window can straddle a narrower news or TOH
        // protection interval. Validate the actual projected airtime too, not only
        // the window endpoint, so a goodbye never talks over protected content.
        if (!$this->isSafeOutroAirTime($station, $estimatedAirTime)) {
            return;
        }

        if (!$backend->isQueueEmpty($station, LiquidsoapQueues::Requests)) {
            return;
        }

        $ttl = $this->getStateTtl($endsAt, $now);
        $this->cache->set($outroKey, true, $ttl);

        if (
            !$this->pushOutroClip(
                $dj,
                $station,
                $backend,
                $scheduleNow,
                $outroWindow['starts_at'],
                $outroWindow['ends_at'],
            )
        ) {
            $this->cache->delete($outroKey);
        }
    }

    private function resolveDirectRequestAirTime(
        Station $station,
        DateTimeImmutable $now,
    ): ?DateTimeImmutable {
        $currentSongEnd = $this->getCurrentSongEndTime($station);

        if ($currentSongEnd instanceof DateTimeImmutable && $currentSongEnd > $now) {
            $remainingSeconds = $currentSongEnd->getTimestamp() - $now->getTimestamp();
            if ($remainingSeconds <= self::DIRECT_REQUEST_PREFETCH_GUARD_SECONDS) {
                return null;
            }

            return $currentSongEnd;
        }

        return $now;
    }

    private function syncWelcomeGuard(
        Station $station,
        AiDjSchedule $schedule,
        AiDj $dj,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $now,
        DateTimeImmutable $estimatedAirTime,
    ): void {
        $welcomeKey = 'ai_dj_welcomed_' . $station->id . '_' . $dj->getId();
        $identityKey = 'ai_dj_welcome_shift_' . $station->id . '_' . $dj->getId();
        $shiftIdentity = $schedule->getId() . ':' . $startsAt->getTimestamp();
        $cachedIdentity = $this->cache->get($identityKey);
        $ttl = $this->getStateTtl($endsAt, $now);

        if ($cachedIdentity === $shiftIdentity && null !== $this->cache->get($welcomeKey)) {
            return;
        }

        $welcomeAlreadyExists = $this->hasDurableShiftMarker(
            $station,
            $dj,
            'AI DJ Welcome',
            $startsAt,
            $endsAt,
        );
        $welcomeWindowEndsAt = $startsAt->modify('+' . self::WELCOME_WINDOW_SECONDS . ' seconds');
        $welcomeWindowOpen = $now >= $startsAt
            && $now < $welcomeWindowEndsAt
            && $estimatedAirTime >= $startsAt
            && $estimatedAirTime < $welcomeWindowEndsAt;

        if ($cachedIdentity !== $shiftIdentity) {
            $this->cache->set($identityKey, $shiftIdentity, $ttl);

            if (!$welcomeAlreadyExists && $welcomeWindowOpen) {
                // The existing listener detects a new shift by its last-active DJ
                // marker. Reset both legacy guards so the same DJ can legitimately
                // welcome again when assigned to a separate scheduled shift.
                $this->cache->delete($welcomeKey);
                $this->cache->delete('ai_dj_last_active_' . $station->id);
                return;
            }
        }

        if ($welcomeAlreadyExists || !$welcomeWindowOpen) {
            // Rehydrate the legacy guard after a process/cache restart. The second
            // condition also prevents a nonsensical mid-shift "welcome" hours late.
            $this->cache->set($welcomeKey, true, $ttl);
        }
    }

    /**
     * Find the latest five-minute sign-off window that remains clear of the same
     * TOH/news safety zones used by normal AI DJ breaks.
     *
     * @return array{starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}|null
     */
    private function resolveOutroWindow(
        Station $station,
        DateTimeImmutable $shiftStartsAt,
        DateTimeImmutable $shiftEndsAt,
    ): ?array {
        $latestSafe = null;

        for (
            $secondsBeforeEnd = self::OUTRO_TAIL_RESERVE_SECONDS;
            $secondsBeforeEnd <= self::OUTRO_SCAN_SECONDS;
            $secondsBeforeEnd += self::OUTRO_SCAN_STEP_SECONDS
        ) {
            $candidate = $shiftEndsAt->modify('-' . $secondsBeforeEnd . ' seconds');
            if ($candidate < $shiftStartsAt) {
                break;
            }

            if ($this->isSafeOutroAirTime($station, $candidate)) {
                $latestSafe = $candidate;
                break;
            }
        }

        if (null === $latestSafe) {
            return null;
        }

        $startsAt = $latestSafe->modify('-' . self::OUTRO_WINDOW_SECONDS . ' seconds');
        if ($startsAt < $shiftStartsAt) {
            $startsAt = $shiftStartsAt;
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $latestSafe,
        ];
    }

    private function isSafeOutroAirTime(Station $station, DateTimeImmutable $candidate): bool
    {
        $minute = (int)$candidate->format('i');
        if ($minute <= 3 || $minute >= 50) {
            return false;
        }

        if ($this->hourBoundaryPlanner->isInLookaheadZone($station, $candidate)) {
            return false;
        }

        return !$this->isNearNewsBulletin($station, $candidate);
    }

    private function isNearNewsBulletin(Station $station, DateTimeImmutable $candidate): bool
    {
        $backendConfig = $station->backend_config;
        if (!$backendConfig->ai_news_enabled) {
            return false;
        }

        $minute = (int)$candidate->format('i');

        if ($backendConfig->ai_news_top_of_hour && ($minute >= 57 || $minute <= 3)) {
            $slot = $minute >= 57
                ? $candidate
                : $candidate->modify('-1 hour');
            $slot = $slot->setTime((int)$slot->format('G'), 59, 0);

            if ($this->isAiNewsActiveAt($station, $slot)) {
                return true;
            }
        }

        if ($backendConfig->ai_news_bottom_of_hour && $minute >= 27 && $minute <= 33) {
            $slot = $candidate->setTime((int)$candidate->format('G'), 29, 0);
            return $this->isAiNewsActiveAt($station, $slot);
        }

        return false;
    }

    private function isAiNewsActiveAt(Station $station, DateTimeImmutable $candidate): bool
    {
        $backendConfig = $station->backend_config;
        $candidate = $candidate->setTimezone($station->getTimezoneObject());

        $activeDays = array_values(array_unique(array_filter(
            array_map(
                static fn(mixed $day): int => (int)$day,
                $backendConfig->ai_news_active_days,
            ),
            static fn(int $day): bool => $day >= 1 && $day <= 7,
        )));

        if ($activeDays !== [] && !in_array((int)$candidate->format('N'), $activeDays, true)) {
            return false;
        }

        $activeHours = $backendConfig->ai_news_active_hours;
        if (null === $activeHours || '' === trim($activeHours)) {
            return true;
        }

        $activeHours = trim($activeHours);
        $currentHour = (int)$candidate->format('G');
        $currentMinute = (int)$candidate->format('i');

        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startMinutes = ((int)$matches[1]) * 60 + (int)$matches[2];
            $endMinutes = ((int)$matches[3]) * 60 + (int)$matches[4];
            $nowMinutes = $currentHour * 60 + $currentMinute;

            if ($startMinutes <= $endMinutes) {
                return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
            }

            return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
        }

        return true;
    }

    private function isSafeOutroInterval(
        Station $station,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): bool {
        if ($endsAt < $startsAt) {
            return false;
        }

        $timezone = $station->getTimezoneObject();
        for ($timestamp = $startsAt->getTimestamp(); $timestamp <= $endsAt->getTimestamp(); $timestamp++) {
            $candidate = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            if (!$this->isSafeOutroAirTime($station, $candidate)) {
                return false;
            }
        }

        return true;
    }

    private function blockLegacyListener(Station $station): void
    {
        // AiDjQueueListener checks this key before it generates any speech. Refreshing
        // it on each out-of-shift/final-window BuildQueue cycle prevents pre-queued
        // expected times from leaking speech outside the actual work schedule.
        $this->cache->set('ai_dj_cooldown_' . $station->id, time(), 300);
    }

    private function getCurrentSongEndTime(Station $station): ?DateTimeImmutable
    {
        try {
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if (null === $last || null === $last->duration || $last->duration <= 0.0) {
                return null;
            }

            $endTs = $last->timestamp_start->getTimestamp() + (int)ceil($last->duration);

            return (new DateTimeImmutable('@' . $endTs))
                ->setTimezone($station->getTimezoneObject());
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Shift lifecycle could not resolve current song end.', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function hasDurableShiftMarker(
        Station $station,
        AiDj $dj,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): bool {
        try {
            // SongHistory and StationQueue timestamps are persisted in UTC. Shift
            // boundaries are deliberately calculated in station-local time, so
            // normalize the query bounds before checking durable lifecycle markers.
            $utc = new DateTimeZone('UTC');
            $startsAtUtc = $startsAt->setTimezone($utc);
            $endsAtUtc = $endsAt->setTimezone($utc);

            $historyCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(sh.id) FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.artist = :artist
                    AND sh.title = :title
                    AND sh.timestamp_start >= :startsAt
                    AND sh.timestamp_start < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', $title)
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            if ($historyCount > 0) {
                return true;
            }

            // Queue rows are also durable across process restarts and are written at
            // generation time, covering the small delay before Now Playing history is
            // persisted or a temporary history outage like the one seen in testing.
            $queueCount = (int)$this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(q.id) FROM App\Entity\StationQueue q
                    WHERE q.station = :station
                    AND q.artist = :artist
                    AND q.title = :title
                    AND q.timestamp_cued >= :startsAt
                    AND q.timestamp_cued < :endsAt
                DQL
            )->setParameter('station', $station)
                ->setParameter('artist', $dj->getName())
                ->setParameter('title', $title)
                ->setParameter('startsAt', $startsAtUtc)
                ->setParameter('endsAt', $endsAtUtc)
                ->getSingleScalarResult();

            return $queueCount > 0;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Shift marker lookup failed.', [
                'title' => $title,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getOutroKey(
        Station $station,
        AiDjSchedule $schedule,
        DateTimeImmutable $startsAt,
    ): string {
        return sprintf(
            'ai_dj_outro_%d_%d_%d',
            $station->id,
            $schedule->getId(),
            $startsAt->getTimestamp(),
        );
    }

    private function getStateTtl(DateTimeImmutable $endsAt, DateTimeImmutable $now): int
    {
        return max(60, $endsAt->getTimestamp() - $now->getTimestamp() + self::STATE_GRACE_SECONDS);
    }

    private function pushOutroClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend,
        AiDjSchedule $schedule,
        DateTimeImmutable $outroWindowStartsAt,
        DateTimeImmutable $outroWindowEndsAt,
    ): bool {
        try {
            $clipPath = $this->generator->generateShiftOutro($dj, $station);
            if (null === $clipPath) {
                return false;
            }

            // TTS is synchronous and can take long enough for the safe window to
            // close while the clip is rendering. Recompute all timing immediately
            // before enqueue so a late render can never spill past the shift or into
            // a protected TOH/news interval.
            $clipDuration = $this->getClipDurationSeconds($clipPath);
            if (null === $clipDuration || $clipDuration <= 0.0) {
                $this->logger->warning('AI DJ: Could not determine shift sign-off duration; discarding clip.', [
                    'dj' => $dj->getName(),
                    'clip' => basename($clipPath),
                ]);
                return false;
            }

            $freshNow = new DateTimeImmutable('now', $station->getTimezoneObject());
            $freshAirTime = $this->resolveDirectRequestAirTime($station, $freshNow);
            if (!$freshAirTime instanceof DateTimeImmutable) {
                return false;
            }

            $clipEndTime = $freshAirTime->modify('+' . (int)ceil($clipDuration) . ' seconds');
            $scheduleNow = $this->scheduler->findActiveSchedule($station->id, $freshNow);
            $scheduleAtAirTime = $this->scheduler->findActiveSchedule($station->id, $freshAirTime);
            $scheduleAtClipEnd = $this->scheduler->findActiveSchedule($station->id, $clipEndTime);

            if (
                !$scheduleNow instanceof AiDjSchedule
                || !$scheduleAtAirTime instanceof AiDjSchedule
                || !$scheduleAtClipEnd instanceof AiDjSchedule
                || $scheduleNow->getId() !== $schedule->getId()
                || $scheduleAtAirTime->getId() !== $schedule->getId()
                || $scheduleAtClipEnd->getId() !== $schedule->getId()
                || $freshAirTime < $outroWindowStartsAt
                || $clipEndTime > $outroWindowEndsAt
                || !$this->isSafeOutroInterval($station, $freshAirTime, $clipEndTime)
                || !$backend->isQueueEmpty($station, LiquidsoapQueues::Requests)
            ) {
                $this->logger->info('AI DJ: Discarded late or unsafe shift sign-off render.', [
                    'dj' => $dj->getName(),
                    'fresh_air_time' => $freshAirTime->format(DATE_ATOM),
                ]);
                return false;
            }

            $title = 'AI DJ Sign-off';
            $track = sprintf(
                'annotate:title="%s",artist="%s",liq_cross_duration="0",' .
                'liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",' .
                'jingle_mode="true",azuracast_autocue="false":%s',
                $title,
                $dj->getName(),
                $clipPath,
            );

            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info('AI DJ: Queued scheduled shift sign-off.', [
                'dj' => $dj->getName(),
                'clip' => basename($clipPath),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Failed to queue scheduled shift sign-off.', [
                'dj' => $dj->getName(),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getClipDurationSeconds(string $clipPath): ?float
    {
        try {
            $ffprobe = FFProbe::create([], $this->logger);
            $formatDuration = $ffprobe->format($clipPath)->get('duration');
            if (is_numeric($formatDuration) && (float)$formatDuration > 0.0) {
                return (float)$formatDuration;
            }

            foreach ($ffprobe->streams($clipPath)->audios() as $stream) {
                $streamDuration = $stream->get('duration');
                if (is_numeric($streamDuration) && (float)$streamDuration > 0.0) {
                    return (float)$streamDuration;
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('AI DJ: Failed to probe shift sign-off duration.', [
                'exception' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function createQueueEntry(
        Station $station,
        string $djName,
        string $clipPath,
        string $title,
    ): void {
        $song = Song::createFromText(sprintf('%s - %s', $djName, $title));
        $song->title = $title;
        $song->artist = $djName;

        $queueEntry = new StationQueue($station, $song);
        $queueEntry->is_visible = true;
        $queueEntry->autodj_custom_uri = $clipPath;
        $queueEntry->is_played = true;

        $this->em->persist($queueEntry);
        $this->em->flush();
    }
}
