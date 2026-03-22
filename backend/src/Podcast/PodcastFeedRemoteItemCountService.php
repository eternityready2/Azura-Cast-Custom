<?php

declare(strict_types=1);

namespace App\Podcast;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\SimpleCache\CacheInterface;
use SimpleXMLElement;

/**
 * Counts RSS/Atom items at a feed URL (cached). Used so API "episodes" can reflect the full feed size.
 */
final class PodcastFeedRemoteItemCountService
{
    private const string CACHE_KEY_PREFIX = 'podcast_feed_item_count.v1.';

    private const int CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly Client $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    public function countItemsForFeedUrl(string $feedUrl): ?int
    {
        $key = self::CACHE_KEY_PREFIX . md5($feedUrl);
        $cached = $this->cache->get($key);
        if (is_int($cached)) {
            return $cached;
        }

        $count = $this->fetchAndCount($feedUrl);
        if ($count !== null) {
            $this->cache->set($key, $count, self::CACHE_TTL_SECONDS);
        }

        return $count;
    }

    public function primeCachedCount(string $feedUrl, int $count): void
    {
        $key = self::CACHE_KEY_PREFIX . md5($feedUrl);
        $this->cache->set($key, $count, self::CACHE_TTL_SECONDS);
    }

    public function countItemsFromParsedXml(SimpleXMLElement $xml): int
    {
        return count(RssAtomFeedItems::fromParsedXml($xml));
    }

    private function fetchAndCount(string $feedUrl): ?int
    {
        try {
            $response = $this->httpClient->get($feedUrl, [
                RequestOptions::TIMEOUT => 20,
                RequestOptions::HTTP_ERRORS => true,
                RequestOptions::HEADERS => [
                    'User-Agent' => 'AzuraCast/1.0 (Podcast Feed)',
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }

        return $this->countItemsFromParsedXml($xml);
    }
}
