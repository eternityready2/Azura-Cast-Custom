<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\LoggerAwareTrait;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\AiDj;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Song;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\Adapters;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use App\Radio\Backend\Liquidsoap;
use App\Radio\Enums\LiquidsoapQueues;
use App\Entity\AiDjContent;
use App\Service\AiDjArtistHistoryService;
use App\Service\AiDjContentSelector;
use App\Service\AiDjGenerator;
use App\Service\AiDjScheduler;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener that injects AI DJ audio clips into the Liquidsoap requests queue.
 *
 * Fail-open behavior: all errors are caught and logged, never blocking normal playback.
 */
final class AiDjQueueListener implements EventSubscriberInterface
{
    use LoggerAwareTrait;

    /** @var string[] Content types eligible for random liners */
    private const array LINER_TYPES = [
        AiDjContent::TYPE_BIBLE_VERSE,
        AiDjContent::TYPE_JOKE,
        AiDjContent::TYPE_ENCOURAGEMENT,
        AiDjContent::TYPE_INSPIRATION,
        AiDjContent::TYPE_TESTIMONY,
        AiDjContent::TYPE_STORY,
    ];

    /**
     * Minimum seconds the current song must have LEFT for a post-song clip to be
     * trusted to air right after it (station crossfade prefetch window ~2s + safety
     * margin). Below this, the DJ names NO specific song (plays a liner) so she can
     * never be one song stale. Tunable: raise if the live QA still shows any stale
     * names; lower if she plays too few song-naming breaks.
     */
    private const float NAME_SAFE_MIN_REMAINING_SECONDS = 8.0;

    /**
     * A played item longer than this is treated as a PROGRAM (spoken-word show,
     * sermon block, long-form segment) and is NEVER announced as if it were a
     * song. Deliberately generous: real songs — including extended live-worship
     * medleys — virtually never run past 10 minutes, while station programs
     * (e.g. the 59:27 "CMS" episode) run 30-60 minutes as one continuous file.
     * This is a duration-only backstop; the primary detection is the playlist
     * program-flag check in getCurrentSongIfSafeToName().
     */
    private const float MAX_NAMEABLE_SONG_SECONDS = 600.0;

    /**
     * Percent of eligible breaks that become a "combo": TWO segments chained into
     * ONE clip that sounds like a short conversation (single self-intro, never a
     * double introduction). Set to 0 to fully disable and restore prior behavior.
     */
    private const int COMBO_PROBABILITY_PCT = 50;

    /** Keep cadence credit through a full shift, but let it naturally reset overnight. */
    private const int TALK_CADENCE_TTL_SECONDS = 12 * 3600;

    public function __construct(
        private readonly AiDjScheduler $scheduler,
        private readonly AiDjGenerator $generator,
        private readonly AiDjContentSelector $contentSelector,
        private readonly Adapters $adapters,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly StationQueueRepository $stationQueueRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly AiDjArtistHistoryService $artistHistoryService,
        private readonly LinearLogPreviewContext $linearLogPreviewContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 1: run AFTER TopOfHourIdScheduler (priority 2) and QueueBuilder
        // so DJ clips never conflict with legal IDs or top-of-hour content.
        return [
            BuildQueue::class => ['onBuildQueue', 1],
        ];
    }

