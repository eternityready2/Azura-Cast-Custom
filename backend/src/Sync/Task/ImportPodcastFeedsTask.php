<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PodcastImportStrategy;
use App\Entity\Enums\PodcastEpisodeStorageType;
use App\Entity\Enums\PodcastSources;
use App\Entity\Podcast;
use App\Entity\PodcastEpisode;
use App\Entity\PodcastMedia;
use App\Entity\StationMedia;
use App\Entity\Repository\PodcastEpisodeRepository;
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
use League\Flysystem\StorageAttributes;
use SimpleXMLElement;

final class ImportPodcastFeedsTask extends AbstractTask
{
    public function __construct(
        private readonly Client $httpClient,
        private readonly PodcastEpisodeRepository $podcastEpisodeRepo,
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

        foreach ($podcasts as $podcast) {
            $fullBacklog = $podcast->import_strategy === PodcastImportStrategy::BackfillAll;
            try {
                $syncLog = null;
                $this->importFeed($podcast, $station, $syncLog, $fullBacklog);
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

        // If retention is exactly 1, enforce strict latest-single sync semantics even when strategy
        // is configured as backfill_all; this prevents repeated duplicates in rolling single-file feeds.
        if (!$fullBacklog || $podcast->auto_keep_episodes === 1) {
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

            $linkKey = ($guid !== null && $guid !== '') ? $guid : $enclosureUrl;

            $episode = new PodcastEpisode($podcast);
            $episode->title = $title;
            $episode->description = $description;
            $episode->publish_at = $publishAt;
            $episode->explicit = $podcast->explicit;
            if ($linkKey !== '') {
                $episode->link = $linkKey;
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
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                if ($linkKey !== '') {
                    $importMap[$linkKey] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $syncLog,
                    'info',
                    sprintf('Download failed; episode kept without media (retry on next sync): [%s]', $title)
                );

                continue;
            }

            $fileMime = MimeType::getMimeTypeFromFile($downloadedPath);
            if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime($fileMime)) {
                ++$skippedUnsupportedMedia;
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                if ($linkKey !== '') {
                    $importMap[$linkKey] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $syncLog,
                    'info',
                    sprintf('Unsupported file type; episode kept without media: [%s]', $title)
                );

                continue;
            }

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $this->getImportMediaOriginalFilename($item, $ext);

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
                if ($linkKey !== '') {
                    $importMap[$linkKey] = ['episode_id' => $episode->id, 'has_media' => true];
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
                if ($linkKey !== '') {
                    $importMap[$linkKey] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $syncLog,
                    'info',
                    sprintf('Upload failed; episode kept without media (retry on next sync): [%s]', $title)
                );
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
                if ($linkKey !== '') {
                    $importMap[$linkKey] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $syncLog,
                    'info',
                    sprintf('Upload failed; episode kept without media (retry on next sync): [%s]', $title)
                );
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
        $lockHandle = $this->acquireLatestSyncLock($podcast, $tempDir, $syncLog);
        if ($lockHandle === null) {
            return ['added' => 0, 'ok' => true];
        }

        try {
            $this->syncLogLine(
                $syncLog,
                'info',
                'Mode: strict single-latest sync (lock, skip-if-same, atomic replacement, duplicate cleanup).'
            );

            usort($items, function (SimpleXMLElement $a, SimpleXMLElement $b): int {
                return $this->getItemPublishAt($b) <=> $this->getItemPublishAt($a);
            });

            $latestItem = null;
            foreach ($items as $item) {
                $enclosureUrl = $this->getEnclosureUrl($item);
                $mimeHint = $this->getEnclosureMimeHint($item);
                if ($enclosureUrl === null || $this->isSkippablePodcastEnclosureMime($mimeHint)) {
                    continue;
                }
                $latestItem = $item;
                break;
            }

            if ($latestItem === null) {
                $this->syncLogLine(
                    $syncLog,
                    'warning',
                    'No feed items with a downloadable audio enclosure; cannot sync.'
                );

                return ['added' => 0, 'ok' => true];
            }

            $latestEpisodeIdentity = $this->extractLatestEpisodeIdentity($latestItem);
            $latestEpisodeId = $latestEpisodeIdentity['id'];
            $statePath = $this->getLatestSyncStatePath($podcast);
            $lastProcessedEpisodeIdentity = $this->getPersistedLatestEpisodeIdentity($fs, $statePath);
            $currentLatestEpisode = $this->getCurrentLatestEpisode($podcast);
            $hasCurrentLatestMedia = $currentLatestEpisode instanceof PodcastEpisode
                && $this->episodeAudioFileExistsOnDisk($currentLatestEpisode, $station);
            if (
                $hasCurrentLatestMedia
                && $currentLatestEpisode !== null
                && $lastProcessedEpisodeIdentity !== null
                && (
                    ($lastProcessedEpisodeIdentity['id'] !== null
                        && hash_equals($lastProcessedEpisodeIdentity['id'], $latestEpisodeId))
                    || (
                        $lastProcessedEpisodeIdentity['url_hash'] !== null
                        && $latestEpisodeIdentity['url_hash'] !== null
                        && hash_equals($lastProcessedEpisodeIdentity['url_hash'], $latestEpisodeIdentity['url_hash'])
                    )
                )
            ) {
                $this->cleanupPodcastEpisodeDuplicates($podcast, $station, $fs, $syncLog);
                $this->syncLogLine($syncLog, 'info', 'Already on latest episode; skipping download.');

                return ['added' => 0, 'ok' => true];
            }

            $enclosureUrl = $this->getEnclosureUrl($latestItem);
            if ($enclosureUrl === null) {
                return ['added' => 0, 'ok' => true];
            }
            $title = $this->getItemTitle($latestItem);
            $description = $this->getItemDescription($latestItem);
            $publishAt = $this->getItemPublishAt($latestItem);
            $episodeLinkKey = $this->getItemGuid($latestItem) ?: ($enclosureUrl ?? '');

            $downloadedPath = $tempDir . '/' . 'podcast_latest_' . $podcast->id . '_' . md5($enclosureUrl . microtime(true));
            try {
                $this->httpClient->get($enclosureUrl, [
                    RequestOptions::SINK => $downloadedPath,
                    RequestOptions::TIMEOUT => 300,
                    RequestOptions::HEADERS => [
                        'User-Agent' => 'AzuraCast/1.0 (Podcast Import)',
                    ],
                ]);
            } catch (\Throwable $e) {
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                $this->syncLogLine($syncLog, 'error', sprintf('Download failed [%s]: %s', $title, $e->getMessage()));

                return ['added' => 0, 'ok' => false, 'message' => 'Latest episode download failed.'];
            }

            $downloadMime = MimeType::getMimeTypeFromFile($downloadedPath);
            $downloadSize = @filesize($downloadedPath);
            if (
                !MimeType::isFileProcessable($downloadedPath)
                || $this->isSkippablePodcastEnclosureMime($downloadMime)
                || !is_int($downloadSize)
                || $downloadSize <= 0
            ) {
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                $this->syncLogLine($syncLog, 'warning', sprintf('Downloaded file invalid for [%s]; keeping current file.', $title));

                return ['added' => 0, 'ok' => true];
            }

            $currentEpisode = $this->getCurrentLatestEpisode($podcast);
            if ($currentEpisode === null) {
                $currentEpisode = new PodcastEpisode($podcast);
            }

            $currentEpisode->title = $title;
            $currentEpisode->description = $description;
            $currentEpisode->publish_at = $publishAt;
            $currentEpisode->explicit = $podcast->explicit;
            // Keep link format compatible with feed preview/import map UI markers.
            $currentEpisode->link = $episodeLinkKey !== '' ? $episodeLinkKey : $latestEpisodeId;
            $this->em->persist($currentEpisode);
            $this->em->flush();

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $this->getImportMediaOriginalFilename($latestItem, $ext);

            try {
                // uploadMedia performs replace/update for existing episode media after file is downloaded and validated.
                $this->podcastEpisodeRepo->uploadMedia(
                    $currentEpisode,
                    $originalName,
                    $downloadedPath,
                    $fs
                );
            } catch (\Throwable $e) {
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                $this->syncLogLine($syncLog, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));

                return ['added' => 0, 'ok' => false, 'message' => 'Latest episode upload failed.'];
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }

            $this->persistLatestEpisodeIdentity($fs, $statePath, $latestEpisodeIdentity);
            $this->cleanupPodcastEpisodeDuplicates($podcast, $station, $fs, $syncLog);

            $this->syncLogLine(
                $syncLog,
                'info',
                sprintf('Synced latest episode: %s', $title)
            );

            return ['added' => 1, 'ok' => true];
        } finally {
            $this->releaseLatestSyncLock($lockHandle);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLatestSyncLock(Podcast $podcast, string $tempDir, ?array &$syncLog)
    {
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $lockPath = $tempDir . '/podcast_latest_sync_' . $podcast->id . '.lock';
        $handle = @fopen($lockPath, 'c+');
        if (false === $handle) {
            $this->syncLogLine($syncLog, 'warning', 'Could not create lock file; skipping sync.');

            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->syncLogLine($syncLog, 'info', 'Another sync is already running for this podcast; skipping.');

            return null;
        }

        return $handle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseLatestSyncLock($lockHandle): void
    {
        if ($lockHandle === null) {
            return;
        }
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }

    /**
     * @return array{id: string, guid: ?string, url_hash: ?string}
     */
    private function extractLatestEpisodeIdentity(SimpleXMLElement $item): array
    {
        $guid = $this->getItemGuid($item);
        if ($guid !== null && $guid !== '') {
            $enclosureUrl = $this->getEnclosureUrl($item);
            $urlHash = $enclosureUrl !== null && $enclosureUrl !== ''
                ? md5($enclosureUrl)
                : null;

            return [
                'id' => 'guid:' . $guid,
                'guid' => $guid,
                'url_hash' => $urlHash,
            ];
        }
        $enclosureUrl = $this->getEnclosureUrl($item) ?? '';
        $urlHash = md5($enclosureUrl);

        return [
            'id' => 'url:' . $urlHash,
            'guid' => null,
            'url_hash' => $urlHash,
        ];
    }

    private function getLatestSyncStatePath(Podcast $podcast): string
    {
        return $podcast->id . '/.latest_sync_state.json';
    }

    /**
     * @return array{id: ?string, guid: ?string, url_hash: ?string}|null
     */
    private function getPersistedLatestEpisodeIdentity(ExtendedFilesystemInterface $fs, string $statePath): ?array
    {
        if (!$fs->fileExists($statePath)) {
            return null;
        }

        try {
            $raw = $fs->read($statePath);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $id = trim((string)($decoded['latest_episode_id'] ?? ''));
            $guid = trim((string)($decoded['latest_episode_guid'] ?? ''));
            $urlHash = trim((string)($decoded['latest_episode_url_hash'] ?? ''));
            if ($id === '' && $guid === '' && $urlHash === '') {
                return null;
            }

            return [
                'id' => $id !== '' ? $id : null,
                'guid' => $guid !== '' ? $guid : null,
                'url_hash' => $urlHash !== '' ? $urlHash : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{id: string, guid: ?string, url_hash: ?string} $identity
     */
    private function persistLatestEpisodeIdentity(
        ExtendedFilesystemInterface $fs,
        string $statePath,
        array $identity
    ): void {
        $payload = json_encode(
            [
                'latest_episode_id' => $identity['id'],
                'latest_episode_guid' => $identity['guid'],
                'latest_episode_url_hash' => $identity['url_hash'],
                'updated_at' => time(),
            ],
            JSON_THROW_ON_ERROR
        );
        $fs->write($statePath, $payload);
    }

    private function getCurrentLatestEpisode(Podcast $podcast): ?PodcastEpisode
    {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\PodcastEpisode e
                WHERE e.podcast = :podcast
                ORDER BY e.publish_at DESC
            DQL
        )->setParameter('podcast', $podcast)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * Remove duplicate episodes and non-state orphan files from podcast storage, preserving only latest episode media.
     *
     * @param list<array{level: string, message: string}>|null $syncLog
     */
    private function cleanupPodcastEpisodeDuplicates(
        Podcast $podcast,
        Station $station,
        ExtendedFilesystemInterface $fs,
        ?array &$syncLog
    ): void {
        $podcast = $this->ensureEntityManagerOpenForPodcast($podcast);
        $episodes = $this->em->createQuery(
            <<<'DQL'
                SELECT e FROM App\Entity\PodcastEpisode e
                WHERE e.podcast = :podcast
                ORDER BY e.publish_at DESC
            DQL
        )->setParameter('podcast', $podcast)->getResult();

        if ($episodes === []) {
            return;
        }

        $keepEpisode = $episodes[0];
        $keepPath = null;
        if ($keepEpisode->media instanceof PodcastMedia) {
            $keepPath = $keepEpisode->media->path;
        } elseif ($keepEpisode->playlist_media instanceof StationMedia) {
            $keepPath = $keepEpisode->playlist_media->path;
        }

        for ($i = 1, $count = count($episodes); $i < $count; $i++) {
            try {
                $this->podcastEpisodeRepo->delete($episodes[$i], $fs);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed removing duplicate podcast episode during latest sync cleanup', [
                    'podcast_id' => $podcast->id,
                    'episode_id' => $episodes[$i]->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $prefix = trim($podcast->id . '/', '/');
        $allowed = [];
        if ($keepPath !== null && $keepPath !== '') {
            $allowed[$keepPath] = true;
        }
        $allowed[$this->getLatestSyncStatePath($podcast)] = true;

        foreach ($fs->listContents($prefix, true) as $entry) {
            if (!$entry instanceof StorageAttributes || !$entry->isFile()) {
                continue;
            }
            $path = $entry->path();
            if (!isset($allowed[$path])) {
                try {
                    $fs->delete($path);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed deleting orphaned podcast file during latest sync cleanup', [
                        'podcast_id' => $podcast->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($podcast->episode_storage_type === PodcastEpisodeStorageType::Media) {
            $mediaFs = $this->stationFilesystems->getMediaFilesystem($station);
            $mediaPrefix = (null !== $podcast->media_folder_path && '' !== trim($podcast->media_folder_path))
                ? trim($podcast->media_folder_path, '/')
                : '';
            $allowedMediaPath = null;
            if ($keepEpisode->playlist_media instanceof StationMedia) {
                $allowedMediaPath = $keepEpisode->playlist_media->path;
            }

            foreach ($mediaFs->listContents($mediaPrefix, true) as $entry) {
                if (!$entry instanceof StorageAttributes || !$entry->isFile()) {
                    continue;
                }
                $path = $entry->path();
                if ($allowedMediaPath !== null && hash_equals($allowedMediaPath, $path)) {
                    continue;
                }

                // For safety, only remove files that match the auto-import naming pattern (<episode-uuid>.<ext>).
                $basename = basename($path);
                if (!preg_match('/^[a-f0-9-]{36}\.[A-Za-z0-9]+$/', $basename)) {
                    continue;
                }
                try {
                    $mediaFs->delete($path);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed deleting orphaned media-folder file during latest sync cleanup', [
                        'podcast_id' => $podcast->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->syncLogLine($syncLog, 'info', 'Cleaned duplicate episodes and orphaned files.');
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
        $originalName = $this->getImportMediaOriginalFilename($item, $ext);

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

    /**
     * RSS &lt;guid&gt; only (no item link fallback). Used for stable imported media filenames.
     */
    private function getRssGuidOnly(SimpleXMLElement $item): ?string
    {
        $guid = $item->guid ?? null;
        if ($guid === null) {
            return null;
        }
        $s = trim((string) $guid);

        return $s !== '' ? $s : null;
    }

    private function sanitizeMediaFilenameBase(string $raw): string
    {
        $s = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $raw) ?? '';
        $s = trim($s, '-.');
        if ($s === '') {
            return '';
        }
        if (mb_strlen($s) > 180) {
            $s = mb_substr($s, 0, 180);
        }

        return $s;
    }

    /**
     * Preferred name for podcast import uploads: sanitized RSS guid + extension; if no guid, feed-{md5(url)}.
     */
    private function getImportMediaOriginalFilename(SimpleXMLElement $item, string $ext): string
    {
        $guid = $this->getRssGuidOnly($item);
        if ($guid !== null) {
            $base = $this->sanitizeMediaFilenameBase($guid);
            if ($base !== '') {
                return $base . '.' . $ext;
            }
        }

        $enclosureUrl = $this->getEnclosureUrl($item);
        $fallback = $enclosureUrl !== null ? 'feed-' . md5($enclosureUrl) : 'media';

        return $fallback . '.' . $ext;
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
     * True when the episode’s audio file exists on the station filesystem (podcast or media storage).
     */
    private function episodeAudioFileExistsOnDisk(PodcastEpisode $episode, Station $station): bool
    {
        if ($episode->media instanceof PodcastMedia) {
            $fs = $this->stationFilesystems->getPodcastsFilesystem($station);

            return $fs->fileExists($episode->media->path);
        }
        if ($episode->playlist_media instanceof StationMedia) {
            $fs = $this->stationFilesystems->getMediaFilesystem($station);

            return $fs->fileExists($episode->playlist_media->path);
        }

        return false;
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
        $stations = $this->storageLocationRepo->getStationsUsingLocation($podcast->storage_location);
        $station = $stations[0] ?? null;

        $episodeById = [];
        if ($station instanceof Station) {
            /** @var list<PodcastEpisode> $preloaded */
            $preloaded = $this->em->createQuery(
                <<<'DQL'
                    SELECT e, pm, plm FROM App\Entity\PodcastEpisode e
                    LEFT JOIN e.media pm
                    LEFT JOIN e.playlist_media plm
                    WHERE e.podcast = :podcast
                DQL
            )->setParameter('podcast', $podcast)->getResult();
            foreach ($preloaded as $ep) {
                $episodeById[$ep->id] = $ep;
            }
        }

        $out = [];

        foreach ($items as $item) {
            $guid = $this->getItemGuid($item);
            $enclosureUrl = $this->getEnclosureUrl($item);
            $mimeHint = $this->getEnclosureMimeHint($item);
            // Must match episode.link and import keys: getItemGuid() ?: enclosure (same as sync).
            $syncKey = $guid ?: $enclosureUrl;
            if ($syncKey === null || $syncKey === '') {
                continue;
            }

            $imported = isset($importMap[$syncKey]);
            $episodeId = $imported ? $importMap[$syncKey]['episode_id'] : null;

            $hasMedia = false;
            if ($imported && $episodeId !== null && $station instanceof Station) {
                $episode = $episodeById[$episodeId] ?? null;
                if ($episode instanceof PodcastEpisode) {
                    $hasMedia = $this->episodeAudioFileExistsOnDisk($episode, $station);
                }
            }

            $noAudio = $enclosureUrl === null
                || $this->isSkippablePodcastEnclosureMime($mimeHint);

            $out[] = [
                'key' => $syncKey,
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
                if ($existing !== null) {
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
                            $this->syncLogLine(
                                $log,
                                'info',
                                $existing['has_media']
                                    ? sprintf('Updated media for: %s', $title)
                                    : sprintf('Re-imported media for: %s', $title)
                            );
                            $importMap[$linkVal]['has_media'] = true;
                        }
                    }
                    continue;
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
                if (file_exists($downloadedPath)) {
                    @unlink($downloadedPath);
                }
                if ($linkVal !== '') {
                    $importMap[$linkVal] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $log,
                    'info',
                    sprintf('Download failed; episode kept without media: [%s]', $title)
                );

                continue;
            }

            if (!MimeType::isFileProcessable($downloadedPath) || $this->isSkippablePodcastEnclosureMime(MimeType::getMimeTypeFromFile($downloadedPath))) {
                $this->syncLogLine($log, 'info', sprintf('Skipped bad file [%s]', $title));
                @unlink($downloadedPath);
                if ($linkVal !== '') {
                    $importMap[$linkVal] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $log,
                    'info',
                    sprintf('Unsupported file; episode kept without media: [%s]', $title)
                );

                continue;
            }

            $ext = pathinfo(parse_url($enclosureUrl, PHP_URL_PATH) ?: 'audio.mp3', PATHINFO_EXTENSION) ?: 'mp3';
            $originalName = $this->getImportMediaOriginalFilename($item, $ext);

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
                if ($linkVal !== '') {
                    $importMap[$linkVal] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $log,
                    'info',
                    sprintf('Upload failed; episode kept without media: [%s]', $title)
                );
            } catch (\Throwable $e) {
                if ($errorLineBudget > 0) {
                    $this->syncLogLine($log, 'error', sprintf('Upload failed [%s]: %s', $title, $e->getMessage()));
                    --$errorLineBudget;
                }
                if ($linkVal !== '') {
                    $importMap[$linkVal] = ['episode_id' => $episode->id, 'has_media' => false];
                }
                $this->syncLogLine(
                    $log,
                    'info',
                    sprintf('Upload failed; episode kept without media: [%s]', $title)
                );
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
