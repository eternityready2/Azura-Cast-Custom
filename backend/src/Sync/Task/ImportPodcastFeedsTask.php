<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PodcastImportStrategy;
use App\Entity\Enums\PodcastSources;
use App\Entity\Podcast;
use App\Entity\PodcastEpisode;
use App\Entity\Repository\PodcastEpisodeRepository;
use App\Entity\Repository\StationScheduleRepository;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\Station;
use App\Exception\CannotProcessMediaException;
use App\Flysystem\StationFilesystems;
use App\Media\MimeType;
use App\Podcast\PodcastFeedRemoteItemCountService;
use App\Podcast\RssAtomFeedItems;
use GuzzleHttp\Client;
use App\Flysystem\ExtendedFilesystemInterface;
use GuzzleHttp\RequestOptions;
use SimpleXMLElement;

final class ImportPodcastFeedsTask extends AbstractTask
{
    public function __construct(
        private readonly Client $httpClient,
        private readonly PodcastEpisodeRepository $podcastEpisodeRepo,
        private readonly StationScheduleRepository $stationScheduleRepo,
        private readonly StorageLocationRepository $storageLocationRepo,
        private readonly StationFilesystems $stationFilesystems,
        private readonly PodcastFeedRemoteItemCountService $feedRemoteItemCount,
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return '*/15 * * * *';
    }

    public function run(bool $force = false): void
    {
        foreach ($this->iterateStations() as $station) {
            $this->importFeedsForStation($station);
        }
    }

    /**
     * Run import for a single podcast (e.g. from "Sync now" API).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     episodes_added: int,
     *     log: list<array{level: string, message: string}>
     * }
     */
    /**
     * @param bool $fullBacklog If true, import all missing feed items. If false, only newest episode (replace previous).
     */
    public function runForPodcastWithSyncLog(Podcast $podcast, bool $fullBacklog = false): array
    {
        $log = [];
        $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);
        $stations = $this->storageLocationRepo->getStationsUsingLocation($podcast->storage_location);
        $station = $stations[0] ?? null;
        if (!$station instanceof Station) {
            $log[] = [
                'level' => 'error',
                'message' => 'No station is configured to use this podcast’s storage location. Assign podcast storage on a station first.',
            ];

            return [
                'success' => false,
                'message' => 'No station found for this podcast storage.',
                'episodes_added' => 0,
                'log' => $log,
            ];
        }