    public function onBuildQueue(BuildQueue $event): void
    {
        if ($this->linearLogPreviewContext->isActive()) {
            $this->logger->debug('AI DJ: Skipped - Linear Log preview is read-only.');
            return;
        }

        $station = $event->getStation();

        if ($event->isInterrupting()) {
            $this->logger->debug('AI DJ: Skipped - event is interrupting.');
            return;
        }

        // Skip if another listener (e.g. TopOfHourIdScheduler) already queued a song
        if (!empty($event->getNextSongs())) {
            $this->logger->debug('AI DJ: Skipped - another listener already queued songs.');
            return;
        }

        $backend = $this->adapters->getBackendAdapter($station);

        if (!($backend instanceof Liquidsoap)) {
            $this->logger->debug('AI DJ: Skipped - backend is not Liquidsoap.');
            return;
        }

        $queueEmpty = $backend->isQueueEmpty($station, LiquidsoapQueues::Requests);

        if (!$queueEmpty) {
            $this->logger->debug('AI DJ: Skipped - Liquidsoap requests queue is not empty.');
            return;
        }

        // This key is deliberately owned only by ordinary AI DJ speech. Lifecycle
        // schedule checks use separate state and must never make a skipped attempt
        // look like a DJ actually spoke.
        $cooldownKey = 'ai_dj_talk_cooldown_' . $station->id;
        $lastGenTime = $this->cache->get($cooldownKey);
        if ($lastGenTime && (time() - $lastGenTime) < 180) {
            $this->logger->debug('AI DJ: Skipped - cooldown active.', ['elapsed' => time() - $lastGenTime]);
            return;
        }

        // One DJ break at a time. A DJ clip is queued AHEAD of airtime, so a
        // time-based cooldown alone can't stop two clips ending up adjacent: a
        // clip about an earlier song can still be waiting in the queue when a new
        // one is added, so both air back-to-back ("on air = DJ, up next = DJ").
        // If a DJ clip is already waiting to air, do not queue another.
        if ($this->hasUpcomingDjClip($station)) {
            $this->logger->debug('AI DJ: Skipped - a DJ clip is already queued ahead.');
            return;
        }

        // AI DJ audio goes directly to Liquidsoap's track-sensitive Requests queue.
        // Its useful scheduling clock is therefore the boundary where that request
        // can actually air, normally the end of the song currently on air. The
        // database queue's expectedPlayTime can be several songs ahead after queue
        // projection/Linear Log work and must not decide which DJ speaks or whether
        // the direct request is inside a protected window.
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $directAirTime = $this->resolveDirectRequestAirTime($station, $now);

        // The lifecycle listener owns a separate wind-down marker once the concrete
        // sign-off has actually been queued. It carries only the shift end timestamp
        // and never touches the ordinary talk cooldown. Expired state is removed lazily
        // so a following shift is never suppressed by the previous DJ's goodbye.
        $winddownKey = 'ai_dj_shift_winddown_until_' . $station->id;
        $winddownUntil = (int)($this->cache->get($winddownKey) ?? 0);
        if ($winddownUntil > 0) {
            if ($directAirTime->getTimestamp() < $winddownUntil) {
                $this->logger->debug('AI DJ: Skipped - scheduled shift is in sign-off wind-down.');
                return;
            }

            $this->cache->delete($winddownKey);
        }

        // Skip if top-of-hour protection is active at the actual likely request airtime.
        if ($this->hourBoundaryPlanner->isInLookaheadZone($station, $directAirTime)) {
            $this->logger->debug('AI DJ: Skipped - in top-of-hour lookahead zone.');
            return;
        }

        // Also skip the first 3 minutes after the hour to let legal IDs and news finish.
        $minute = (int)$now->format('i');
        if ($minute <= 3) {
            $this->logger->debug('AI DJ: Skipped - post-hour buffer (minute ' . $minute . ').');
            return;
        }

        // Quiet window: keep the AI DJ off the air before the top of the hour so it never
        // steps on the station ID or news. A DJ request airs at the current-song boundary,
        // so use that direct airtime rather than a far-ahead database queue projection.
        $playMinute = (int)$directAirTime->format('i');
        $songEnd = $this->getCurrentSongEndTime($station);
        $endSecOfHour = -1;
        if ($songEnd !== null) {
            $endLocal = $songEnd->setTimezone($station->getTimezoneObject());
            $endSecOfHour = ((int)$endLocal->format('i')) * 60 + (int)$endLocal->format('s');
        }
        // A DJ clip airs when the current song ends and then runs ~15-30s. To keep even
        // the clip's TAIL out of the :55-:00 window, block from :54:30 (3270s into the
        // hour) onward - a break that aired at :54:55 once bled ~19s past :55.
        if ($minute >= 50 || $playMinute >= 55 || $endSecOfHour >= 3270) {
            $this->logger->debug('AI DJ: Skipped - DJ winding down before top of hour.', [
                'now_min' => $minute,
                'air_min' => $playMinute,
                'song_end_sec' => $endSecOfHour,
            ]);
            return;
        }

        // Coordinate with AI Newscaster at the time this request is likely to air.
        if ($this->isNearNewsBulletin($station, $playMinute)) {
            $this->logger->debug('AI DJ: Skipped - near AI Newscaster bulletin time.');
            return;
        }

        $dj = $this->scheduler->findActiveDj($station->id, $directAirTime);

        // Track DJ shift transitions for outro firing
        $cacheKey = 'ai_dj_last_active_' . $station->id;
        $previousDjId = $this->cache->get($cacheKey);
        $currentDjId = $dj?->getId() ?? null;

        $this->cache->set($cacheKey, $currentDjId, 3600);

        // The outgoing DJ's sign-off (pushOutroClip) is intentionally not fired here.
        // It only ever triggered on a shift change, the same cycle that queues the new
        // DJ's welcome below, so the two clips would air back-to-back with no song
        // between them. Keeping only the welcome guarantees a break is always a single
        // clip.

        // Fire the shift intro when a new DJ block begins. Only one clip per break.
        if ($currentDjId !== null && $previousDjId !== $currentDjId && $dj instanceof AiDj) {
            // The direct request can be prepared before a shift boundary, but a welcome
            // must never air before the scheduled shift has actually begun. If the
            // direct-airtime DJ differs from the DJ active right now, wait for a later
            // BuildQueue cycle rather than announcing the next DJ early.
            $djNow = $this->scheduler->findActiveDj($station->id, $now);
            if (!$djNow instanceof AiDj || $djNow->getId() !== $currentDjId) {
                $this->cache->set($cacheKey, $previousDjId, 3600);
                $this->trackCurrentSong($station);
                return;
            }

            // Welcome once per shift. The 'ai_dj_last_active' key (3600s TTL) is only
            // refreshed when this listener runs on a BuildQueue event. During a long
            // single-file program (e.g. a ~59-minute block) no track is requested, no
            // BuildQueue fires, the key expires, and on music-resume $previousDjId reads
            // null while the same DJ is still on shift, which would cause a duplicate
            // welcome. This per-DJ guard survives that gap; a genuine DJ change (different
            // id) has a different, absent key and so still welcomes.
            $welcomedKey = 'ai_dj_welcomed_' . $station->id . '_' . $currentDjId;
            if (null === $this->cache->get($welcomedKey)) {
                // Long enough to survive a program gap while remaining shift-local in practice.
                $this->cache->set($welcomedKey, time(), 72000);
                $this->pushIntroShiftClip($dj, $station, $backend);
                $this->cache->set($cooldownKey, time(), 300);
                $this->trackCurrentSong($station);
                return;
            }
            // Same DJ, already welcomed this shift -> do NOT repeat; fall through to
            // normal post-song / liner handling below.
        }

        if (null === $dj) {
            $this->logger->debug('AI DJ: No active DJ for direct request airtime.');
            return;
        }

        $this->logger->info('AI DJ: Active DJ found.', ['dj_name' => $dj->getName()]);

        // Treat talk_frequency as the share of ELIGIBLE song boundaries that should
        // contain a DJ break. The previous independent random roll could produce long
        // unlucky silent streaks (and materially under-deliver over a single shift).
        // Fractional credit keeps the same configured percentage while making the
        // cadence stable: 0.5 => about every second eligible boundary; 0.75 => about
        // three of every four. Safety windows and the 3-minute cooldown still win.
        $frequency = $dj->getTalkFrequency();
        if ($frequency <= 0.0) {
            $this->logger->debug('AI DJ: Skipped - talk frequency is zero.');
            $this->trackCurrentSong($station);
            return;
        }

        $cadenceKey = 'ai_dj_talk_cadence_' . $station->id . '_' . $dj->getId();
        $cadenceCredit = (float)($this->cache->get($cadenceKey) ?? 0.0) + $frequency;
        if ($cadenceCredit < 1.0) {
            $this->cache->set($cadenceKey, $cadenceCredit, self::TALK_CADENCE_TTL_SECONDS);
            $this->logger->debug('AI DJ: Skipped by talk cadence.', [
                'frequency' => $frequency,
                'credit' => $cadenceCredit,
            ]);
            $this->trackCurrentSong($station);
            return;
        }

        // Reserve this eligible break before synchronous TTS starts. This keeps the
        // original anti-parallel behavior: another queue request arriving while a clip
        // is rendering sees the cooldown and cannot generate a second adjacent DJ clip.
        $this->cache->set(
            $cadenceKey,
            max(0.0, $cadenceCredit - 1.0),
            self::TALK_CADENCE_TTL_SECONDS,
        );
        $this->cache->set($cooldownKey, time(), 300);

        // Name the current song only when the clip will air right after it. The clip
        // goes to the track_sensitive "requests" queue, but the station's crossfade
        // (enable_crossfade, default_fade ~2s) prefetches the next music track a couple
        // seconds before a boundary. If this break fires inside that prefetch window,
        // the next song is already locked in, so the clip airs after it and "that was
        // <current>" would be one song stale. getCurrentSongIfSafeToName() returns the
        // current song only when it has comfortably more time left than the prefetch
        // window (the clip wins the boundary, so the name is correct); otherwise it
        // returns null and a liner is played below, so the DJ is never confidently wrong
        // about a song name.
        $currentSong = $this->getCurrentSongIfSafeToName($station);
        $curArtist = $currentSong['artist'] ?? null;
        $curTitle = $currentSong['title'] ?? null;

        // The next music track is usually NOT queued yet when the DJ fires, so only
        // use it when it is genuinely known.
        $nextMusicEntry = $this->findNextMusicEntry($station);
        $nextArtist = $nextMusicEntry?->artist;
        $nextTitle = $nextMusicEntry?->title;

        $roll = mt_rand(1, 100);
        $wantCombo = (mt_rand(1, 100) <= self::COMBO_PROBABILITY_PCT);

        if ($wantCombo) {
            // Occasionally chain TWO segments into ONE clip so the DJ sounds like
            // she's having a short conversation (single self-intro, no double
            // introduction). Fails open to the single-segment paths on any error.
            $this->pushComboClip($dj, $curArtist, $curTitle, $station, $backend);
        } elseif ($curArtist !== null && $curArtist !== '') {
            // Announce only the song that just played, one song per break for a clean,
            // natural flow. Never pass a "next" song, so the DJ never chains several
            // song names together in a single break.
            if ($roll <= 45) {
                $this->pushPostSongClip($dj, $curArtist, $curTitle, null, null, $station, $backend);
            } elseif ($roll <= 65) {
                // A short fun fact about the artist that just played. Fetched
                // safely (short timeout + cache); falls back to a content liner
                // if nothing is found so it never delays or stalls playback.
                $this->pushArtistHistoryClip($dj, $curArtist, $station, $backend);
            } else {
                $this->pushContentLiner($dj, $station, $backend);
            }
        } else {
            // No reliably known song — play a content liner, never a generic filler.
            $this->pushContentLiner($dj, $station, $backend);
        }

        $this->trackCurrentSong($station);
    }

