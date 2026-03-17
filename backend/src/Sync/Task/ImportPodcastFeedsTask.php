<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Entity\Enums\PodcastSources;
use App\Entity\Podcast;
use App\Entity\PodcastEpisode;
use App\Entity\Repository\PodcastEpisodeRepository;
use App\Entity\Repository\PodcastRepository;
use App\Entity\Repository\StorageLocationRepository;
use App\Entity\Station;
use App\Flysystem\StationFilesystems;
use GuzzleHttp\Client;
use App\Flysystem\ExtendedFilesystemInterface;
use GuzzleHttp\RequestOptions;
use SimpleXMLElement;

final class ImportPodcastFeedsTask extends AbstractTask
{
    public function __construct(
        private readonly Client $httpClient,
        private readonly PodcastRepository $podcastRepository,
        private readonly PodcastEpisodeRepository $podcastEpisodeRepo,
        private readonly StorageLocationRepository $storageLocationRepo,
        private readonly StationFilesystems $stationFilesystems
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

    /** Run import for a single podcast (e.g. from "Sync now" API). */
    public function runForPodcast(Podcast $podcast): void
    {
        $stations = $this->storageLocationRepo->getStationsForLocation($podcast->storage_location);
        $station = $stations[0] ?? null;
        if ($station instanceof Station) {
            $this->importFeed($podcast, $station);
        }
    }

    private function importFeedsForStation(Station $station): void
    {
        $podcasts = $this->em->createQuery(
            <<<'DQL'
                SELECT p FROM App\Entity\Podcast p
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
            $this->importFeed($podcast, $station);
        }
    }

    private function importFeed(Podcast $podcast, Station $station): void
    {
        $feedUrl = $podcast->feed_url;
        if (empty($feedUrl)) {
            return;
        }

        $this->logger->info('Importing podcast feed', [
            'podcast' => $podcast->title,
            'feed_url' => $feedUrl,
        ]);

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
            return;
        }

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            $this->logger->error('Invalid XML in podcast feed', ['podcast' => $podcast->title]);
            return;
        }

        $items = $this->getRssItems($xml);
        if (empty($items)) {
            $this->logger->debug('No items in podcast feed', ['podcast' => $podcast->title]);
            return;
        }

        $fs = $this->stationFilesystems->getPodcastsFilesystem($station);
        $existingGuids = $this->getExistingEpisodeGuids($podcast);
        $tempDir = $station->getRadioTempDir();
        $added = 0;

        foreach ($items as $item) {
            $guid = $this->getItemGuid($item);
            if ($guid !== null && isset($existingGuids[$guid])) {
                continue;
            }

            $enclosureUrl = $this->getEnclosureUrl($item);
            if ($enclosureUrl === null) {
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
                $this->logger->error('Failed to download episode media', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
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
                $added++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to attach media to episode', [
                    'episode' => $title,
                    'error' => $e->getMessage(),
                ]);
                $this->em->remove($episode);
                $this->em->flush();
            }

            if (file_exists($downloadedPath)) {
                @unlink($downloadedPath);
            }
        }

        if ($podcast->auto_keep_episodes > 0) {
            $this->pruneOldEpisodes($podcast, $fs);
        }

        if ($added > 0) {
            $this->logger->info('Imported podcast episodes', [
                'podcast' => $podcast->title,
                'added' => $added,
            ]);
        }
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function getRssItems(SimpleXMLElement $xml): array
    {
        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $item;
            }
        }
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $items[] = $entry;
            }
        }
        return $items;
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

    /** @return array<string, true> */
    private function getExistingEpisodeGuids(Podcast $podcast): array
    {
        $episodes = $this->em->createQuery(
            <<<'DQL'
                SELECT e.link FROM App\Entity\PodcastEpisode e
                WHERE e.podcast = :podcast AND e.link IS NOT NULL AND e.link != ''
            DQL
        )->setParameter('podcast', $podcast)->getArrayResult();

        $guids = [];
        foreach ($episodes as $row) {
            $guids[$row['link']] = true;
        }
        return $guids;
    }

    private function pruneOldEpisodes(Podcast $podcast, ExtendedFilesystemInterface $fs): void
    {
        $keep = $podcast->auto_keep_episodes;
        if ($keep <= 0) {
            return;
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

        if (count($toRemove) > 0) {
            $this->logger->info('Pruned old podcast episodes', [
                'podcast' => $podcast->title,
                'removed' => count($toRemove),
            ]);
        }
    }
}
