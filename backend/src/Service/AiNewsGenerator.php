<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Podcast\RssAtomFeedItems;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Process\Process;
use Throwable;

final class AiNewsGenerator
{
    use LoggerAwareTrait;

    private const string PIPER_BIN = 'piper';
    private const string FFMPEG_BIN = 'ffmpeg';
    private const string DEFAULT_VOICE_MODEL =
        '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx';
    public const array AVAILABLE_VOICE_MODELS = [
        [
            'label' => 'Lessac (Default)',
            'path' => '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx',
        ],
        [
            'label' => 'Joe',
            'path' => '/usr/local/share/piper-voices/en/en_US/joe/medium/en_US-joe-medium.onnx',
        ],
        [
            'label' => 'Ryan',
            'path' => '/usr/local/share/piper-voices/en/en_US/ryan/medium/en_US-ryan-medium.onnx',
        ],
    ];
    public const string OUTPUT_FILENAME = 'news_bulletin.mp3';
    public const array DEFAULT_SOURCE_URLS = [
        'https://worthynews.com/',
        'https://www.raptureready.com/',
    ];
    private const int MAX_STORY_COUNT = 25;
    private const int HTTP_TIMEOUT = 30;
    private const int MAX_HTML_CANDIDATES = 80;
    private const string RAPTURE_READY_NEWS_URL = 'https://www.raptureready.com/category/rapture-ready-news/';
    private const string JINA_FETCH_PREFIX = 'https://r.jina.ai/http://';

    public function __construct(
        private readonly Client $httpClient,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Generate an AI news bulletin for a given station.
     *
     * Validates config (enabled + active-hours), fetches website/feed sources,
     * extracts headlines, builds a deterministic script, runs local Piper TTS,
     * converts WAV→MP3 via ffmpeg, writes atomically to the Liquidsoap path,
     * and persists ai_news_last_generation_status/time/error.
     *
     * @return bool True if generation succeeded or was intentionally skipped.
     */
    public function generate(Station $station, bool $force = false): bool
    {
        $backendConfig = $station->backend_config;

        if (!$force && !$backendConfig->ai_news_enabled) {
            $this->logger->debug(
                sprintf('AI news disabled for station "%s".', $station->name)
            );
            return true;
        }

        if (!$force && !$this->isWithinActiveSchedule(
            $backendConfig->ai_news_active_hours,
            $backendConfig->ai_news_active_days,
            $station
        )) {
            $this->logger->debug(
                sprintf('Outside active AI news schedule for station "%s".', $station->name)
            );
            return true;
        }

        try {
            $maxHeadlines = max(1, min(self::MAX_STORY_COUNT, $backendConfig->ai_news_story_count));
            $startTime = microtime(true);
            $sourceUrls = $this->parseSourceUrls($backendConfig->ai_news_source_urls);
            if ([] === $sourceUrls) {
                $this->persistStatus($station, 'error', 'No source URLs configured.', null);
                throw new RuntimeException('No source URLs configured.');
            }

            $fetchResults = $this->fetchHeadlines($sourceUrls, $maxHeadlines);
            $headlines = $fetchResults['headlines'];
            $sourceResults = $fetchResults['source_results'];
            if ([] === $headlines) {
                $message = 'No website or feed headlines could be fetched from configured sources.';
                $this->persistStatus($station, 'error', $message, [
                    'source_results' => $sourceResults,
                ]);
                throw new RuntimeException($message);
            }

            $intro = $backendConfig->ai_news_intro ?: 'Here are the latest headlines.';
            $script = $this->buildScript(
                $intro,
                $headlines,
                $backendConfig->ai_news_reporter_name,
                $backendConfig->ai_news_outro
            );

            $tempDir = $station->getRadioTempDir();
            $outputPath = $tempDir . '/' . self::OUTPUT_FILENAME;
            $this->generateAudio(
                $script,
                $backendConfig->ai_news_voice_model_path,
                $tempDir,
                $outputPath
            );

            $elapsedSeconds = round(microtime(true) - $startTime, 2);
            $metadata = [
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'story_count' => count($headlines),
                'source_urls' => $sourceUrls,
                'source_results' => $sourceResults,
                'elapsed_seconds' => $elapsedSeconds,
                'output_filename' => self::OUTPUT_FILENAME,
                'headline_preview' => $headlines,
            ];
            $this->persistStatus($station, 'completed', null, $metadata);

            $this->logger->info(
                sprintf('AI news bulletin generated for station "%s".', $station->name)
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('AI news generation failed for station "%s": %s', $station->name, $e->getMessage())
            );

            if ('error' !== $station->ai_news_last_generation_status) {
                $this->persistStatus($station, 'error', $e->getMessage(), null);
            }

            throw $e;
        }
    }

    /**
     * Check whether the current station-local time falls within the configured schedule.
     *
     * Hours formats: "HH:MM-HH:MM" (e.g. "06:00-22:00", UI default) or "H-H" (e.g. "6-22", legacy).
     * Days use ISO weekdays 1=Mon .. 7=Sun. Empty days means every day.
     * Supports overnight hour ranges. Null/empty hours means always active.
     */
    private function isWithinActiveSchedule(?string $activeHours, array $activeDays, Station $station): bool
    {
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $activeDays = $this->normalizeActiveDays($activeDays);

        if ([] !== $activeDays && !in_array((int) $now->format('N'), $activeDays, true)) {
            return false;
        }

        return $this->isWithinActiveHours($activeHours, $now);
    }

    /**
     * Check whether the current station-local time falls within the configured window.
     *
     * Formats: "HH:MM-HH:MM" (e.g. "06:00-22:00", UI default) or "H-H" (e.g. "6-22", legacy).
     * Supports overnight ranges. Null/empty means always active.
     */
    private function isWithinActiveHours(?string $activeHours, DateTimeImmutable $now): bool
    {
        if (null === $activeHours || '' === trim($activeHours)) {
            return true;
        }

        $activeHours = trim($activeHours);
        $currentHour = (int) $now->format('G');
        $currentMinute = (int) $now->format('i');

        // HH:MM-HH:MM format (preferred, UI default)
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $activeHours, $matches)) {
            $startMinutes = ((int) $matches[1]) * 60 + (int) $matches[2];
            $endMinutes   = ((int) $matches[3]) * 60 + (int) $matches[4];
            $nowMinutes   = $currentHour * 60 + $currentMinute;

            if ($startMinutes <= $endMinutes) {
                return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
            }
            return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $start = (int) $matches[1];
            $end   = (int) $matches[2];

            if ($start <= $end) {
                return $currentHour >= $start && $currentHour < $end;
            }
            return $currentHour >= $start || $currentHour < $end;
        }