    private function trackCurrentSong(Station $station): void
    {
        // Record the song that is actually on air (reliable), not the upcoming
        // queue entry, which is usually empty when the DJ fires.
        $current = $this->getCurrentPlayingSong($station);

        if ($current !== null && ($current['artist'] ?? null) !== null) {
            $this->cache->set('ai_dj_prev_song_' . $station->id, [
                'artist' => $current['artist'],
                'title' => $current['title'],
            ], 600);
        }
    }

    /**
     * The current on-air song IF it is safe to name it in a post-song clip — i.e.
     * the clip will provably air right after it. Returns null when the song is within
     * the crossfade prefetch window of ending (the next track is likely already
     * locked in, so the clip would air one song late and name a stale song). On null,
     * callers play a content liner, so the DJ never speaks a wrong song name.
     *
     * @return array{artist: ?string, title: ?string}|null
     */
    private function getCurrentSongIfSafeToName(Station $station): ?array
    {
        try {
            /** @var \App\Entity\SongHistory|null $last */
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    AND sh.artist IS NOT NULL
                    AND sh.artist != :empty
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('empty', '')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            // No reliable timing -> don't risk a stale name; caller uses a liner.
            if ($last === null || $last->duration === null || $last->duration <= 0.0) {
                return null;
            }

            // A program is not a song and must never be named as one, even a short
            // episode. Detect it exactly the way the Liquidsoap config does
            // (ConfigWriter: remote-URL feed, "play single track", or merged
            // block), so this works generically without a station-specific
            // playlist-ID list.
            $playlist = $last->playlist;
            if (
                null !== $playlist
                && (
                    PlaylistSources::RemoteUrl === $playlist->source
                    || $playlist->backendPlaySingleTrack()
                    || $playlist->backendMerge()
                )
            ) {
                $this->logger->debug(
                    'AI DJ: current item is a program playlist; using a liner instead of naming it.',
                    ['playlist_id' => $last->playlist_id]
                );
                return null;
            }

            // Backstop for items with no usable playlist metadata (playlist_id
            // nulled on delete, request/manual plays): a very long single item is
            // a program, not a song. See MAX_NAMEABLE_SONG_SECONDS.
            if ($last->duration > self::MAX_NAMEABLE_SONG_SECONDS) {
                $this->logger->debug(
                    'AI DJ: current item exceeds max song length; treating as a program, using a liner.',
                    ['duration' => $last->duration, 'cap' => self::MAX_NAMEABLE_SONG_SECONDS]
                );
                return null;
            }

            $elapsed = time() - $last->timestamp_start->getTimestamp();
            $remaining = $last->duration - (float)$elapsed;

            if ($remaining <= self::NAME_SAFE_MIN_REMAINING_SECONDS) {
                $this->logger->debug(
                    'AI DJ: current song near its end; using a liner to avoid naming a stale song.',
                    ['remaining' => $remaining, 'threshold' => self::NAME_SAFE_MIN_REMAINING_SECONDS]
                );
                return null;
            }

            return [
                'artist' => $last->artist,
                'title' => $last->title,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: safe-name check failed: %s', $e->getMessage()));
            return null;
        }
    }

    /**
     * Get the song currently (most recently) on air from play history.
     * Always accurate, unlike the upcoming queue which is typically empty at the
     * moment the AI DJ decides to speak.
     *
     * @return array{artist: ?string, title: ?string}|null
     */
    private function getCurrentPlayingSong(Station $station): ?array
    {
        try {
            $last = $this->em->createQuery(
                <<<'DQL'
                    SELECT sh FROM App\Entity\SongHistory sh
                    WHERE sh.station = :station
                    AND sh.is_visible = 1
                    AND sh.media IS NOT NULL
                    AND sh.artist IS NOT NULL
                    AND sh.artist != :empty
                    ORDER BY sh.timestamp_start DESC
                DQL
            )->setParameter('station', $station)
                ->setParameter('empty', '')
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($last === null) {
                return null;
            }

            return [
                'artist' => $last->artist,
                'title' => $last->title,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to load current song from history: %s', $e->getMessage()));
            return null;
        }
    }

    /**
     * Resolve when a track-sensitive Liquidsoap request is actually likely to air.
     * If the current song has a trustworthy future end, that boundary is the request
     * airtime. Otherwise fail open to now rather than a projected DB queue slot.
     */
    private function resolveDirectRequestAirTime(
        Station $station,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        $currentSongEnd = $this->getCurrentSongEndTime($station);

        if ($currentSongEnd instanceof DateTimeImmutable && $currentSongEnd > $now) {
            return $currentSongEnd;
        }

        return $now;
    }

    /**
     * Projected end time (= break airtime) of the song currently on air. A DJ clip
     * enqueues to Requests and airs when the current song finishes, so this is the
     * most accurate estimate of when the clip is actually heard - more reliable than
     * the queue's expectedPlayTime, which can be far ahead of the direct request.
     */
    private function getCurrentSongEndTime(Station $station): ?\DateTimeImmutable
    {
        try {
            /** @var \App\Entity\SongHistory|null $last */
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

            if ($last === null || $last->duration === null || $last->duration <= 0.0) {
                return null;
            }

            $endTs = $last->timestamp_start->getTimestamp() + (int)ceil($last->duration);
            return (new \DateTimeImmutable('@' . $endTs))->setTimezone($station->getTimezoneObject());
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: song-end timing check failed: %s', $e->getMessage()));
            return null;
        }
    }

    /**
     * Find the next queued music entry (not AI DJ clips) for the station.
     */
    private function findNextMusicEntry(Station $station): ?StationQueue
    {
        $upcomingQueue = $this->stationQueueRepo->getUnplayedQueue($station);

        foreach ($upcomingQueue as $entry) {
            if ($entry->media !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Content categories the DJ may use as random liners: any ENABLED content
     * type for this station except the intro/post-song templates. Dynamic (not a
     * fixed list) so custom categories are used automatically once they have
     * content, and disabling/emptying a category removes it from rotation.
     * Falls back to the built-in set if nothing is configured.
     *
     * @return string[]
     */
    private function getLinerTypes(Station $station): array
    {
        $excluded = [
            AiDjContent::TYPE_SONG_INTRO_TEMPLATE,
            AiDjContent::TYPE_POST_SONG_TEMPLATE,
        ];

        try {
            /** @var string[] $types */
            $types = $this->em->createQuery(
                <<<'DQL'
                    SELECT DISTINCT c.type FROM App\Entity\AiDjContent c
                    WHERE c.station = :station AND c.is_enabled = true
                DQL
            )->setParameter('station', $station)->getSingleColumnResult();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to load liner categories: %s', $e->getMessage()));
            return self::LINER_TYPES;
        }

        $types = array_values(array_filter(
            $types,
            static fn(string $t): bool => !in_array($t, $excluded, true)
        ));

        return $types !== [] ? $types : self::LINER_TYPES;
    }

    /**
     * True if an AI DJ clip is already queued (unplayed) and waiting to air.
     * DJ clips have no media and their custom URI points at the station's ai_dj dir.
     */
    private function hasUpcomingDjClip(Station $station): bool
    {
        foreach ($this->stationQueueRepo->getUnplayedQueue($station) as $entry) {
            $uri = $entry->autodj_custom_uri;
            if ($entry->media === null && $uri !== null && str_contains($uri, 'ai_dj')) {
                return true;
            }
        }

        return false;
    }

    private function pushIntroClip(
        AiDj $dj,
        ?string $artist,
        ?string $songTitle,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateSongIntro($dj, $artist, $songTitle, $station);

            if (null === $clipPath) {
                $this->logger->warning('AI DJ: Failed to generate intro clip, continuing normal playback.');
                return;
            }

            $track = sprintf('annotate:title="AI DJ Intro",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath);

            $this->logger->info(sprintf(
                'AI DJ: Queued intro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push intro clip: %s', $e->getMessage()));
        }
    }

    private function pushPostSongClip(
        AiDj $dj,
        ?string $prevArtist,
        ?string $prevTitle,
        ?string $nextArtist,
        ?string $nextTitle,
        Station $station,
        Liquidsoap $backend
    ): void {
        // Never announce the same song twice in a row. If it would repeat, use a
        // generic liner with no song name instead of restating a stale/duplicate title.
        if ($prevArtist !== null && $prevArtist !== '') {
            $namedKey = 'ai_dj_last_named_' . $station->id;
            $songKey = strtolower(trim($prevArtist . ' - ' . ($prevTitle ?? '')));
            if ($this->cache->get($namedKey) === $songKey) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }
            $this->cache->set($namedKey, $songKey, 1800);
        }

        try {
            $clipPath = $this->generator->generatePostSong(
                $dj,
                $prevArtist,
                $prevTitle,
                $nextArtist,
                $nextTitle,
                $station
            );

            if (null === $clipPath) {
                // Fallback to content liner if post-song generation fails
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            // Keep the semantic break type in both the Liquidsoap metadata and the
            // StationQueue row so Past Playout History can distinguish this from liners.
            $title = 'Song Commentary';
            $track = sprintf('annotate:title="%s",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $title, $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info(sprintf(
                'AI DJ: Queued post-song clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push post-song clip: %s', $e->getMessage()));
        }
    }

    private function createQueueEntry(Station $station, string $djName, string $clipPath, string $title = 'AI DJ Intro'): void
    {
        try {
            $song = Song::createFromText(sprintf('%s - %s', $djName, $title));
            $song->title = $title;
            $song->artist = $djName;

            $queueEntry = new StationQueue($station, $song);
            $queueEntry->is_visible = true;
            $queueEntry->autodj_custom_uri = $clipPath;
            // Already played via the Requests queue (enqueue above), so mark it sent/played
            // to prevent the main next_song queue from also playing it. Still visible for
            // now-playing/history.
            $queueEntry->is_played = true;

            $this->em->persist($queueEntry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to create StationQueue entry: %s', $e->getMessage()));
        }
    }

    private function pushArtistHistoryClip(
        AiDj $dj,
        ?string $artist,
        Station $station,
        Liquidsoap $backend
    ): void {
        if ($artist === null || $artist === '') {
            $this->pushContentLiner($dj, $station, $backend);
            return;
        }

        try {
            $historyText = $this->artistHistoryService->getArtistHistory(
                $artist,
                $this->generator->getSpokenName($dj->getName()),
                $station->name
            );
            if ($historyText === null) {
                // Nothing found — fall back to a content liner so she still talks.
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $outputDir = '/var/azuracast/stations/' . $station->id . '/ai_dj';
            $outputPath = $outputDir . '/artist_' . uniqid() . '.mp3';
            $clipPath = $this->generator->generateAudio(
                $historyText,
                $dj->getVoiceModelPath(),
                $outputPath,
                $dj->getVoiceSpeed(),
                $dj->useBackgroundAudio()
            );

            if ($clipPath === null) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            $track = sprintf('annotate:title="Artist Spotlight",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'Artist Spotlight');

            $this->logger->info(sprintf(
                'AI DJ: Queued artist history clip for DJ "%s" (artist: %s)',
                $dj->getName(),
                $artist
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push artist history clip: %s', $e->getMessage()));
            $this->pushContentLiner($dj, $station, $backend);
        }
    }

    /**
     * Pick one enabled liner content item, optionally excluding a type so the two
     * halves of a combo are different categories. Returns null if none available.
     */
    private function selectLinerContent(AiDj $dj, Station $station, ?string $excludeType): ?AiDjContent
    {
        $linerTypes = $this->getLinerTypes($station);
        // Keep long, self-contained content out of combos. Testimonies and stories average
        // well over the per-segment budget (COMBO_SEGMENT_CHARS = 230), and truncateForTts
        // only keeps complete sentences, so a truncated testimony or story can lose its
        // payoff mid-narrative. These content types still air in full as standalone liners;
        // combos are built from shorter content only.
        $comboExcluded = [AiDjContent::TYPE_TESTIMONY, AiDjContent::TYPE_STORY];
        $linerTypes = array_values(array_filter(
            $linerTypes,
            static fn(string $t): bool => !in_array($t, $comboExcluded, true) && $t !== $excludeType
        ));
        if ($linerTypes === []) {
            return null;
        }
        $type = $linerTypes[array_rand($linerTypes)];
        return $this->contentSelector->selectContent($dj->getId(), $type, $station->id);
    }

    /**
     * Queue a COMBO break: two segments rendered as ONE clip. Segment 1 carries
     * the only self-intro (post-song mention OR artist history OR an intro-bearing
     * liner); segment 2 is a DIFFERENT, intro-free liner. Any failure falls open
     * to a normal single-segment content liner. One enqueue + one queue entry, so
     * the "one clip per break" invariant the cooldown/dedup guards rely on holds.
     */
    private function pushComboClip(
        AiDj $dj,
        ?string $curArtist,
        ?string $curTitle,
        Station $station,
        Liquidsoap $backend
    ): void {
        $enqueued = false;
        try {
            $introText = null;
            $usedType = null;
            $segment1Title = 'Song Commentary';
            $haveSong = ($curArtist !== null && $curArtist !== '');

            // Segment 1, option A: post-song mention (respect the "don't name the
            // same song twice" guard — same cache key as pushPostSongClip).
            if ($haveSong && mt_rand(0, 1) === 1) {
                $namedKey = 'ai_dj_last_named_' . $station->id;
                $songKey = strtolower(trim($curArtist . ' - ' . ($curTitle ?? '')));
                if ($this->cache->get($namedKey) !== $songKey) {
                    $this->cache->set($namedKey, $songKey, 1800);
                    $introText = $this->generator->buildPostSongText($dj, $curArtist, $curTitle, null, null, $station);
                }
            }

            // Segment 1, fallback: an intro-bearing content liner.
            // Artist history is deliberately not used as a combo segment. Its full script
            // (intro + facts + closer) runs well over the per-segment budget, and
            // truncateForTts only keeps complete sentences, so the facts sentence would be
            // dropped and only the opening promise of a fact would survive with no fact
            // behind it. Artist history stays a full standalone break (pushArtistHistoryClip)
            // where it airs untruncated with the real facts intact.
            if ($introText === null) {
                $c1 = $this->selectLinerContent($dj, $station, null);
                if ($c1 === null) {
                    $this->pushContentLiner($dj, $station, $backend);
                    return;
                }
                $introText = $this->generator->buildLinerText($dj, $c1, $station, true);
                $usedType = $c1->type;
                $segment1Title = $this->getLinerTitle($c1->type);
            }

            // Segment 2: a DIFFERENT liner type, rendered intro-free. If none is
            // available the combo degrades to a valid single-segment clip.
            $c2 = $this->selectLinerContent($dj, $station, $usedType);
            $payloadText = $c2 !== null ? $this->generator->buildLinerText($dj, $c2, $station, false) : '';
            $segment2Title = $c2 !== null ? $this->getLinerTitle($c2->type) : null;
            $title = $segment2Title !== null && $segment2Title !== $segment1Title
                ? $segment1Title . ' + ' . $segment2Title
                : $segment1Title;

            $clipPath = $this->generator->generateComboBreak($dj, $introText, $payloadText, $station);
            if (null === $clipPath) {
                $this->pushContentLiner($dj, $station, $backend);
                return;
            }

            // Preserve both combo segment categories in the metadata. This is what
            // Past Playout History receives from Liquidsoap feedback.
            $track = sprintf('annotate:title="%s",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $title, $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $enqueued = true;
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info(sprintf(
                'AI DJ: Queued COMBO clip for DJ "%s" (segment2: %s, clip: %s)',
                $dj->getName(),
                $c2?->type ?? 'single',
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push combo clip: %s', $e->getMessage()));
            // Only fail open if nothing was enqueued yet; otherwise a post-enqueue
            // throw here would result in a second clip airing for the same break.
            if (!$enqueued) {
                $this->pushContentLiner($dj, $station, $backend);
            }
        }
    }

    private function getLinerTitle(string $type): string
    {
        return match ($type) {
            AiDjContent::TYPE_BIBLE_VERSE => 'Bible Verse',
            AiDjContent::TYPE_JOKE => 'Joke',
            AiDjContent::TYPE_ENCOURAGEMENT => 'Encouragement',
            AiDjContent::TYPE_INSPIRATION => 'Inspiration',
            AiDjContent::TYPE_TESTIMONY => 'Testimony',
            AiDjContent::TYPE_STORY => 'Story',
            default => ucwords(str_replace(['_', '-'], ' ', $type)),
        };
    }

    private function pushContentLiner(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $linerTypes = $this->getLinerTypes($station);
            $type = $linerTypes[array_rand($linerTypes)];
            $content = $this->contentSelector->selectContent($dj->getId(), $type, $station->id);

            if (null === $content) {
                $this->logger->debug('AI DJ: No content available for liner.', ['type' => $type]);
                return;
            }

            $clipPath = $this->generator->generateContentLiner($dj, $content, $station);

            if (null === $clipPath) {
                return;
            }

            $title = $this->getLinerTitle($content->type);

            $track = sprintf('annotate:title="%s",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $title, $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, $title);

            $this->logger->info(sprintf(
                'AI DJ: Queued %s liner for DJ "%s" (clip: %s)',
                $content->type,
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push content liner: %s', $e->getMessage()));
        }
    }

    /**
     * Check if current time is near a scheduled AI Newscaster bulletin.
     * Avoids DJ speech 3 minutes before and after news times (:00 and/or :30).
     */
    private function isNearNewsBulletin(Station $station, int $minute): bool
    {
        $backendConfig = $station->backend_config;

        if (!$backendConfig->ai_news_enabled) {
            return false;
        }

        // Top-of-hour news: skip the last 3 minutes of the hour (:57-:59) and the
        // first 3 minutes of the next hour (:00-:03). The AI DJ's separate
        // post-hour buffer (minute <= 3, checked earlier in onBuildQueue) already
        // covers :00-:03 incidentally, but this guard is what actually protects
        // :57-:59, matching the symmetric 3-minutes-before/after behavior already
        // applied to bottom-of-hour news below.
        if ($backendConfig->ai_news_top_of_hour && ($minute >= 57 || $minute <= 3)) {
            return true;
        }

        // Bottom-of-hour news: skip minutes 27-33
        if ($backendConfig->ai_news_bottom_of_hour && $minute >= 27 && $minute <= 33) {
            return true;
        }

        return false;
    }

    private function pushIntroShiftClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateShiftIntro($dj, $station);

            if (null === $clipPath) {
                return;
            }

            $track = sprintf('annotate:title="AI DJ Welcome",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'AI DJ Welcome');

            $this->logger->info(sprintf(
                'AI DJ: Queued shift intro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push shift intro clip: %s', $e->getMessage()));
        }
    }

    private function pushOutroClip(
        AiDj $dj,
        Station $station,
        Liquidsoap $backend
    ): void {
        try {
            $clipPath = $this->generator->generateShiftOutro($dj, $station);

            if (null === $clipPath) {
                return;
            }

            $track = sprintf('annotate:title="AI DJ Sign-off",artist="%s",liq_cross_duration="0",liq_fade_in="0",liq_fade_out="0",liq_cue_in="0",jingle_mode="true",azuracast_autocue="false":%s', $dj->getName(), $clipPath);
            $backend->enqueue($station, LiquidsoapQueues::Requests, $track);
            $this->createQueueEntry($station, $dj->getName(), $clipPath, 'AI DJ Sign-off');

            $this->logger->info(sprintf(
                'AI DJ: Queued outro clip for DJ "%s" (clip: %s)',
                $dj->getName(),
                basename($clipPath)
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('AI DJ: Failed to push outro clip: %s', $e->getMessage()));
        }
    }
}