        try {
            $result = $this->importFeed($podcast, $station, $log, $fullBacklog);
            $added = $result['added'];
            $ok = $result['ok'];

            $message = $ok
                ? ($added > 0
                    ? sprintf('Imported %d episode(s).', $added)
                    : ($fullBacklog
                        ? 'Sync completed. No new episodes (feed OK).'
                        : 'Sync completed. Already on latest episode.'))
                : ($result['message'] ?? 'Sync failed.');

            return [
                'success' => $ok,
                'message' => $message,
                'episodes_added' => $added,
                'log' => $log,
            ];
        } finally {
            $this->ensureEntityManagerOpen();
        }
    }

    private function importFeedsForStation(Station $station): void
    {
        $podcasts = $this->em->createQuery(
            <<<'DQL'
                SELECT p FROM App\Entity\Podcast p
                LEFT JOIN p.playlist pl
                WHERE p.storage_location = :storageLocation
                AND p.source = :source
                AND p.is_enabled = true
                AND p.auto_import_enabled = true
                AND p.feed_url IS NOT NULL AND p.feed_url != ''
            DQL
        )
            ->setParameter('storageLocation', $station->podcasts_storage_location)
            ->setParameter('source', PodcastSources::Import->value)
            ->execute();

        $now = new \DateTimeImmutable('@' . time());
        $nowTs = $now->getTimestamp();

        foreach ($podcasts as $podcast) {
            $syncBeforeHours = $podcast->import_sync_before_hours;
            if ($syncBeforeHours !== null && $syncBeforeHours > 0 && $podcast->playlist !== null) {
                $nextStart = $this->stationScheduleRepo->getNextStartTimestampForPlaylist(
                    $station,
                    $podcast->playlist,
                    $now
                );
                if ($nextStart === null) {
                    continue;
                }
                $windowStart = $nextStart - ($syncBeforeHours * 3600);
                if ($nowTs < $windowStart) {
                    continue;
                }
                if ($nowTs > $nextStart + 3600) {
                    continue;
                }
            }

            $fullBacklog = $podcast->import_strategy === PodcastImportStrategy::BackfillAll;
            try {
                $this->importFeed($podcast, $station, null, $fullBacklog);
            } finally {
                $this->ensureEntityManagerOpen();
            }
        }
    }

    /**
     * @param list<array{level: string, message: string}>|null $syncLog
     *
     * @return array{added: int, ok: bool, message?: string}
     */
    private function importFeed(
        Podcast $podcast,
        Station $station,
        ?array &$syncLog = null,
        bool $fullBacklog = true
    ): array
    {
        $feedUrl = $podcast->feed_url;
        if (empty($feedUrl)) {
            $this->syncLogLine($syncLog, 'warning', 'Feed URL is empty; nothing to import.');

            return ['added' => 0, 'ok' => true];
        }

        $this->logger->info('Importing podcast feed', [
            'podcast' => $podcast->title,
            'feed_url' => $feedUrl,
        ]);
        $this->syncLogLine($syncLog, 'info', sprintf('Fetching feed: %s', $feedUrl));
        $this->syncLogLine($syncLog, 'info', sprintf('Podcast: %s', $podcast->title));

        try {
            $response = $this->httpClient->get($feedUrl, [
                RequestOptions::TIMEOUT => 30,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch podcast feed', [
                'podcast' => $podcast->title,
                'error' => $e->getMessage(),
            ]);
            $msg = 'Failed to fetch feed: ' . $e->getMessage();
            $this->syncLogLine($syncLog, 'error', $msg);

            return ['added' => 0, 'ok' => false, 'message' => $msg];
        }

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            $this->logger->error('Invalid XML in podcast feed', ['podcast' => $podcast->title]);
            $msg = 'Invalid XML in feed response (could not parse as RSS/Atom).';
            $this->syncLogLine($syncLog, 'error', $msg);

            return ['added' => 0, 'ok' => false, 'message' => $msg];
        }

        $this->syncLogLine($syncLog, 'info', sprintf('Downloaded feed (%d bytes).', strlen($body)));

        $items = $this->getRssItems($xml);
        $this->feedRemoteItemCount->primeCachedCount($feedUrl, count($items));
        if (empty($items)) {
            $this->logger->debug('No items in podcast feed', ['podcast' => $podcast->title]);
            $this->syncLogLine($syncLog, 'warning', 'No episode items found in feed (no <item> or <entry> elements).');

            return ['added' => 0, 'ok' => true];
        }

        $itemCount = count($items);
        $this->syncLogLine($syncLog, 'info', sprintf('Found %d item(s) in feed.', $itemCount));

        $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);

        $fs = $this->stationFilesystems->getPodcastsFilesystem($station);
        $tempDir = $station->getRadioTempDir();

        if (!$fullBacklog) {
            return $this->importFeedLatestSingle($podcast, $station, $items, $fs, $tempDir, $syncLog);
        }

        $importMap = $this->getExistingEpisodeImportMap($podcast);
        $added = 0;
        $skippedExisting = 0;
        $skippedNoEnclosure = 0;
        $skippedUnsupportedMedia = 0;
        $downloadErrors = 0;
        $uploadErrors = 0;
        $maxErrorLines = 40;
        $errorLineBudget = $maxErrorLines;

        foreach ($items as $item) {
            $guid = $this->getItemGuid($item);
            if ($guid !== null) {
                $existing = $importMap[$guid] ?? null;
                if ($existing !== null && $existing['has_media']) {
                    ++$skippedExisting;

                    continue;
                }
                if ($existing !== null && !$existing['has_media']) {
                    $episode = $this->podcastEpisodeRepo->fetchEpisodeForPodcast($podcast, $existing['episode_id']);
                    if ($episode !== null && $this->attachFeedItemMediaToExistingEpisode(
                        $episode,
                        $item,
                        $fs,
                        $tempDir,
                        'podcast_import_',
                        $syncLog,
                        $errorLineBudget
                    )) {
                        ++$added;
                        if (null !== $syncLog) {
                            $this->syncLogLine($syncLog, 'info', sprintf('Re-imported media for: %s', $this->getItemTitle($item)));
                        }
                        $importMap[$guid]['has_media'] = true;
                    }

                    continue;
                }
            }

            $enclosureUrl = $this->getEnclosureUrl($item);
            if ($enclosureUrl === null) {
                ++$skippedNoEnclosure;

                continue;
            }

            $enclosureMimeHint = $this->getEnclosureMimeHint($item);
            if ($this->isSkippablePodcastEnclosureMime($enclosureMimeHint)) {
                ++$skippedUnsupportedMedia;

                continue;
            }

            $title = $this->getItemTitle($item);
            $description = $this->getItemDescription($item);
            $publishAt = $this->getItemPublishAt($item);

            $episode = new PodcastEpisode($podcast);
            $episode->title = $title;
            $episode->description = $description;
            $episode->publish_at = $publishAt;
            $episode->explicit = $podcast->explicit;
            if ($guid !== null) {
                $episode->link = $guid;
            }
            $this->em->persist($episode);
            $this->em->flush();

            $downloadedPath = $tempDir . '/' . 'podcast_import_' . $episode->id . '_' . md5($enclosureUrl);
            try {
                $this->httpClient->get($enclosureUrl, [
                    RequestOptions::SINK => $downloadedPath,
                    RequestOptions::TIMEOUT => 300,
                    RequestOptions::HEADERS => [
                        'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                    ],
                ]);
            } catch (\Throwable $e) {
                ++$downloadErrors;
                $this->logger->error('Failed to download episode media', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($syncLog, 'error', sprintf('Download failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }

                continue;
            }

            $fileMime = MimeType::getMimeTypeFromFile($downloadedPath);
            if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime($fileMime)) {
                ++$skippedUnsupportedMedia;
                $this->em->remove($episode);
                $this->em->flush();
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }

                continue;
            }

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $title . '.' . $ext;

            try {
                $this->podcastEpisodeRepo->uploadMedia(
                    $episode,
                    $originalName,
                    $downloadedPath,
                    $fs
                );
                ++$added;
                if (null !== $syncLog) {
                    $this->syncLogLine($syncLog, 'info', sprintf('Imported: %s', $title));
                }
                if ($guid !== null) {
                    $importMap[$guid] = ['episode_id' => $episode->id, 'has_media' => true];
                }
            } catch (CannotProcessMediaException|\InvalidArgumentException $e) {
                if ($this->isSkippablePodcastImportException($e)) {
                    ++$skippedUnsupportedMedia;
                    $this->logger->debug('Skipped podcast episode (unsupported media)', [
                        'episode' => $title,
                        'reason' => $e->getMessage(),
                    ]);
                } else {
                    ++$uploadErrors;
                    $this->logger->error('Failed to attach media to episode', [
                        'episode' => $title,
                        'error' => $e->getMessage(),
                    ]);
                    if ($errorLineBudget > 0) {
                        $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                        --$errorLineBudget;
                    }
                }
                $podcast = $this->removeOrphanEpisodeAfterFailedImport($podcast, $episode);
            } catch (\Throwable $e) {
                ++$uploadErrors;
                $this->logger->error('Failed to attach media to episode', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $podcast = $this->removeOrphanEpisodeAfterFailedImport($podcast, $episode);
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }
        }

        $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);

        $this->syncLogLine($syncLog, 'info', sprintf('Skipped %d item(s) already in library (same GUID, with media).', $skippedExisting));
        $this->syncLogLine($syncLog, 'info', sprintf('Skipped %d item(s) with no audio enclosure.', $skippedNoEnclosure));
        if ($skippedUnsupportedMedia > 0) {
            $this->syncLogLine(
                $syncLog,
                'info',
                sprintf(
                    'Skipped %d item(s) with unsupported media (e.g. MP4 video or non-audio file).',
                    $skippedUnsupportedMedia
                )
            );
        }
        if ($downloadErrors > 0) {
            $truncated = $maxErrorLines - $errorLineBudget;
            $omitted = max(0, $downloadErrors - $truncated);
            $suffix = $omitted > 0 ? sprintf(' (%d error line(s) omitted from log.)', $omitted) : '';
            $this->syncLogLine($syncLog, 'warning', sprintf('%d download error(s).%s', $downloadErrors, $suffix));
        }
        if ($uploadErrors > 0) {
            $this->syncLogLine($syncLog, 'warning', sprintf('%d upload/storage error(s).', $uploadErrors));
        }

        if ($podcast->auto_keep_episodes > 0) {
            $removed = $this->pruneOldEpisodes($podcast, $fs);
            if ($removed > 0) {
                $this->syncLogLine($syncLog, 'info', sprintf('Pruned %d older episode(s) (keep last %d).', $removed, $podcast->auto_keep_episodes));
            }
        }

        if ($added > 0) {
            $this->logger->info('Imported podcast episodes', [
                'podcast' => $podcast->title,
                'added' => $added,
            ]);
        }
        $this->syncLogLine($syncLog, 'info', sprintf('Done. %d new episode(s) imported this run.', $added));

        return ['added' => $added, 'ok' => true];
    }

    /**
     * @param list<SimpleXMLElement> $items
     * @param list<array{level: string, message: string}>|null $syncLog
     *
     * @return array{added: int, ok: bool, message?: string}
     */
    private function importFeedLatestSingle(
        Podcast $podcast,
        Station $station,
        array $items,
        ExtendedFilesystemInterface $fs,
        string $tempDir,
        ?array &$syncLog
    ): array {
        $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);

        $rollingKeepN = $podcast->auto_keep_episodes > 0;
        $n = $rollingKeepN ? $podcast->auto_keep_episodes : 1;

        $this->syncLogLine(
            $syncLog,
            'info',
            $rollingKeepN
                ? sprintf(
                    'Mode: sync top %d episode(s) from feed (refresh media; remove episodes not in this set).',
                    $n
                )
                : 'Mode: sync latest episode only (refresh media; other episodes unchanged).'
        );

        usort($items, function (SimpleXMLElement $a, SimpleXMLElement $b): int {
            return $this->getItemPublishAt($b) <=> $this->getItemPublishAt($a);
        });

        $candidates = [];
        foreach ($items as $item) {
            $url = $this->getEnclosureUrl($item);
            if ($url === null) {
                continue;
            }
            if ($this->isSkippablePodcastEnclosureMime($this->getEnclosureMimeHint($item))) {
                continue;
            }
            $candidates[] = $item;
        }

        if ($candidates === []) {
            $this->syncLogLine($syncLog, 'warning', 'No suitable audio enclosure in feed (newest items may be video-only or invalid).');

            return ['added' => 0, 'ok' => true];
        }

        $topSlice = [];
        foreach ($candidates as $item) {
            if (count($topSlice) >= $n) {
                break;
            }
            $key = $this->getItemGuid($item) ?: $this->getEnclosureUrl($item) ?: '';
            if ($key === '') {
                continue;
            }
            $topSlice[] = $item;
        }

        if ($topSlice === []) {
            $this->syncLogLine($syncLog, 'warning', 'Top feed item(s) have no GUID or enclosure URL; cannot sync.');

            return ['added' => 0, 'ok' => true];
        }

        $targetKeys = [];
        foreach ($topSlice as $item) {
            $key = $this->getItemGuid($item) ?: $this->getEnclosureUrl($item) ?: '';
            if ($key !== '') {
                $targetKeys[$key] = true;
            }
        }

        $importMap = $this->getExistingEpisodeImportMap($podcast);
        $touched = 0;
        $downloadErrors = 0;
        $uploadErrors = 0;
        $skippedUnsupportedMedia = 0;
        $maxErrorLines = 40;
        $errorLineBudget = $maxErrorLines;

        foreach ($topSlice as $item) {
            $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);

            $key = $this->getItemGuid($item) ?: $this->getEnclosureUrl($item) ?: '';
            if ($key === '') {
                continue;
            }

            $existing = $importMap[$key] ?? null;
            if ($existing !== null) {
                $episode = $this->podcastEpisodeRepo->fetchEpisodeForPodcast($podcast, $existing['episode_id']);
                if ($episode !== null) {
                    if ($this->attachFeedItemMediaToExistingEpisode(
                        $episode,
                        $item,
                        $fs,
                        $tempDir,
                        'podcast_import_',
                        $syncLog,
                        $errorLineBudget
                    )) {
                        ++$touched;
                        $importMap[$key]['has_media'] = true;
                        $this->syncLogLine($syncLog, 'info', sprintf('Refreshed media: %s', $this->getItemTitle($item)));
                    }
                }

                continue;
            }

            $enclosureUrl = $this->getEnclosureUrl($item);
            if ($enclosureUrl === null || $this->isSkippablePodcastEnclosureMime($this->getEnclosureMimeHint($item))) {
                continue;
            }

            $title = $this->getItemTitle($item);
            $description = $this->getItemDescription($item);
            $publishAt = $this->getItemPublishAt($item);
            $guid = $this->getItemGuid($item);

            $episode = new PodcastEpisode($podcast);
            $episode->title = $title;
            $episode->description = $description;
            $episode->publish_at = $publishAt;
            $episode->explicit = $podcast->explicit;
            $linkVal = $guid ?? $enclosureUrl;
            if ($linkVal !== '') {
                $episode->link = $linkVal;
            }
            $this->em->persist($episode);
            $this->em->flush();

            $downloadedPath = $tempDir . '/' . 'podcast_import_' . $episode->id . '_' . md5($enclosureUrl);
            try {
                $this->httpClient->get($enclosureUrl, [
                    RequestOptions::SINK => $downloadedPath,
                    RequestOptions::TIMEOUT => 300,
                    RequestOptions::HEADERS => [
                        'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                    ],
                ]);
            } catch (\Throwable $e) {
                ++$downloadErrors;
                $this->logger->error('Failed to download episode media', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($syncLog, 'error', sprintf('Download failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }

                continue;
            }

            if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime(MimeType::getMimeTypeFromFile($downloadedPath))) {
                ++$skippedUnsupportedMedia;
                $this->em->remove($episode);
                $this->em->flush();
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }

                continue;
            }

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $title . '.' . $ext;

            try {
                $this->podcastEpisodeRepo->uploadMedia(
                    $episode,
                    $originalName,
                    $downloadedPath,
                    $fs
                );
                ++$touched;
                $importMap[$key] = ['episode_id' => $episode->id, 'has_media' => true];
                $this->syncLogLine($syncLog, 'info', sprintf('Imported: %s', $title));
            } catch (CannotProcessMediaException|\InvalidArgumentException $e) {
                if ($this->isSkippablePodcastImportException($e)) {
                    ++$skippedUnsupportedMedia;
                    $this->logger->debug('Skipped podcast episode (unsupported media)', [
                        'episode' => $title,
                        'reason' => $e->getMessage(),
                    ]);
                } else {
                    ++$uploadErrors;
                    $this->logger->error('Failed to attach media to episode', [
                        'episode' => $title,
                        'error' => $e->getMessage(),
                    ]);
                    if ($errorLineBudget > 0) {
                        $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                        --$errorLineBudget;
                    }
                }
                $this->em->remove($episode);
                $this->em->flush();
            } catch (\Throwable $e) {
                ++$uploadErrors;
                $this->logger->error('Failed to attach media to episode', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }
        }

        if ($rollingKeepN && $targetKeys !== []) {
            $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);
            $podcastId = $podcast->id;

            $allEpisodes = $this->em->createQuery(
                <<<'DQL'
                    SELECT e FROM App\Entity\PodcastEpisode e
                    WHERE e.podcast = :podcast
                DQL
            )->setParameter('podcast', $podcast)->getResult();

            $episodeIdsToRemove = [];
            foreach ($allEpisodes as $episode) {
                $link = $episode->link;
                if ($link === null || $link === '' || !isset($targetKeys[$link])) {
                    $episodeIdsToRemove[] = $episode->id;
                }
            }

            foreach ($episodeIdsToRemove as $episodeId) {
                $this->ensureEntityManagerOpen();
                $podcast = $this->em->find(Podcast::class, $podcastId);
                if ($podcast === null) {
                    break;
                }
                $episode = $this->podcastEpisodeRepo->fetchEpisodeForPodcast($podcast, $episodeId);
                if ($episode === null) {
                    continue;
                }
                $this->syncLogLine(
                    $syncLog,
                    'info',
                    sprintf('Removing episode outside top %d: %s', $n, $episode->title)
                );
                try {
                    $this->podcastEpisodeRepo->delete($episode, $fs);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to delete podcast episode during rolling sync', [
                        'episode_id' => $episodeId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->syncLogLine(
                        $syncLog,
                        'warning',
                        sprintf('Could not remove episode %s: %s', $episodeId, $e->getMessage())
                    );
                    $this->ensureEntityManagerOpen();
                }
            }
        }

        if ($skippedUnsupportedMedia > 0) {
            $this->syncLogLine(
                $syncLog,
                'info',
                sprintf('Skipped %d non-audio item(s) while syncing.', $skippedUnsupportedMedia)
            );
        }
        if ($downloadErrors > 0) {
            $this->syncLogLine($syncLog, 'warning', sprintf('%d download error(s).', $downloadErrors));
        }
        if ($uploadErrors > 0) {
            $this->syncLogLine($syncLog, 'warning', sprintf('%d upload/storage error(s).', $uploadErrors));
        }

        if ($touched > 0) {
            $this->logger->info('Synced podcast feed (latest / top-N)', [
                'podcast' => $podcast->title,
                'touched' => $touched,
            ]);
        }
        $this->syncLogLine($syncLog, 'info', sprintf('Done. %d episode(s) imported or refreshed this run.', $touched));

        return ['added' => $touched, 'ok' => true];
    }

    /**
     * After a failed flush, Doctrine closes the EntityManager; reopen and return a managed podcast.
     * After {@see ReloadableEntityManagerInterface::open()} replaces the wrapped EM, old entity instances
     * are detached — always refetch when the podcast is not contained in the current EM.
     */
    private function ensureEntityManagerOpenForPodcast(Podcast $podcast): Podcast
    {
        if (!$this->em->isOpen()) {
            $this->em->open();
        }

        if ($this->em->contains($podcast)) {
            return $podcast;
        }

        $fresh = $this->em->find(Podcast::class, $podcast->id);

        return $fresh instanceof Podcast ? $fresh : $podcast;
    }

    /**
     * Recreate the EntityManager if a previous flush or operation closed it.
     */
    private function ensureEntityManagerOpen(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        $this->em->open();
    }

    /**
     * Remove a podcast_episode row after a failed import when the EntityManager may already be closed.
     */
    private function removeOrphanEpisodeAfterFailedImport(Podcast $podcast, PodcastEpisode $episode): Podcast
    {
        $episodeId = $episode->id;
        if (!$this->em->isOpen()) {
            $this->em->open();
            $podcast = $this->em->refetch($podcast);
        }

        $toRemove = $this->podcastEpisodeRepo->fetchEpisodeForPodcast($podcast, $episodeId);
        if ($toRemove === null) {
            return $podcast;
        }

        try {
            $this->em->remove($toRemove);
            $this->em->flush();
        } catch (\Throwable) {
        }

        return $podcast;
    }

    /**
     * @param list<array{level: string, message: string}>|null $syncLog
     */
    private function syncLogLine(?array &$syncLog, string $level, string $message): void
    {
        if (null !== $syncLog) {
            $syncLog[] = ['level' => $level, 'message' => $message];
        }
    }

    /**
     * Download enclosure from feed item and attach media to an existing episode.
     * Updates episode title/description/publish_at from the item.
     *
     * @param list<array{level: string, message: string}>|null $syncLog
     *
     * @return bool true if media was attached successfully
     */
    private function attachFeedItemMediaToExistingEpisode(
        PodcastEpisode $episode,
        SimpleXMLElement $item,
        ExtendedFilesystemInterface $fs,
        string $tempDir,
        string $tempFilenamePrefix,
        ?array &$syncLog,
        int &$errorLineBudget
    ): bool {
        $this->ensureEntityManagerOpen();
        $episodeId = $episode->id;
        $managedEpisode = $this->em->find(PodcastEpisode::class, $episodeId);
        if ($managedEpisode === null) {
            return false;
        }
        $episode = $managedEpisode;

        $enclosureUrl = $this->getEnclosureUrl($item);
        if ($enclosureUrl === null || $this->isSkippablePodcastEnclosureMime($this->getEnclosureMimeHint($item))) {
            return false;
        }

        $title = $this->getItemTitle($item);
        $episode->title = $title;
        $episode->description = $this->getItemDescription($item);
        $episode->publish_at = $this->getItemPublishAt($item);
        $this->em->persist($episode);
        $this->em->flush();

        $downloadedPath = $tempDir . '/' . $tempFilenamePrefix . $episode->id . '_' . md5($enclosureUrl);
        try {
            $this->httpClient->get($enclosureUrl, [
                RequestOptions::SINK => $downloadedPath,
                RequestOptions::TIMEOUT => 300,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to download episode media', [
                'episode' => $title,
                'error' => $e->getMessage(),
            ]);
            if ($errorLineBudget > 0 && $syncLog !== null) {
                $this->syncLogLine($syncLog, 'error', sprintf('Download failed [%s]: %s', $title, $e->getMessage()));
                --$errorLineBudget;
            }
            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }

            return false;
        }

        if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime(MimeType::getMimeTypeFromFile($downloadedPath))) {
            if ($syncLog !== null) {
                $this->syncLogLine($syncLog, 'info', sprintf('Skipped bad file [%s]', $title));
            }
            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }

            return false;
        }

        $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
        $originalName = $title . '.' . $ext;

        try {
            $this->podcastEpisodeRepo->uploadMedia($episode, $originalName, $downloadedPath, $fs);
        } catch (CannotProcessMediaException|\InvalidArgumentException $e) {
            if ($this->isSkippablePodcastImportException($e)) {
                if ($syncLog !== null) {
                    $this->syncLogLine($syncLog, 'info', sprintf('Skipped [%s]: %s', $title, $e->getMessage()));
                }
            } elseif ($errorLineBudget > 0 && $syncLog !== null) {
                $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                --$errorLineBudget;
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to attach media to episode', [
                'episode' => $title,
                'error' => $e->getMessage(),
            ]);
            if ($errorLineBudget > 0 && $syncLog !== null) {
                $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                --$errorLineBudget;
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }

            return false;
        }

        if (file_exists($downloadedPath)) {
            @unlink($downloadedPath);
        }

        return true;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function getRssItems(SimpleXMLElement $xml): array
    {
        return RssAtomFeedItems::fromParsedXml($xml);
    }

    private function getItemGuid(SimpleXMLElement $item): ?string
    {
        $guid = $item->guid ?? null;
        if ($guid !== null) {
            return trim((string) $guid);
        }
        $link = $item->link ?? null;
        if ($link !== null) {
            $href = (string) $link;
            if (isset($link['href'])) {
                $href = (string) $link['href'];
            }
            return trim($href) ?: null;
        }
        return null;
    }

    private function getEnclosureUrl(SimpleXMLElement $item): ?string
    {
        if (isset($item->enclosure['url'])) {
            return trim((string) $item->enclosure['url']);
        }
        if (isset($item->link['href'])) {
            $type = (string) ($item->link['type'] ?? '');
            if (str_starts_with($type, 'audio/') || $type === 'video/mp4') {
                return trim((string) $item->link['href']);
            }
        }
        return null;
    }

    /**
     * MIME from RSS &lt;enclosure type="..."&gt; or Atom link when present.
     */
    private function getEnclosureMimeHint(SimpleXMLElement $item): ?string
    {
        if (isset($item->enclosure['url'], $item->enclosure['type'])) {
            $t = trim((string) $item->enclosure['type']);

            return $t !== '' ? $t : null;
        }
        if (isset($item->link['href'])) {
            $type = trim((string) ($item->link['type'] ?? ''));

            return $type !== '' ? $type : null;
        }

        return null;
    }

    private function normalizeMimeType(?string $mime): ?string
    {
        if ($mime === null || $mime === '') {
            return null;
        }
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return $mime !== '' ? $mime : null;
    }

    /**
     * Episodes we skip quietly: MP4 video, HTML/error pages, and other non-MP3/M4A types
     * handled after download or via upload validation.
     */
    private function isSkippablePodcastEnclosureMime(?string $mime): bool
    {
        $mime = $this->normalizeMimeType($mime);
        if ($mime === null) {
            return false;
        }
        if ($mime === 'video/mp4') {
            return true;
        }
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return false;
    }

    private function isSkippablePodcastImportException(\Throwable $e): bool
    {
        if ($e instanceof CannotProcessMediaException) {
            return true;
        }
        if ($e instanceof \InvalidArgumentException
            && str_contains($e->getMessage(), 'Invalid Podcast Media mime type')
            && str_contains($e->getMessage(), 'video/mp4')) {
            return true;
        }

        return false;
    }

    private function getItemTitle(SimpleXMLElement $item): string
    {
        $title = $item->title ?? null;
        return trim((string) $title) ?: 'Untitled Episode';
    }

    private function getItemDescription(SimpleXMLElement $item): string
    {
        $desc = $item->description ?? $item->summary ?? null;
        $text = trim((string) $desc);
        if ($text !== '') {
            $text = strip_tags($text);
            return mb_substr($text, 0, 4000, 'UTF-8');
        }
        return 'No description';
    }

    private function getItemPublishAt(SimpleXMLElement $item): int
    {
        $date = $item->pubDate ?? $item->published ?? null;
        if ($date !== null) {
            $ts = strtotime((string) $date);
            if ($ts !== false) {
                return $ts;
            }
        }
        return time();
    }

    private function pruneOldEpisodes(Podcast $podcast, ExtendedFilesystemInterface $fs): int
    {
        $keep = $podcast->auto_keep_episodes;
        if ($keep <= 0) {
            return 0;
        }

        $episodes = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\PodcastEpisode e
                WHERE e.podcast = :podcast
                ORDER BY e.publish_at DESC
            DQL
        )->setParameter('podcast', $podcast)->getResult();

        $toRemove = array_slice($episodes, $keep);
        foreach ($toRemove as $episode) {
            $this->podcastEpisodeRepo->delete($episode, $fs);
        }

        $n = count($toRemove);
        if ($n > 0) {
            $this->logger->info('Pruned old podcast episodes', [
                'podcast' => $podcast->title,
                'removed' => $n,
            ]);
        }

        return $n;
    }

    /**
     * @return array<string, array{episode_id: string, has_media: bool}>
     */
    private function getExistingEpisodeImportMap(Podcast $podcast): array
    {
        $episodes = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\PodcastEpisode e
                WHERE e.podcast = :podcast
            DQL
        )->setParameter('podcast', $podcast)->getResult();

        $map = [];
        foreach ($episodes as $episode) {
            $link = $episode->link;
            if ($link !== null && $link !== '') {
                $map[$link] = [
                    'episode_id' => $episode->id,
                    'has_media' => $episode->media !== null || $episode->playlist_media !== null,
                ];
            }
        }

        return $map;
    }

    /**
     * @return array{success: bool, items: list<array<string, mixed>>, message?: string}
     */
    public function getFeedItemsPreview(Podcast $podcast): array
    {
        $feedUrl = $podcast->feed_url;
        if (empty($feedUrl)) {
            return ['success' => false, 'items' => [], 'message' => 'Podcast has no feed URL.'];
        }

        try {
            $response = $this->httpClient->get($feedUrl, [
                RequestOptions::TIMEOUT => 30,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast/1.0 (Podcast Feed Preview)',
                ],
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'items' => [], 'message' => 'Failed to fetch feed: ' . $e->getMessage()];
        }

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return ['success' => false, 'items' => [], 'message' => 'Invalid XML in feed.'];
        }

        $items = $this->getRssItems($xml);
        $this->feedRemoteItemCount->primeCachedCount($feedUrl, count($items));
        usort($items, function (SimpleXMLElement $a, SimpleXMLElement $b): int {
            return $this->getItemPublishAt($b) <=> $this->getItemPublishAt($a);
        });

        $importMap = $this->getExistingEpisodeImportMap($podcast);
        $out = [];

        foreach ($items as $item) {
            $guid = $this->getItemGuid($item);
            $enclosureUrl = $this->getEnclosureUrl($item);
            $mimeHint = $this->getEnclosureMimeHint($item);
            $key = $guid ?: $enclosureUrl;
            if ($key === null || $key === '') {
                continue;
            }

            $imported = false;
            $episodeId = null;
            $hasMedia = false;
            if ($guid !== null && $guid !== '' && isset($importMap[$guid])) {
                $imported = true;
                $episodeId = $importMap[$guid]['episode_id'];
                $hasMedia = $importMap[$guid]['has_media'];
            } elseif ($enclosureUrl !== null && isset($importMap[$enclosureUrl])) {
                $imported = true;
                $episodeId = $importMap[$enclosureUrl]['episode_id'];
                $hasMedia = $importMap[$enclosureUrl]['has_media'];
            }

            $noAudio = $enclosureUrl === null
                || $this->isSkippablePodcastEnclosureMime($mimeHint);

            $out[] = [
                'key' => $key,
                'title' => $this->getItemTitle($item),
                'published_at' => $this->getItemPublishAt($item),
                'enclosure_url' => $enclosureUrl,
                'enclosure_type' => $mimeHint,
                'no_audio' => $noAudio,
                'imported' => $imported,
                'episode_id' => $episodeId,
                'has_media' => $hasMedia,
            ];
        }

        return ['success' => true, 'items' => $out];
    }

    /**
     * Import specific feed items by the same `key` values returned from getFeedItemsPreview.
     *
     * @param list<string> $keys
     *
     * @return array{success: bool, episodes_added: int, log: list<array{level: string, message: string}>, message?: string}
     */
    public function importFeedItemsByKeys(Podcast $podcast, array $keys): array
    {
        $log = [];
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        if ($keys === []) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'No episode keys provided.']],
                'message' => 'No keys.',
            ];
        }

        if (count($keys) > 40) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'Maximum 40 episodes per request.']],
                'message' => 'Too many keys.',
            ];
        }

        $stations = $this->storageLocationRepo->getStationsUsingLocation($podcast->storage_location);
        $station = $stations[0] ?? null;
        if (!$station instanceof Station) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'No station for podcast storage.']],
                'message' => 'No station.',
            ];
        }

        $feedUrl = $podcast->feed_url;
        if (empty($feedUrl)) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'No feed URL.']],
                'message' => 'No feed.',
            ];
        }

        try {
            $response = $this->httpClient->get($feedUrl, [
                RequestOptions::TIMEOUT => 30,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                ],
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'Fetch failed: ' . $e->getMessage()]],
                'message' => $e->getMessage(),
            ];
        }

        $xml = @simplexml_load_string((string) $response->getBody());
        if ($xml === false) {
            return [
                'success' => false,
                'episodes_added' => 0,
                'log' => [['level' => 'error', 'message' => 'Invalid feed XML.']],
                'message' => 'Bad XML.',
            ];
        }

        $items = $this->getRssItems($xml);
        $this->feedRemoteItemCount->primeCachedCount($feedUrl, count($items));
        $itemByKey = [];
        foreach ($items as $item) {
            $guid = $this->getItemGuid($item);
            $url = $this->getEnclosureUrl($item);
            $k = $guid ?: $url;
            if ($k !== null && $k !== '') {
                $itemByKey[$k] = $item;
            }
        }

        $fs = $this->stationFilesystems->getPodcastsFilesystem($station);
        $tempDir = $station->getRadioTempDir();
        $importMap = $this->getExistingEpisodeImportMap($podcast);
        $added = 0;
        $errorLineBudget = 20;

        foreach ($keys as $key) {
            $item = $itemByKey[$key] ?? null;
            if ($item === null) {
                $this->syncLogLine($log, 'warning', sprintf('Key not found in feed: %s', mb_substr($key, 0, 80)));
                continue;
            }

            $guid = $this->getItemGuid($item);
            $enclosureUrl = $this->getEnclosureUrl($item);
            if ($enclosureUrl === null) {
                $this->syncLogLine($log, 'warning', sprintf('No enclosure [%s]', $this->getItemTitle($item)));
                continue;
            }

            if ($this->isSkippablePodcastEnclosureMime($this->getEnclosureMimeHint($item))) {
                $this->syncLogLine($log, 'info', sprintf('Skipped (non-audio) [%s]', $this->getItemTitle($item)));
                continue;
            }

            $linkVal = $guid ?: $enclosureUrl;
            $title = $this->getItemTitle($item);
            if ($linkVal !== '') {
                $existing = $importMap[$linkVal] ?? null;
                if ($existing !== null && $existing['has_media']) {
                    $this->syncLogLine($log, 'info', sprintf('Already imported [%s]', $title));
                    continue;
                }
                if ($existing !== null && !$existing['has_media']) {
                    $episode = $this->podcastEpisodeRepo->fetchEpisodeForPodcast($podcast, $existing['episode_id']);
                    if ($episode !== null) {
                        if ($this->attachFeedItemMediaToExistingEpisode(
                            $episode,
                            $item,
                            $fs,
                            $tempDir,
                            'podcast_sel_',
                            $log,
                            $errorLineBudget
                        )) {
                            ++$added;
                            $this->syncLogLine($log, 'info', sprintf('Re-imported media for: %s', $title));
                            $importMap[$linkVal]['has_media'] = true;
                        }
                        continue;
                    }
                }
            }

            $description = $this->getItemDescription($item);
            $publishAt = $this->getItemPublishAt($item);

            $episode = new PodcastEpisode($podcast);
            $episode->title = $title;
            $episode->description = $description;
            $episode->publish_at = $publishAt;
            $episode->explicit = $podcast->explicit;
            if ($linkVal !== '') {
                $episode->link = $linkVal;
            }
            $this->em->persist($episode);
            $this->em->flush();

            $downloadedPath = $tempDir . '/' . 'podcast_sel_' . $episode->id . '_' . md5($enclosureUrl);
            try {
                $this->httpClient->get($enclosureUrl, [
                    RequestOptions::SINK => $downloadedPath,
                    RequestOptions::TIMEOUT => 300,
                    RequestOptions::HEADERS => [
                        'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                    ],
                ]);
            } catch (\Throwable $e) {
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($log, 'error', sprintf('Download failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }

                continue;
            }

            if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime(MimeType::getMimeTypeFromFile($downloadedPath))) {
                $this->syncLogLine($log, 'info', sprintf('Skipped bad file [%s]', $title));
                $this->em->remove($episode);
                $this->em->flush();
                @unlink($downloadedPath);

                continue;
            }

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $title . '.' . $ext;

            try {
                $this->podcastEpisodeRepo->uploadMedia(
                    $episode,
                    $originalName,
                    $downloadedPath,
                    $fs
                );
                ++$added;
                $this->syncLogLine($log, 'info', sprintf('Imported: %s', $title));
                if ($linkVal !== '') {
                    $importMap[$linkVal] = ['episode_id' => $episode->id, 'has_media' => true];
                }
            } catch (CannotProcessMediaException|\InvalidArgumentException $e) {
                if ($this->isSkippablePodcastImportException($e)) {
                    $this->syncLogLine($log, 'info', sprintf('Skipped [%s]: %s', $title, $e->getMessage()));
                } elseif ($errorLineBudget > 0) {
                    $this->syncLogLine($log, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
            } catch (\Throwable $e) {
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($log, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                $this->em->remove($episode);
                $this->em->flush();
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }
        }

        if ($podcast->auto_keep_episodes > 0) {
            $pruned = $this->pruneOldEpisodes($podcast, $fs);
            if ($pruned > 0) {
                $this->syncLogLine($log, 'info', sprintf('Pruned %d older episode(s).', $pruned));
            }
        }

        $this->syncLogLine($log, 'info', sprintf('Done. %d episode(s) imported.', $added));

        return [
            'success' => true,
            'episodes_added' => $added,
            'log' => $log,
        ];
    }
}
