<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationRequestRepository;
use App\Entity\Repository\SongHistoryRepository;
use App\Entity\Song;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel;
use App\Radio\PlaylistParser;
use App\Radio\SmartBlock\SmartBlockPlaybackPreparer;
use App\Service\HolidayOverrideService;
use DateTimeImmutable;
use DateTimeZone;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The internal steps of the AutoDJ Queue building process.
 */
final class QueueBuilder implements EventSubscriberInterface
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly SponsorGuaranteedPlayoutService $sponsorGuarantee,
        private readonly DuplicatePrevention $duplicatePrevention,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
        private readonly ClockWheel\ClockWheelStretchCalculator $stretchCalculator,
        private readonly CacheInterface $cache,
        private readonly StationPlaylistGroupMemberRepository $groupMemberRepo,
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationRequestRepository $requestRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly SongHistoryRepository $historyRepo,
        private readonly HolidayOverrideService $holidayOverrideService,
        private readonly SmartBlockPlaybackPreparer $smartBlockPlaybackPreparer,
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['getNextSongFromRequests', 5],
                ['calculateNextSong', 0],
            ],
        ];
    }

    /**
     * Determine the next-playing song for this station based on its playlist rotation rules.
     *
     * @param BuildQueue $event
     */
    public function calculateNextSong(BuildQueue $event): void
    {
        if (!empty($event->getNextSongs())) {
            return;
        }

        $this->logger->info('AzuraCast AutoDJ is calculating the next song to play...');

        $this->smartBlockPlaybackPreparer->beginQueueBuild();

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        $tz = $station->getTimezoneObject();

        $sponsorPlaylistIdsBehindPace = [];
        if ($event->isInterrupting()) {
            foreach ($this->sponsorGuarantee->getPlaylistsBehindPace($station, $expectedPlayTime) as $sponsorPlaylist) {
                $sponsorPlaylistIdsBehindPace[$sponsorPlaylist->id] = true;
            }
        }

        $activePlaylistsByType = [];
        $groupMemberPlaylistIds = array_fill_keys(
            $this->groupMemberRepo->getChildPlaylistIds($station),
            true
        );

        foreach ($station->playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if (isset($groupMemberPlaylistIds[$playlist->id])) {
                continue;
            }

            $isEligible = $playlist->isPlayable($event->isInterrupting())
                || ($event->isInterrupting()
                    && $this->scheduler->isPlaylistStrictStartDueNow($playlist, $tz, $expectedPlayTime))
                || ($event->isInterrupting() && isset($sponsorPlaylistIdsBehindPace[$playlist->id]));

            if ($isEligible) {
                $type = $playlist->type->value;

                $subType = ($playlist->schedule_items->count() > 0) ? 'scheduled' : 'unscheduled';
                $activePlaylistsByType[$type . '_' . $subType][$playlist->id] = $playlist;
            }
        }

        if (empty($activePlaylistsByType)) {
            $this->logger->warning('No valid playlists detected. Skipping AutoDJ calculations.');
            return;
        }

        $recentSongHistoryForDuplicatePrevention = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->backend_config->duplicate_prevention_time_range
        );

        $holidayPlaylist = $this->holidayOverrideService->getHolidayPlaylist($station, $expectedPlayTime);
        if ($holidayPlaylist !== null) {
            foreach ([false, true] as $allowDuplicates) {
                if (
                    $event->setNextSongs(
                        $this->playSongFromPlaylist(
                            $holidayPlaylist,
                            $recentSongHistoryForDuplicatePrevention,
                            $expectedPlayTime,
                            $allowDuplicates
                        )
                    )
                ) {
                    $this->logger->info(
                        'Holiday override playlist is active.',
                        ['playlist_id' => $holidayPlaylist->id]
                    );

                    return;
                }
            }
        }

        $this->logger->debug(
            'AutoDJ recent song playback history',
            [
                'history_duplicate_prevention' => $recentSongHistoryForDuplicatePrevention,
            ]
        );

        $typesToPlay = [
            PlaylistTypes::OncePerHour->value,
            PlaylistTypes::OncePerXSongs->value,
            PlaylistTypes::OncePerXMinutes->value,
            PlaylistTypes::Standard->value,
        ];
        $typesToPlayByPriority = [];
        foreach ($typesToPlay as $type) {
            $typesToPlayByPriority[] = $type . '_scheduled';
            $typesToPlayByPriority[] = $type . '_unscheduled';
        }

        foreach ($typesToPlayByPriority as $currentPlaylistType) {
            if (empty($activePlaylistsByType[$currentPlaylistType])) {
                continue;
            }

            $eligiblePlaylists = [];
            $logPlaylists = [];
            foreach ($activePlaylistsByType[$currentPlaylistType] as $playlistId => $playlist) {
                /** @var StationPlaylist $playlist */
                if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime)) {
                    continue;
                }

                $eligiblePlaylists[$playlistId] = $playlist->weight;

                $logPlaylists[] = [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'weight' => $playlist->weight,
                ];
            }

            if (empty($eligiblePlaylists)) {
                continue;
            }

            $this->logger->info(
                sprintf(
                    '%d playable playlist(s) of type "%s" found.',
                    count($eligiblePlaylists),
                    $currentPlaylistType
                ),
                ['playlists' => $logPlaylists]
            );

            $eligiblePlaylists = $this->weightedShuffle($eligiblePlaylists);

            // Loop through the playlists and attempt to play them with no duplicates first,
            // then loop through them again while allowing duplicates.
            foreach ([false, true] as $allowDuplicates) {
                foreach ($eligiblePlaylists as $playlistId => $weight) {
                    $playlist = $activePlaylistsByType[$currentPlaylistType][$playlistId];

                    if (
                        $event->setNextSongs(
                            $this->playSongFromPlaylist(
                                $playlist,
                                $recentSongHistoryForDuplicatePrevention,
                                $expectedPlayTime,
                                $allowDuplicates
                            )
                        )
                    ) {
                        $this->logger->info(
                            'Playable track(s) found and registered.',
                            [
                                'next_song' => (string)$event,
                            ]
                        );
                        return;
                    }
                }
            }
        }

        if ($event->isInterrupting()) {
            $this->logger->info('No interrupting tracks to play.');
        } else {
            $this->logger->error('No playable tracks were found.');
        }
    }

    /**
     * Apply a weighted shuffle to the given array in the form:
     *  [ key1 => weight1, key2 => weight2 ]
     *
     * Based on: https://gist.github.com/savvot/e684551953a1716208fbda6c4bb2f344
     *
     * @param array $original
     * @return array
     */
    private function weightedShuffle(array $original): array
    {
        $new = $original;
        $max = 1.0 / mt_getrandmax();

        array_walk(
            $new,
            static function (&$value) use ($max): void {
                $value = (mt_rand() * $max) ** (1.0 / $value);
            }
        );

        arsort($new);

        array_walk(
            $new,
            static function (&$value, $key) use ($original): void {
                $value = $original[$key];
            }
        );

        return $new;
    }

    /**
     * Given a specified (sequential or shuffled) playlist, choose a song from the playlist to play and return it.
     *
     * @param StationPlaylist $playlist
     * @param array $recentSongHistory
     * @param DateTimeImmutable $expectedPlayTime
     * @param bool $allowDuplicates Whether to return a media ID even if duplicates can't be prevented.
     * @param bool $singleTrackOnly Whether an array-producing playlist should return only its first track.
     * @param bool $deferQueuePersistence Whether StationQueue rows should be persisted by the caller.
     * @return StationQueue|StationQueue[]|null
     */
    private function playSongFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates = false,
        bool $singleTrackOnly = false,
        bool $deferQueuePersistence = false,
    ): StationQueue|array|null {
        if (!$this->smartBlockPlaybackPreparer->prepare($playlist)) {
            return null;
        }

        if (PlaylistSources::Group === $playlist->source) {
            return $this->playSongFromGroup(
                $playlist,
                $recentSongHistory,
                $expectedPlayTime,
                $allowDuplicates
            );
        }

        if (PlaylistSources::RemoteUrl === $playlist->source) {
            return $this->getSongFromRemotePlaylist(
                $playlist,
                $expectedPlayTime,
                $deferQueuePersistence,
            );
        }

        if ($playlist->backendMerge() && !$singleTrackOnly) {
            $this->spmRepo->resetQueue($playlist);

            $queueEntries = array_filter(
                array_map(
                    function (StationPlaylistQueue $validTrack) use (
                        $playlist,
                        $expectedPlayTime,
                        $deferQueuePersistence,
                    ) {
                        return $this->makeQueueFromApi(
                            $validTrack,
                            $playlist,
                            $expectedPlayTime,
                            $deferQueuePersistence,
                        );
                    },
                    $this->spmRepo->getQueue($playlist)
                )
            );

            if (!empty($queueEntries)) {
                $playlist->played_at = $expectedPlayTime;
                $this->em->persist($playlist);
                return $queueEntries;
            }
        } else {
            $validTrack = match ($playlist->order) {
                PlaylistOrders::Random => $this->getRandomMediaIdFromPlaylist(
                    $playlist,
                    $recentSongHistory,
                    $expectedPlayTime,
                    $allowDuplicates
                ),
                PlaylistOrders::Sequential => $this->getSequentialMediaIdFromPlaylist(
                    $playlist,
                    $recentSongHistory,
                    $expectedPlayTime,
                    $allowDuplicates
                ),
                PlaylistOrders::Shuffle, PlaylistOrders::SmartShuffle => $this->getShuffledMediaIdFromPlaylist(
                    $playlist,
                    $recentSongHistory,
                    $expectedPlayTime,
                    $allowDuplicates
                ),
            };

            if (null !== $validTrack) {
                $validTrack = $this->applyHourBoundarySelection(
                    $playlist,
                    $validTrack,
                    $recentSongHistory,
                    $expectedPlayTime,
                    $allowDuplicates,
                );

                if (null === $validTrack) {
                    return null;
                }

                $queueEntry = $this->makeQueueFromApi(
                    $validTrack,
                    $playlist,
                    $expectedPlayTime,
                    $deferQueuePersistence,
                );

                if (null !== $queueEntry) {
                    $playlist->played_at = $expectedPlayTime;
                    $this->em->persist($playlist);
                    return $queueEntry;
                }
            }
        }

        $this->logger->warning(
            sprintf('Playlist "%s" did not return a playable track.', $playlist->name),
            [
                'playlist_id' => $playlist->id,
                'playlist_order' => $playlist->order->value,
                'allow_duplicates' => $allowDuplicates,
            ]
        );
        return null;
    }

    private function playSongFromGroup(
        StationPlaylist $group,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates,
    ): StationQueue|array|null {
        $members = $this->groupMemberRepo->getMembers($group);
        if ([] === $members) {
            return null;
        }

        $queueEntries = [];
        foreach ($members as $member) {
            if (PlaylistSources::Group === $member->playlist->source) {
                $this->logger->warning(
                    'Nested playlist groups are not supported in this increment.',
                    ['group_id' => $group->id, 'member_id' => $member->id]
                );
                continue;
            }

            $selectionCount = $this->getGroupMemberSelectionCount($member);
            if (0 === $selectionCount) {
                continue;
            }

            for ($play = 0; $play < $selectionCount; $play++) {
                $selection = $this->playSongFromPlaylist(
                    $member->playlist,
                    $recentSongHistory,
                    $expectedPlayTime,
                    $allowDuplicates,
                    true,
                    true,
                );

                // A group is selected as one programmed passage. If duplicate
                // prevention rejects a member, retry that member immediately
                // with the normal duplicate fallback instead of accepting a
                // block that stops at the preceding member.
                if (null === $selection && !$allowDuplicates) {
                    $selection = $this->playSongFromPlaylist(
                        $member->playlist,
                        $recentSongHistory,
                        $expectedPlayTime,
                        true,
                        true,
                        true,
                    );
                }

                if (null === $selection) {
                    break;
                }

                if (is_array($selection)) {
                    foreach ($selection as $queueEntry) {
                        $queueEntry->group_playlist = $group;
                        $queueEntries[] = $queueEntry;
                    }
                } else {
                    $selection->group_playlist = $group;
                    $queueEntries[] = $selection;
                }

                // A group builds several selections in one pass. Persist the
                // playlist cursor before selecting the next track, while the
                // StationQueue rows themselves remain deferred until the block
                // is complete.
                $this->em->flush();
            }
        }

        if ([] === $queueEntries) {
            return null;
        }

        $group->group_next_position = 0;
        $group->played_at = $expectedPlayTime;
        $this->em->persist($group);

        foreach ($queueEntries as $queueEntry) {
            $this->em->persist($queueEntry);
        }

        return $queueEntries;
    }

    private function getGroupMemberSelectionCount(StationPlaylistGroupMember $member): int
    {
        if (!$member->play_full_cycle) {
            return max(1, $member->consecutive_plays);
        }

        $playlist = $member->playlist;
        if (!$this->smartBlockPlaybackPreparer->prepare($playlist)) {
            return 0;
        }

        if (
            PlaylistSources::Songs !== $playlist->source
            || PlaylistOrders::Random === $playlist->order
        ) {
            $this->logger->warning(
                'Full-cycle playback is not supported for this playlist member.',
                [
                    'member_id' => $member->id,
                    'playlist_id' => $playlist->id,
                    'playlist_source' => $playlist->source->value,
                    'playlist_order' => $playlist->order->value,
                ]
            );
            return 0;
        }

        $cycleSize = count($this->spmRepo->getQueue($playlist));
        if (0 === $cycleSize) {
            $this->spmRepo->resetQueue($playlist);
            $cycleSize = count($this->spmRepo->getQueue($playlist));
        }

        return $cycleSize;
    }

    private function makeQueueFromApi(
        StationPlaylistQueue $validTrack,
        StationPlaylist $playlist,
        DateTimeImmutable $expectedPlayTime,
        bool $deferQueuePersistence = false,
    ): ?StationQueue {
        $mediaToPlay = $this->em->find(StationMedia::class, $validTrack->media_id);
        if (!$mediaToPlay instanceof StationMedia) {
            return null;
        }

        $spm = $this->em->find(StationPlaylistMedia::class, $validTrack->spm_id);
        if ($spm instanceof StationPlaylistMedia) {
            $spm->played($expectedPlayTime->getTimestamp());
            $this->em->persist($spm);
        }

        $stationQueueEntry = StationQueue::fromMedia($playlist->station, $mediaToPlay);
        $stationQueueEntry->playlist = $playlist;

        // Soft-strict scheduling for normal scheduled transitions (e.g. a talk show
        // starting at 5:01pm). TOPH itself is handled by the interrupting queue at
        // the absolute hour boundary and must not shorten normal queue entries here.
        //
        // Defensively wrapped: if anything here throws for an edge case, queue
        // building must never break station-wide; leave this track uncapped.
        $maxDuration = null;

        try {
            $secondsToNextScheduledStart = $this->scheduler->secondsUntilNextScheduledStart(
                $playlist->station,
                $expectedPlayTime,
            );

            if (null !== $secondsToNextScheduledStart) {
                $maxDuration = (float)$secondsToNextScheduledStart;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Scheduled boundary calculation failed; falling back to no boundary cap for this track.',
                ['exception' => $e->getMessage()]
            );
            $maxDuration = null;
        }

        if (null !== $maxDuration && $mediaToPlay->getCalculatedLength() > $maxDuration) {
            $maxPlaySeconds = (int)floor($maxDuration);
            $stationQueueEntry->hour_boundary_enforce_cap = true;
            $stationQueueEntry->hour_boundary_max_play_seconds = $maxPlaySeconds;

            // Queue::buildQueue() must advance using the same capped duration
            // that Liquidsoap will enforce for scheduled transitions.
            $stationQueueEntry->duration = (float)$maxPlaySeconds;
        }

        // Dedicated early fade-out ahead of a due top-of-hour legal ID. This is
        // separate from the scheduled-transition cap above, which intentionally
        // does not touch TOPH (see comment above). applyHourBoundarySelection()
        // already tries to pick a track that fits before :00 outright; this is
        // the backstop for when no fitting track exists (e.g. a short playlist
        // of long-form tracks) -- instead of a silent hard cut, the track is
        // capped AND explicitly flagged to fade out over the last few seconds,
        // so the ID at :58/:59 starts cleanly. Only active when top-of-hour ID
        // protection is enabled and this track lands in the pre-:00 lookahead
        // window (maxMusicDurationBeforeTopOfHour() returns null otherwise).
        $topOfHourMaxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
            $playlist->station,
            $expectedPlayTime,
        );

        if (null !== $topOfHourMaxDuration && $mediaToPlay->getCalculatedLength() > $topOfHourMaxDuration) {
            $cappedSeconds = (int)floor($topOfHourMaxDuration);

            $fadeOutSeconds = min(
                $playlist->station->backend_config->getCrossfadeDuration(),
                (float)$cappedSeconds
            );

            $stationQueueEntry->top_of_hour_pre_id_fade = true;
            $stationQueueEntry->top_of_hour_pre_id_fade_seconds = (int)round(max(0.0, $fadeOutSeconds));
            $stationQueueEntry->duration = (float)$cappedSeconds;
        } elseif (null !== $topOfHourMaxDuration) {
            // Track fits before :00 outright -- but it might be slightly short or
            // slightly long (within ±5%). Try a pitch-preserving stretch to land the
            // track *exactly* on the boundary instead of leaving a gap. The same
            // ClockWheelStretchCalculator used by clock-wheel slots ensures we never
            // exceed the safe ±5% range. This applies to rotation playlists too,
            // not just clock wheels.
            $stretchRatio = $this->stretchCalculator->calculate(
                $mediaToPlay->getCalculatedLength(),
                (int)round($topOfHourMaxDuration),
            );

            if (null !== $stretchRatio) {
                // Reuse the clock_wheel_stretch_ratio column -- Liquidsoap reads
                // this from liq_stretch_ratio regardless of whether the track came
                // from a clock wheel or a rotation playlist.
                $stationQueueEntry->clock_wheel_stretch_ratio = $stretchRatio;
            }
        }

        if (!$deferQueuePersistence) {
            $this->em->persist($stationQueueEntry);
        }

        return $stationQueueEntry;
    }

    private function getSongFromRemotePlaylist(
        StationPlaylist $playlist,
        DateTimeImmutable $expectedPlayTime,
        bool $deferQueuePersistence = false,
    ): ?StationQueue {
        $mediaToPlay = $this->getMediaFromRemoteUrl($playlist);

        if (is_array($mediaToPlay)) {
            [$mediaUri, $mediaDuration] = $mediaToPlay;

            $playlist->played_at = $expectedPlayTime;
            $this->em->persist($playlist);

            $stationQueueEntry = new StationQueue(
                $playlist->station,
                Song::createFromText('Remote Playlist URL')
            );

            $stationQueueEntry->playlist = $playlist;
            $stationQueueEntry->autodj_custom_uri = $mediaUri;
            $stationQueueEntry->duration = $mediaDuration;

            if (!$deferQueuePersistence) {
                $this->em->persist($stationQueueEntry);
            }

            return $stationQueueEntry;
        }

        return null;
    }

    /**
     * Returns either an array containing the URL of a remote stream and the duration,
     * an array with a media id and the duration or null if no media has been found.
     *
     * @return array{string|null, int}|null
     */
    private function getMediaFromRemoteUrl(StationPlaylist $playlist): ?array
    {
        $remoteType = $playlist->remote_type ?? PlaylistRemoteTypes::Stream;

        // Handle a raw stream URL of possibly indeterminate length.
        if (PlaylistRemoteTypes::Stream === $remoteType) {
            // Annotate a hard-coded "duration" parameter to avoid infinite play for scheduled playlists.
            $duration = $this->scheduler->getPlaylistScheduleDuration($playlist);
            return [$playlist->remote_url, $duration];
        }

        // Handle a remote playlist containing songs or streams.
        $queueCacheKey = 'playlist_queue.' . $playlist->id;

        $mediaQueue = $this->cache->get($queueCacheKey);
        if (empty($mediaQueue)) {
            $mediaQueue = [];

            $playlistRemoteUrl = $playlist->remote_url;
            if (null !== $playlistRemoteUrl) {
                $playlistRaw = file_get_contents($playlistRemoteUrl);
                if (false !== $playlistRaw) {
                    $mediaQueue = PlaylistParser::getSongs($playlistRaw);
                }
            }
        }

        $mediaId = null;
        if (!empty($mediaQueue)) {
            $mediaId = array_shift($mediaQueue);
        }

        // Save the modified cache, sans the now-missing entry.
        $this->cache->set($queueCacheKey, $mediaQueue, 6000);

        return ($mediaId)
            ? [$mediaId, 0]
            : null;
    }

    /**
     * When top-of-hour protection is in the lookahead window, prefer tracks that fit before :00.
     */
    private function applyHourBoundarySelection(
        StationPlaylist $playlist,
        StationPlaylistQueue $selectedTrack,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates,
    ): ?StationPlaylistQueue {
        $maxDuration = $this->hourBoundaryPlanner->maxMusicDurationBeforeTopOfHour(
            $playlist->station,
            $expectedPlayTime,
        );

        if (null === $maxDuration) {
            return $selectedTrack;
        }

        $media = $this->em->find(StationMedia::class, $selectedTrack->media_id);
        if ($media instanceof StationMedia && $media->getCalculatedLength() <= $maxDuration) {
            return $selectedTrack;
        }

        $mediaQueue = $this->spmRepo->getQueue($playlist);
        $fitting = [];

        foreach ($mediaQueue as $queueItem) {
            $candidate = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$candidate instanceof StationMedia) {
                continue;
            }

            if ($candidate->getCalculatedLength() <= $maxDuration) {
                $fitting[] = $queueItem;
            }
        }

        if ($fitting !== []) {
            usort(
                $fitting,
                function (StationPlaylistQueue $a, StationPlaylistQueue $b) use ($maxDuration): int {
                    $mediaA = $this->em->find(StationMedia::class, $a->media_id);
                    $mediaB = $this->em->find(StationMedia::class, $b->media_id);
                    $lenA = $mediaA instanceof StationMedia ? $mediaA->getCalculatedLength() : 0.0;
                    $lenB = $mediaB instanceof StationMedia ? $mediaB->getCalculatedLength() : 0.0;

                    return $lenB <=> $lenA;
                }
            );

            if ($playlist->avoid_duplicates) {
                $duplicateSafe = $this->duplicatePrevention->preventDuplicates(
                    $fitting,
                    $recentSongHistory,
                    $allowDuplicates
                );
                if (null !== $duplicateSafe) {
                    return $duplicateSafe;
                }
            }

            return $fitting[0];
        }

        if (!$media instanceof StationMedia) {
            return $selectedTrack;
        }

        // No candidate fits the remaining time before the hour. Fail LOUDLY — this
        // usually means the finish buffer / ID max seconds are tighter than the
        // playlist's shortest track — then fall back to the least-bad option: the
        // shortest tracks, run through duplicate prevention so we never silently
        // lock onto the same single file every hour ("AI keeps repeating songs").
        $this->logger->warning(
            'Hour boundary: NO track fits before top of hour (check finish buffer / ID max seconds vs shortest track length). Falling back to shortest non-recent track.',
            [
                'playlist_id' => $playlist->id,
                'max_duration_seconds' => $maxDuration,
            ]
        );

        $byLength = [];
        foreach ($mediaQueue as $queueItem) {
            $candidate = $this->em->find(StationMedia::class, $queueItem->media_id);
            if (!$candidate instanceof StationMedia) {
                continue;
            }
            $byLength[] = [$queueItem, $candidate->getCalculatedLength()];
        }
        usort($byLength, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        if ($byLength !== []) {
            // Consider the few shortest, prefer one not recently played.
            $shortestFew = array_map(
                static fn(array $row): StationPlaylistQueue => $row[0],
                array_slice($byLength, 0, 5)
            );
            $nonRepeat = $this->duplicatePrevention->preventDuplicates(
                $shortestFew,
                $recentSongHistory,
                false
            );
            if (null !== $nonRepeat) {
                return $nonRepeat;
            }

            if ($byLength[0][1] < $media->getCalculatedLength()) {
                return $byLength[0][0];
            }
        }

        return $selectedTrack;
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     *
     * @return StationPlaylistQueue[]
     */
    private function filterQueueByRotationGoal(StationPlaylist $playlist, array $mediaQueue): array
    {
        $goalDays = $playlist->rotation_goal_days;
        if (null === $goalDays || $goalDays <= 0 || $mediaQueue === []) {
            return $mediaQueue;
        }

        $blockedIds = array_flip(
            $this->historyRepo->getRecentlyPlayedMediaIdsForPlaylist($playlist, $goalDays),
        );

        if ($blockedIds === []) {
            return $mediaQueue;
        }

        $filtered = array_values(array_filter(
            $mediaQueue,
            static fn (StationPlaylistQueue $item): bool => !isset($blockedIds[$item->media_id]),
        ));

        if ($filtered === []) {
            $this->logger->warning(
                'Rotation goal blocked all tracks in playlist; using full pool.',
                [
                    'playlist_id' => $playlist->id,
                    'rotation_goal_days' => $goalDays,
                ],
            );

            return $mediaQueue;
        }

        return $filtered;
    }

    /**
     * @param StationPlaylistQueue[] $mediaQueue
     *
     * @return StationPlaylistQueue[]
     */
    private function filterQueueByPlayability(
        array $mediaQueue,
        DateTimeImmutable $expectedPlayTime,
        ?DateTimeZone $tz = null,
    ): array {
        $filtered = [];

        foreach ($mediaQueue as $item) {
            if (!isset($item->media_id)) {
                $filtered[] = $item;
                continue;
            }

            $media = $this->em->find(StationMedia::class, $item->media_id);

            $isEligible = true;
            if ($media instanceof StationMedia) {
                try {
                    $isEligible = MediaPlayability::isEligibleForPlayback($media, $expectedPlayTime, $tz);
                } catch (\Throwable $e) {
                    // Never let a single bad record's eligibility check break queue
                    // building station-wide -- default to eligible and log it.
                    $this->logger->warning(
                        'Media eligibility check failed; defaulting to eligible.',
                        ['media_id' => $item->media_id, 'exception' => $e->getMessage()]
                    );
                    $isEligible = true;
                }
            }

            if ($isEligible) {
                $filtered[] = $item;
            }
        }

        if ($filtered === [] && $mediaQueue !== []) {
            $this->logger->warning(
                'Playability filtering excluded every track in this queue pass; using full pool instead.'
            );
            return $mediaQueue;
        }

        return $filtered;
    }

    private function preparePlaylistQueue(
        StationPlaylist $playlist,
        array $mediaQueue,
        DateTimeImmutable $expectedPlayTime,
    ): array {
        return $this->filterQueueByPlayability(
            $this->filterQueueByRotationGoal($playlist, $mediaQueue),
            $expectedPlayTime,
            $playlist->station->getTimezoneObject(),
        );
    }

    private function getRandomMediaIdFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates
    ): ?StationPlaylistQueue {
        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );

        if ($playlist->avoid_duplicates) {
            return $this->duplicatePrevention->preventDuplicates($mediaQueue, $recentSongHistory, $allowDuplicates);
        }

        return array_shift($mediaQueue);
    }

    private function getSequentialMediaIdFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates = false
    ): ?StationPlaylistQueue {
        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );
        if (empty($mediaQueue)) {
            $this->spmRepo->resetQueue($playlist);
            $mediaQueue = $this->preparePlaylistQueue(
                $playlist,
                $this->spmRepo->getQueue($playlist),
                $expectedPlayTime,
            );
        }

        // Apply duplicate prevention if enabled for this playlist
        if ($playlist->avoid_duplicates) {
            $queueItem = $this->duplicatePrevention->preventDuplicates(
                $mediaQueue,
                $recentSongHistory,
                $allowDuplicates
            );
            if (null !== $queueItem) {
                return $queueItem;
            }
        }

        // Fallback: return first item in queue if duplicate prevention is disabled or no match found
        return array_shift($mediaQueue);
    }

    private function getShuffledMediaIdFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        DateTimeImmutable $expectedPlayTime,
        bool $allowDuplicates
    ): ?StationPlaylistQueue {
        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );
        if (empty($mediaQueue)) {
            $this->spmRepo->resetQueue($playlist);
            $mediaQueue = $this->preparePlaylistQueue(
                $playlist,
                $this->spmRepo->getQueue($playlist),
                $expectedPlayTime,
            );
        }

        if (!$playlist->avoid_duplicates) {
            return array_shift($mediaQueue);
        }

        $queueItem = $this->duplicatePrevention->preventDuplicates(
            $mediaQueue,
            $recentSongHistory,
            $allowDuplicates,
            $playlist->aging_threshold_days,
        );
        if (null !== $queueItem || $allowDuplicates) {
            return $queueItem;
        }

        // Reshuffle the queue.
        $this->logger->warning(
            'Duplicate prevention yielded no playable song; resetting song queue.'
        );

        $this->spmRepo->resetQueue($playlist);
        $mediaQueue = $this->preparePlaylistQueue(
            $playlist,
            $this->spmRepo->getQueue($playlist),
            $expectedPlayTime,
        );

        return $this->duplicatePrevention->preventDuplicates(
            $mediaQueue,
            $recentSongHistory,
            false,
            $playlist->aging_threshold_days,
        );
    }

    /**
     * Pick the next track using a playlist's configured rotation order (PHP AutoDJ path).
     *
     * @param array<array{song_id:string, timestamp_played:mixed, title:string|null, artist:string|null}> $recentSongHistory
     */
    public function pickNextTrackFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        bool $allowDuplicates = false,
    ): ?StationPlaylistQueue {
        $this->smartBlockPlaybackPreparer->beginQueueBuild();
        if (!$this->smartBlockPlaybackPreparer->prepare($playlist)) {
            return null;
        }

        if (PlaylistSources::RemoteUrl === $playlist->source) {
            return null;
        }

        return match ($playlist->order) {
            PlaylistOrders::Random => $this->getRandomMediaIdFromPlaylist(
                $playlist,
                $recentSongHistory,
                new DateTimeImmutable(),
                $allowDuplicates
            ),
            PlaylistOrders::Sequential => $this->getSequentialMediaIdFromPlaylist(
                $playlist,
                $recentSongHistory,
                new DateTimeImmutable(),
                $allowDuplicates
            ),
            PlaylistOrders::Shuffle, PlaylistOrders::SmartShuffle => $this->getShuffledMediaIdFromPlaylist(
                $playlist,
                $recentSongHistory,
                new DateTimeImmutable(),
                $allowDuplicates
            ),
        };
    }

    public function getNextSongFromRequests(BuildQueue $event): void
    {
        // Don't use this to cue requests.
        if ($event->isInterrupting()) {
            return;
        }

        if (!empty($event->getNextSongs())) {
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();
        $station = $event->getStation();

        // Check if any playlist marked with "Prioritize Over Requests" (e.g. a jingle) is due now.
        foreach ($station->playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if (
                $playlist->backendPrioritizeOverRequests() &&
                $playlist->isPlayable($event->isInterrupting()) &&
                $this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime)
            ) {
                $this->logger->debug(sprintf(
                    'Playlist "%s" is prioritized and due now; skipping request queue.',
                    $playlist->name
                ));
                return;
            }
        }

        $request = $this->requestRepo->getNextPlayableRequest($station, $expectedPlayTime);
        if (null === $request) {
            return;
        }

        $this->logger->debug(sprintf('Queueing next song from request ID %d.', $request->id));

        $stationQueueEntry = StationQueue::fromRequest($request);
        $this->em->persist($stationQueueEntry);

        $request->played_at = $expectedPlayTime;
        $this->em->persist($request);

        $event->setNextSongs($stationQueueEntry);
    }
}
