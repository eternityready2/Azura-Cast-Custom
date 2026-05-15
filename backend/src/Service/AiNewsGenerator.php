<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Podcast\RssAtomFeedItems;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
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
        'https://worthynews.com/feed/',
        'https://www.raptureready.com/category/rapture-ready-news/feed/',
        'https://feeds.bbci.co.uk/news/world/rss.xml',
    ];
    private const int MAX_HEADLINES = 10;
    private const int MAX_STORY_COUNT = 25;
    private const int HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly Client $httpClient,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Generate an AI news bulletin for a given station.
     *
     * Validates config (enabled + active-hours), fetches RSS/Atom sources,
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

        if (!$force && !$this->isWithinActiveHours($backendConfig->ai_news_active_hours, $station)) {
            $this->logger->debug(
                sprintf('Outside active hours window for station "%s".', $station->name)
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
                $message = 'No RSS/Atom headlines could be fetched from configured sources.';
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

            if ('error' !== $station->backend_config->ai_news_last_generation_status) {
                $this->persistStatus($station, 'error', $e->getMessage(), null);
            }

            throw $e;
        }
    }

    /**
     * Check whether the current station-local time falls within the configured window.
     *
     * Formats: "HH:MM-HH:MM" (e.g. "06:00-22:00", UI default) or "H-H" (e.g. "6-22", legacy).
     * Supports overnight ranges. Null/empty means always active.
     */
    private function isWithinActiveHours(?string $activeHours, Station $station): bool
    {
        if (null === $activeHours || '' === trim($activeHours)) {
            return true;
        }

        $activeHours = trim($activeHours);
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
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
     *   headlines: list<array{title: string, description: string, source_url: string}>,
     *   source_results: list<array{url: string, status: string, message: string, headline_count: int}>
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
                ];
                continue;
            }

            try {
                $items = $this->fetchAndParseUrl($url, $sourceHeadlineLimit);
                $headlineCount = count($items);

                foreach ($items as $item) {
                    $headlines[] = [
                        ...$item,
                        'source_url' => $url,
                    ];
                }

                $sourceResults[] = [
                    'url' => $url,
                    'status' => $headlineCount > 0 ? 'ok' : 'empty',
                    'message' => $headlineCount > 0
                        ? sprintf('Fetched %d headline(s).', $headlineCount)
                        : 'Feed parsed successfully but returned no usable headlines.',
                    'headline_count' => $headlineCount,
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
                ];
            }
        }

        return [
            'headlines' => array_slice($headlines, 0, $maxHeadlines),
            'source_results' => $sourceResults,
        ];
    }

    /**
     * @return list<array{title: string, description: string}>
     */
    private function fetchAndParseUrl(string $url, int $maxHeadlines): array
    {
        $response = $this->httpClient->get($url, [
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

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if (false === $xml) {
            throw new RuntimeException('Failed to parse XML from response.');
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
            $lines[] = '';
        }

        $lines[] = $intro;
        $lines[] = '';

        foreach ($headlines as $i => $item) {
            $num = $i + 1;
            $line = sprintf('%d. %s.', $num, $item['title']);

            if ('' !== $item['description']) {
                $line .= ' ' . $this->truncateAtSentenceEnd($item['description']);
            }

            $lines[] = $line;
        }

        $outro = null !== $outro ? trim($outro) : null;
        if (!empty($outro)) {
            $lines[] = '';
            $lines[] = $outro;
        }

        return implode("\n", $lines);
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
        $backendConfig = $station->backend_config;
        $backendConfig->ai_news_last_generation_status = $status;
        $backendConfig->ai_news_last_generation_time = gmdate('Y-m-d\TH:i:s\Z');
        $backendConfig->ai_news_last_error = $error;
        if (null !== $metadata) {
            $backendConfig->ai_news_latest_bulletin = $metadata;
        }
        $station->backend_config = $backendConfig;

        $this->em->persist($station);
        $this->em->flush();
    }
}
