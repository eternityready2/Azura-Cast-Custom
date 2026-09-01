<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Enums\PlaylistGroupAllowedRequests;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistRemoteTypes;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Repository\StationRequestRepository;
use App\Entity\Repository\SongHistoryRepository;
use App\Entity\Song;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroup;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationQueue;
use App\Event\Radio\BuildQueue;
use App\Radio\AutoDJ\ClockWheel;
use App\Radio\PlaylistParser;
use App\Radio\SmartBlock\SmartBlockPlaybackPreparer;
use App\Service\HolidayOverrideService;
use App\Utilities\UserUrlFilter;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
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
        private readonly StationPlaylistRepository $playlistRepo,
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationRequestRepository $requestRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly SongHistoryRepository $historyRepo,
        private readonly HolidayOverrideService $holidayOverrideService,
        private readonly SmartBlockPlaybackPreparer $smartBlockPlaybackPreparer,
        private readonly LinearLogPreviewContext $linearLogPreviewContext,
        private readonly UserUrlFilter $userUrlFilter,
        private readonly Client $httpClient,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['getNextSongFromRequests', 5],
                ['calculateNextSong', 0],
            ],
        ];
    }

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
        foreach ($station->playlists as $playlist) {
            /** @var StationPlaylist $playlist */
            if ($playlist->playlist_groups->count() > 0) {
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
            ['history_duplicate_prevention' => $recentSongHistoryForDuplicatePrevention]
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
                            ['next_song' => (string)$event]
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

        if (PlaylistSources::Playlists === $playlist->source) {
            return $this->playSongFromGroup(
                $playlist,
                $recentSongHistory,
                $expectedPlayTime,
                $allowDuplicates
            );
        }

        if (PlaylistSources::Requests === $playlist->source) {
            return $this->playSongFromRequestsPlaylist(
                $playlist,
                $expectedPlayTime,
                $deferQueuePersistence,
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
        foreach ($this->getPlaylistGroupQueueForOrder($group) as $membership) {
            $memberPlaylist = $membership->playlist;

            if (!$this->scheduler->shouldPlaylistPlayNow($memberPlaylist, $expectedPlayTime)) {
                $membership->played($expectedPlayTime->getTimestamp(), forceAdvance: true);
                $this->em->persist($membership);
                continue;
            }

            $isFullCycleMember = $membership->play_full_cycle
                && PlaylistSources::Songs === $memberPlaylist->source
                && in_array(
                    $memberPlaylist->order,
                    [PlaylistOrders::Sequential, PlaylistOrders::Shuffle],
                    true
                );

            $queuedBeforePlay = $isFullCycleMember
                ? count($this->spmRepo->getQueue($memberPlaylist))
                : 0;

            $selection = $this->playSongFromPlaylist(
                $memberPlaylist,
                $recentSongHistory,
                $expectedPlayTime,
                $allowDuplicates,
                true,
                true,
            );

            if (null === $selection && !$allowDuplicates) {
                $selection = $this->playSongFromPlaylist(
                    $memberPlaylist,
                    $recentSongHistory,
                    $expectedPlayTime,
                    true,
                    true,
                    true,
                );
            }

            if (null !== $selection) {
                $group->played_at = $expectedPlayTime;
                $this->em->persist($group);

                if (is_array($selection)) {
                    foreach ($selection as $queueEntry) {
                        $queueEntry->group_playlist = $group;
                        $this->em->persist($queueEntry);
                    }
                } else {
                    $selection->group_playlist = $group;
                    $this->em->persist($selection);
                }

                if ($isFullCycleMember && $queuedBeforePlay === 0) {
                    $queuedBeforePlay = $memberPlaylist->media_items->count();
                }

                $membership->played(
                    $expectedPlayTime->getTimestamp(),
                    keepQueued: $isFullCycleMember && $queuedBeforePlay > 1
                );
                $this->em->persist($membership);

                return $selection;
            }

            $membership->played($expectedPlayTime->getTimestamp(), forceAdvance: true);
            $this->em->persist($membership);
        }

        $this->logger->warning(
            sprintf('Playlist Group "%s" did not return a playable track.', $group->name),
            ['playlist_group_id' => $group->id]
        );

        return null;
    }

    /** @return StationPlaylistGroup[] */
    private function getPlaylistGroupQueueForOrder(StationPlaylist $group): array
    {
        if (PlaylistOrders::Random === $group->order) {
            return $this->playlistRepo->getPlaylistGroupQueue($group);
        }

        $queue = $this->playlistRepo->getPlaylistGroupQueue($group);
        if (empty($queue)) {
            $this->playlistRepo->resetPlaylistGroupQueue($group);
            $queue = $this->playlistRepo->getPlaylistGroupQueue($group);
        }

        return $queue;
    }

    private function playSongFromRequestsPlaylist(
        StationPlaylist $playlist,
        DateTimeImmutable $expectedPlayTime,
        bool $deferQueuePersistence = false,
    ): ?StationQueue {
        if ($this->areRequestsBlockedByAncestors($playlist, $expectedPlayTime)) {
            return null;
        }

        $request = $this->requestRepo->getNextPlayableRequest(
            $playlist->station,
            $expectedPlayTime
        );

        if (null === $request) {
            return null;
        }

        $this->logger->debug(sprintf(
            'Queueing next song from request ID %d via Requests playlist "%s".',
            $request->id,
            $playlist->name
        ));

        $stationQueueEntry = StationQueue::fromRequest($request);
        $stationQueueEntry->playlist = $playlist;

        if (!$deferQueuePersistence) {
            $this->em->persist($stationQueueEntry);
        }

        $request->played_at = $expectedPlayTime;
        $this->em->persist($request);

        $playlist->played_at = $expectedPlayTime;
        $this->em->persist($playlist);

        return $stationQueueEntry;
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
            $stationQueueEntry->duration = (float)$maxPlaySeconds;
        }

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
            $stretchRatio = $this->stretchCalculator->calculate(
                $mediaToPlay->getCalculatedLength(),
                (int)round($topOfHourMaxDuration),
            );

            if (null !== $stretchRatio) {
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

    /** @return array{string|null, int}|null */
    private function getMediaFromRemoteUrl(StationPlaylist $playlist): ?array
    {
        $remoteType = $playlist->remote_type ?? PlaylistRemoteTypes::Stream;

        if (PlaylistRemoteTypes::Stream === $remoteType) {
            $duration = $this->scheduler->getPlaylistScheduleDuration($playlist);
            return [$playlist->remote_url, $duration];
        }

        $queueCacheKey = $this->linearLogPreviewContext->cacheKey('playlist_queue.' . $playlist->id);
        $mediaQueue = $this->cache->get($queueCacheKey);
        if (empty($mediaQueue)) {
            $mediaQueue = [];

            $playlistRemoteUrl = $this->userUrlFilter->filterSensitiveUserUrl(
                $playlist->remote_url,
                'Playlist Remote URL'
            );

            $httpResponse = $this->httpClient->get($playlistRemoteUrl);
            $playlistRaw = $httpResponse->getBody()->getContents();

            if (!empty($playlistRaw)) {
                $mediaQueue = PlaylistParser::getSongs($playlistRaw);
            }
        }

        $mediaId = null;
        if (!empty($mediaQueue)) {
            $mediaId = array_shift($mediaQueue);
        }

        $this->cache->set($queueCacheKey, $mediaQueue, 6000);
        return ($mediaId) ? [$mediaId, 0] : null;
    }

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

    public function pickNextTrackFromPlaylist(
        StationPlaylist $playlist,
        array $recentSongHistory,
        bool $allowDuplicates = false,
    ): ?StationPlaylistQueue {
        $this->smartBlockPlaybackPreparer->beginQueueBuild();
        if (!$this->smartBlockPlaybackPreparer->prepare($playlist)) {
            return null;
        }

        if (in_array(
            $playlist->source,
            [PlaylistSources::RemoteUrl, PlaylistSources::Playlists, PlaylistSources::Requests],
            true
        )) {
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

    private function isRequestBlockedInHierarchy(
        StationPlaylist $group,
        ?StationMedia $requestedMedia
    ): bool {
        $members = ($group->order === PlaylistOrders::Random)
            ? $group->playlists->toArray()
            : array_slice($this->playlistRepo->getPlaylistGroupQueue($group), 0, 1);

        foreach ($members as $member) {
            if ($member->allowed_requests === PlaylistGroupAllowedRequests::None) {
                $this->logger->debug(sprintf(
                    'Playlist group member "%s" blocks requests (allowed_requests=none).',
                    $member->playlist->name
                ));
                return true;
            }

            if (
                $member->allowed_requests === PlaylistGroupAllowedRequests::Playlist
                && $requestedMedia !== null
                && !$this->spmRepo->isMediaInPlaylist($requestedMedia, $member->playlist)
            ) {
                $this->logger->debug(sprintf(
                    'Request blocked, media not in subtree of member "%s".',
                    $member->playlist->name
                ));
                return true;
            }

            if ($member->playlist->source === PlaylistSources::Playlists) {
                if ($this->isRequestBlockedInHierarchy($member->playlist, $requestedMedia)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function areRequestsBlockedByAncestors(
        StationPlaylist $playlist,
        DateTimeImmutable $expectedPlayTime
    ): bool {
        foreach ($playlist->playlist_groups as $membership) {
            if ($membership->allowed_requests === PlaylistGroupAllowedRequests::None) {
                $root = $membership->playlist_group;
                while (($parentMembership = $root->playlist_groups->first()) !== false) {
                    /** @var StationPlaylistGroup $parentMembership */
                    $root = $parentMembership->playlist_group;
                }

                if (
                    $root->schedule_items->count() > 0
                    && $this->scheduler->shouldPlaylistPlayNow($root, $expectedPlayTime)
                ) {
                    $this->logger->debug(sprintf(
                        'Requests blocked for "%s", ancestor group membership has allowed_requests=none.',
                        $playlist->name
                    ));
                    return true;
                }
            }

            if ($this->areRequestsBlockedByAncestors($membership->playlist_group, $expectedPlayTime)) {
                return true;
            }
        }

        return false;
    }

    public function getNextSongFromRequests(BuildQueue $event): void
    {
        if ($event->isInterrupting()) {
            return;
        }

        if (!empty($event->getNextSongs())) {
            return;
        }

        $expectedPlayTime = $event->getExpectedPlayTime();
        $station = $event->getStation();

        if ($station->requests_only_via_playlists) {
            return;
        }

        foreach ($station->playlists as $playlist) {
            if (!$playlist->is_enabled) {
                continue;
            }

            foreach ($playlist->schedule_items as $scheduleItem) {
                if (
                    $scheduleItem->prevent_requests
                    && $this->scheduler->shouldSchedulePlayNow(
                        $scheduleItem,
                        $station->getTimezoneObject(),
                        $expectedPlayTime,
                        excludeSpecialRules: true
                    )
                ) {
                    $this->logger->debug(sprintf(
                        'Schedule item on playlist "%s" is blocking the global request queue.',
                        $playlist->name
                    ));
                    return;
                }
            }
        }

        foreach ($station->playlists as $playlist) {
            if (!$playlist->isPlayable($event->isInterrupting())) {
                continue;
            }

            if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime)) {
                continue;
            }

            if (PlaylistSources::Requests === $playlist->source) {
                $this->logger->debug(sprintf(
                    'Playlist "%s" is controlling request queue and due now; skipping regular request queue.',
                    $playlist->name
                ));
                return;
            }

            if ($playlist->backendPrioritizeOverRequests()) {
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

        foreach ($station->playlists as $playlist) {
            if (
                !$playlist->is_enabled
                || $playlist->source !== PlaylistSources::Playlists
                || $playlist->schedule_items->count() === 0
                || $playlist->playlist_groups->count() > 0
            ) {
                continue;
            }

            if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime)) {
                continue;
            }

            if ($this->isRequestBlockedInHierarchy($playlist, $request->track)) {
                return;
            }
        }

        $this->logger->debug(sprintf('Queueing next song from request ID %d.', $request->id));

        $stationQueueEntry = StationQueue::fromRequest($request);
        $this->em->persist($stationQueueEntry);

        $request->played_at = $expectedPlayTime;
        $this->em->persist($request);

        $event->setNextSongs($stationQueueEntry);
    }
}