        return true;
    }

    /** @return int[] */
    private function normalizeActiveDays(array $activeDays): array
    {
        $normalizedDays = array_map(
            static fn(mixed $day): int => (int) $day,
            $activeDays
        );
        $normalizedDays = array_values(array_unique(array_filter(
            $normalizedDays,
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($normalizedDays);

        return $normalizedDays;
    }

    /**
     * @return list<string>
     */
    private function parseSourceUrls(string $sourceUrls): array
    {
        return array_values(
            array_filter(
                array_map('trim', explode("\n", $sourceUrls)),
                static fn (string $url): bool => '' !== $url
            )
        );
    }

    /**
     * @param list<string> $urls
     * @return array{
     *   headlines: list<array{title: string, description: string, source_url: string, source_type?: string}>,
     *   source_results: list<array{url: string, status: string, message: string, headline_count: int, source_type?: string}>
     * }
     */
    private function fetchHeadlines(array $urls, int $maxHeadlines): array
    {
        $headlines = [];
        $sourceResults = [];
        $sourceCount = count($urls);
        $baseHeadlineCount = intdiv($maxHeadlines, $sourceCount);
        $remainderHeadlineCount = $maxHeadlines % $sourceCount;

        foreach ($urls as $index => $url) {
            $sourceHeadlineLimit = $baseHeadlineCount + ($index < $remainderHeadlineCount ? 1 : 0);
            if (0 === $sourceHeadlineLimit) {
                $sourceResults[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'No headline slot allocated for this source.',
                    'headline_count' => 0,
                    'source_type' => 'unknown',
                ];
                continue;
            }

            try {
                $result = $this->fetchAndParseUrl($url, $sourceHeadlineLimit);
                $items = $result['headlines'];
                $headlineCount = count($items);
                $sourceType = $result['source_type'];

                foreach ($items as $item) {
                    $headlines[] = [
                        ...$item,
                        'source_url' => $url,
                        'source_type' => $sourceType,
                    ];
                }

                $sourceResults[] = [
                    'url' => $url,
                    'status' => $headlineCount > 0 ? 'ok' : 'empty',
                    'message' => $headlineCount > 0
                        ? sprintf('Fetched %d headline(s) via %s.', $headlineCount, $sourceType)
                        : sprintf('%s parsing completed but returned no usable headlines.', ucfirst($sourceType)),
                    'headline_count' => $headlineCount,
                    'source_type' => $sourceType,
                ];
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Source "%s" skipped: %s', $url, $e->getMessage())
                );
                $sourceResults[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => $e->getMessage(),
                    'headline_count' => 0,
                    'source_type' => 'unknown',
                ];
            }
        }

        return [
            'headlines' => array_slice($headlines, 0, $maxHeadlines),
            'source_results' => $sourceResults,
        ];
    }

    /**
     * @return array{
     *   headlines: list<array{title: string, description: string}>,
     *   source_type: string
     * }
     */
    private function fetchAndParseUrl(string $url, int $maxHeadlines): array
    {
        $scraperTargetUrl = $this->getWebsiteScraperTargetUrl($url);
        $requestUrl = $scraperTargetUrl ?? $url;
        $isSupportedWebsite = null !== $scraperTargetUrl;

        try {
            $response = $this->fetchUrl($requestUrl);
            $body = (string) $response->getBody();
            $contentType = strtolower($response->getHeaderLine('Content-Type'));
        } catch (Throwable $e) {
            if ($isSupportedWebsite && $this->shouldUseJinaFallback($requestUrl, $e)) {
                $body = $this->fetchJinaMirror($requestUrl);
                $contentType = 'text/markdown';
            } else {
                throw $e;
            }
        }

        if ($isSupportedWebsite) {
            $websiteHeadlines = $this->extractSupportedWebsiteHeadlines($body, $url, $maxHeadlines);
            if ([] !== $websiteHeadlines) {
                return [
                    'headlines' => $websiteHeadlines,
                    'source_type' => 'website',
                ];
            }
        }

        $feedHeadlines = $this->extractFeedHeadlines($body, $maxHeadlines);
        if ([] !== $feedHeadlines) {
            return [
                'headlines' => $feedHeadlines,
                'source_type' => 'feed',
            ];
        }

        if ($isSupportedWebsite) {
            throw new RuntimeException('Supported website scraper found no usable headlines for this source.');
        }

        if ($this->isLikelyHtmlDocument($body, $contentType)) {
            throw new RuntimeException('No website scraper is available for this source URL. Try an RSS/Atom feed instead.');
        }

        throw new RuntimeException('No usable headlines could be extracted from the source URL.');
    }

    private function fetchUrl(string $url): ResponseInterface
    {
        return $this->httpClient->get($url, [
            RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
            ],
            RequestOptions::HEADERS => [
                'User-Agent' => 'AzuraCast/1.0 (AI News Generator)',
            ],
        ]);
    }

    private function shouldUseJinaFallback(string $url, Throwable $e): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_ends_with($host, 'raptureready.com')) {
            return false;
        }

        return str_contains($e->getMessage(), 'Could not resolve host');
    }

    private function fetchJinaMirror(string $url): string
    {
        $jinaUrl = self::JINA_FETCH_PREFIX . $url;
        $response = $this->fetchUrl($jinaUrl);

        return (string) $response->getBody();
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractFeedHeadlines(string $body, int $maxHeadlines): array
    {
        $xml = @simplexml_load_string($body);
        if (false === $xml) {
            return [];
        }

        $items = RssAtomFeedItems::fromParsedXml($xml);

        $headlines = [];
        foreach ($items as $item) {
            $title = $this->extractTextField($item, 'title');
            if ('' === $title) {
                continue;
            }

            $headlines[] = [
                'title' => $title,
                'description' => $this->extractTextField($item, 'description'),
            ];

            if (count($headlines) >= $maxHeadlines) {
                break;
            }
        }

        return $headlines;
    }

    private function getWebsiteScraperTargetUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ('' === $host) {
            return null;
        }

        if (str_ends_with($host, 'worthynews.com')) {
            return 'https://worthynews.com/';
        }

        if (str_ends_with($host, 'raptureready.com')) {
            return self::RAPTURE_READY_NEWS_URL;
        }

        return null;
    }

    private function isLikelyHtmlDocument(string $body, string $contentType): bool
    {
        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        if (str_contains($contentType, 'xml') || str_contains($contentType, 'rss') || str_contains($contentType, 'atom')) {
            return false;
        }

        return (bool) preg_match('/<(html|body|article|main)\b/i', $body);
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractSupportedWebsiteHeadlines(string $body, string $sourceUrl, int $maxHeadlines): array
    {
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        if (str_ends_with($host, 'worthynews.com')) {
            return $this->extractWorthyNewsHeadlines($body, $maxHeadlines);
        }

        if (str_ends_with($host, 'raptureready.com')) {
            return $this->extractRaptureReadyHeadlines($body, $maxHeadlines);
        }

        return [];
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractArticleContentByUrl(string $articleUrl): string
    {
        try {
            $body = (string) $this->fetchUrl($articleUrl)->getBody();
        } catch (Throwable) {
            return '';
        }

        if (!preg_match('/<(html|body|article|main|p)\b/i', $body)) {
            return '';
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $paragraphQueries = [
            '//article//p[normalize-space()]',
            '//main//p[normalize-space()]',
            '//div[contains(@class, "entry-content")]//p[normalize-space()]',
            '//div[contains(@class, "post-content")]//p[normalize-space()]',
        ];

        foreach ($paragraphQueries as $query) {
            $paragraphs = $xpath->query($query);
            if (false === $paragraphs || 0 === $paragraphs->length) {
                continue;
            }

            $parts = [];
            foreach ($paragraphs as $paragraph) {
                $text = $this->normalizeHtmlText($paragraph->textContent ?? '');
                if (!$this->isUsableSummary($text)) {
                    continue;
                }

                $parts[] = $text;
                if (count($parts) >= 2) {
                    break;
                }
            }

            if ([] !== $parts) {
                return implode(' ', $parts);
            }
        }

        return '';
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractWorthyNewsHeadlines(string $body, int $maxHeadlines): array
    {
        return $this->extractHeadlinesFromHtml(
            $body,
            [
                '//article',
                '//main//a[contains(@href, "worthynews.com/")][normalize-space()]',
                '//h2/a[contains(@href, "worthynews.com/")][normalize-space()]',
                '//h3/a[contains(@href, "worthynews.com/")][normalize-space()]',
            ],
            $maxHeadlines,
            static function (string $href): bool {
                return str_contains($href, 'worthynews.com/')
                    && preg_match('#worthynews\.com/\d+#', $href)
                    && !str_contains($href, '/category/')
                    && !str_contains($href, '/tag/');
            },
            'worthynews.com'
        );
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractRaptureReadyHeadlines(string $body, int $maxHeadlines): array
    {
        if (str_starts_with(ltrim($body), 'Title: Rapture Ready End Times News Archives')) {
            return $this->extractRaptureReadyHeadlinesFromMarkdown($body, $maxHeadlines);
        }

        return $this->extractHeadlinesFromHtml(
            $body,
            [
                '//article',
                '//main//a[contains(@href, "raptureready.com/")][normalize-space()]',
                '//h2/a[contains(@href, "raptureready.com/")][normalize-space()]',
                '//h3/a[contains(@href, "raptureready.com/")][normalize-space()]',
            ],
            $maxHeadlines,
            static function (string $href): bool {
                return str_contains($href, 'raptureready.com/')
                    && preg_match('#/20\d{2}/\d{2}/\d{2}/#', $href)
                    && !str_contains($href, '/category/')
                    && !str_contains($href, '/wp-content/');
            },
            'raptureready.com'
        );
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function extractRaptureReadyHeadlinesFromMarkdown(string $body, int $maxHeadlines): array
    {
        preg_match_all('/^\[(.+?)\]\((https?:\/\/[^)]+)\)\n\n\s*(.+)$/m', $body, $matches, PREG_SET_ORDER);

        $headlines = [];
        foreach ($matches as $match) {
            $title = $this->normalizeHtmlText($match[1] ?? '');
            $href = trim($match[2] ?? '');
            $summary = $this->normalizeHtmlText($match[3] ?? '');

            if (!$this->isUsableHeadline($title)) {
                continue;
            }

            if (!preg_match('#^https?://#', $href) || !$this->isUsableSummary($summary)) {
                $summary = '';
            }

            $headlines[] = [
                'title' => $title,
                'description' => $summary,
            ];

            if (count($headlines) >= $maxHeadlines) {
                break;
            }
        }

        return $headlines;
    }

    /**
     * @param list<string> $candidateQueries
     * @param callable(string): bool $hrefFilter
     * @return list<array{title: string, description: string}>
     */
    private function extractHeadlinesFromHtml(
        string $body,
        array $candidateQueries,
        int $maxHeadlines,
        callable $hrefFilter,
        string $fallbackDomain
    ): array {
        if (!preg_match('/<(html|body|article|main|h1|h2|h3|a)\b/i', $body)) {
            return [];
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $headlines = [];
        $seenTitles = [];

        foreach ($candidateQueries as $query) {
            $candidates = $xpath->query($query);
            if (false === $candidates) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (!$candidate instanceof DOMElement) {
                    continue;
                }

                $headline = $this->buildHeadlineFromWebsiteNode($candidate, $xpath, $hrefFilter, $fallbackDomain);
                if (null === $headline) {
                    continue;
                }

                $dedupeKey = mb_strtolower($headline['title']);
                if (isset($seenTitles[$dedupeKey])) {
                    continue;
                }

                $seenTitles[$dedupeKey] = true;
                $headlines[] = $headline;

                if (count($headlines) >= $maxHeadlines || count($seenTitles) >= self::MAX_HTML_CANDIDATES) {
                    return $headlines;
                }
            }
        }

        return $headlines;
    }

    /**
     * @param callable(string): bool $hrefFilter
     * @return array{title: string, description: string}|null
     */
    private function buildHeadlineFromWebsiteNode(
        DOMElement $node,
        DOMXPath $xpath,
        callable $hrefFilter,
        string $fallbackDomain
    ): ?array {
        $titleNode = $this->resolveHeadlineTitleNode($node, $xpath);
        if (null === $titleNode) {
            return null;
        }

        $href = $titleNode instanceof DOMElement ? (string) $titleNode->getAttribute('href') : '';
        if ('' === $href) {
            $linkNode = $xpath->query('.//a[@href][normalize-space()]', $node)?->item(0);
            if ($linkNode instanceof DOMElement) {
                $href = (string) $linkNode->getAttribute('href');
            }
        }

        if ('' !== $href && !$hrefFilter($href)) {
            return null;
        }

        $title = $this->normalizeHtmlText($titleNode->textContent ?? '');
        if (!$this->isUsableHeadline($title)) {
            return null;
        }

        $summary = '';
        $summaryNode = $xpath->query('.//p[normalize-space()]', $node)?->item(0);
        if ($summaryNode instanceof DOMNode) {
            $summary = $this->normalizeHtmlText($summaryNode->textContent ?? '');
        }

        if ($summary === '' && '' !== $href) {
            $summary = $this->extractArticleContentByUrl($href);
        }

        if ($summary !== '' && (!$this->isUsableSummary($summary) || $summary === $title)) {
            $summary = '';
        }

        return [
            'title' => $title,
            'description' => $summary,
        ];
    }

    private function resolveHeadlineTitleNode(DOMElement $node, DOMXPath $xpath): ?DOMNode
    {
        if (in_array(strtolower($node->tagName), ['h1', 'h2', 'h3', 'a'], true)) {
            return $node;
        }

        $headlineNode = $xpath->query('.//*[self::h1 or self::h2 or self::h3][normalize-space()]', $node)?->item(0);
        if ($headlineNode instanceof DOMNode) {
            return $headlineNode;
        }

        $linkNode = $xpath->query('.//a[normalize-space()]', $node)?->item(0);
        return $linkNode instanceof DOMNode ? $linkNode : null;
    }

    private function normalizeHtmlText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    private function isUsableHeadline(string $title): bool
    {
        if ('' === $title) {
            return false;
        }

        if (mb_strlen($title) < 12 || mb_strlen($title) > 220) {
            return false;
        }

        $lowerTitle = mb_strtolower($title);
        $blockedFragments = ['subscribe', 'newsletter', 'cookie', 'privacy policy', 'sign in', 'all rights reserved'];
        foreach ($blockedFragments as $blockedFragment) {
            if (str_contains($lowerTitle, $blockedFragment)) {
                return false;
            }
        }

        return true;
    }

    private function isUsableSummary(string $summary): bool
    {
        if ('' === $summary) {
            return false;
        }

        if (mb_strlen($summary) < 40) {
            return false;
        }

        $lowerSummary = mb_strtolower($summary);
        $blockedFragments = ['read more', 'click here', 'continue reading', 'headline scraped from'];
        foreach ($blockedFragments as $blockedFragment) {
            if (str_contains($lowerSummary, $blockedFragment)) {
                return false;
            }
        }

        return true;
    }

    private function extractTextField(SimpleXMLElement $item, string $field): string
    {
        if (!isset($item->{$field})) {
            return '';
        }

        $text = trim(strip_tags((string) $item->{$field}));
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param list<array{title: string, description: string, source_url?: string}> $headlines
     */
    private function buildScript(
        string $intro,
        array $headlines,
        ?string $reporterName = null,
        ?string $outro = null
    ): string {
        $lines = [];

        $reporterName = null !== $reporterName ? trim($reporterName) : null;
        if (!empty($reporterName)) {
            $lines[] = sprintf('This is %s.', $reporterName);
            $this->appendPauseBreak($lines, 2);
        }

        $lines[] = $intro;
        $this->appendPauseBreak($lines, 2);

        $headlineCount = count($headlines);
        foreach ($headlines as $index => $item) {
            $line = rtrim($item['title'], ".!? ") . '.';

            if ('' !== $item['description']) {
                $line .= ' ' . $this->truncateAtSentenceEnd($item['description']);
            }

            $lines[] = $line;

            if ($index < ($headlineCount - 1)) {
                $this->appendPauseBreak($lines, 1);
            }
        }

        $outro = null !== $outro ? trim($outro) : null;
        if (!empty($outro)) {
            $this->appendPauseBreak($lines, 2);
            $lines[] = $outro;
        }

        return implode("\n", $lines);
    }

    /**
     * Add paragraph separators to encourage short natural pauses in TTS output.
     */
    private function appendPauseBreak(array &$lines, int $extraBlankLines = 1): void
    {
        $blankLines = max(1, $extraBlankLines);
        for ($i = 0; $i < $blankLines; $i++) {
            $lines[] = '';
        }
    }

    private function truncateAtSentenceEnd(string $description): string
    {
        $description = trim($description);
        if ('' === $description) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $sentences || [] === $sentences) {
            return $description;
        }

        $softLimit = 240;
        $selected = '';

        foreach ($sentences as $index => $sentence) {
            $candidate = '' === $selected
                ? trim($sentence)
                : $selected . ' ' . trim($sentence);

            if (mb_strlen($candidate) > $softLimit && '' !== $selected) {
                break;
            }

            $selected = $candidate;

            if ($index >= 1 || mb_strlen($selected) >= $softLimit) {
                break;
            }
        }

        return '' !== $selected ? $selected : $description;
    }

    private function generateAudio(
        string $script,
        ?string $voiceModelPath,
        string $tempDir,
        string $outputPath
    ): void {
        $modelPath = $voiceModelPath ?: self::DEFAULT_VOICE_MODEL;

        $scriptFile = $tempDir . '/news_script.txt';
        if (false === file_put_contents($scriptFile, $script)) {
            throw new RuntimeException('Failed to write TTS script file.');
        }

        $wavFile = $tempDir . '/news_bulletin.wav';
        $tmpMp3 = $tempDir . '/news_bulletin_tmp.mp3';

        try {
            $piper = new Process([
                self::PIPER_BIN,
                '--model', $modelPath,
                '--output_file', $wavFile,
            ]);
            $piper->setInput($script);
            $piper->setTimeout(120);
            $piper->mustRun();

            $ffmpeg = new Process([
                self::FFMPEG_BIN,
                '-y',
                '-i', $wavFile,
                '-af', 'adelay=2000:all=true,apad=pad_dur=2',
                '-c:a', 'libmp3lame',
                '-b:a', '128k',
                $tmpMp3,
            ]);
            $ffmpeg->setTimeout(60);
            $ffmpeg->mustRun();

            if (!@rename($tmpMp3, $outputPath)) {
                throw new RuntimeException(
                    sprintf('Failed to move bulletin to "%s".', $outputPath)
                );
            }
        } finally {
            @unlink($scriptFile);
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    private function persistStatus(Station $station, string $status, ?string $error, ?array $metadata = null): void
    {
        $station->ai_news_last_generation_status = $status;
        $station->ai_news_last_generation_time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $station->ai_news_last_error = $error;
        if (null !== $metadata) {
            $station->ai_news_latest_bulletin = $metadata;
        }

        $this->em->persist($station);
        $this->em->flush();
    }
}
