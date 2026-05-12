<?php

declare(strict_types=1);

namespace App\Service;

use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Podcast\RssAtomFeedItems;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use SimpleXMLElement;
use Symfony\Component\Process\Process;
use Throwable;

final class AiNewsGenerator
{
    use LoggerAwareTrait;

    private const string PIPER_BIN = 'piper';
    private const string FFMPEG_BIN = 'ffmpeg';
    private const string DEFAULT_VOICE_MODEL = '/usr/local/share/piper-voices/en/en_US/lessac/medium/en_US-lessac-medium.onnx';
    private const string OUTPUT_FILENAME = 'news_bulletin.mp3';
    private const int MAX_HEADLINES = 10;
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
            $sourceUrls = $this->parseSourceUrls($backendConfig->ai_news_source_urls);
            if ([] === $sourceUrls) {
                $this->persistStatus($station, 'error', 'No source URLs configured.');
                throw new \RuntimeException('No source URLs configured.');
            }

            $headlines = $this->fetchHeadlines($sourceUrls);
            if ([] === $headlines) {
                $this->persistStatus($station, 'error', 'No headlines could be fetched from any source.');
                throw new \RuntimeException('No headlines could be fetched from any source.');
            }

            $intro = $backendConfig->ai_news_intro ?: 'Here are the latest headlines.';
            $script = $this->buildScript($intro, $headlines);

            $tempDir = $station->getRadioTempDir();
            $outputPath = $tempDir . '/' . self::OUTPUT_FILENAME;
            $this->generateAudio(
                $script,
                $backendConfig->ai_news_voice_model_path,
                $tempDir,
                $outputPath
            );

            $this->persistStatus($station, 'completed', null);

            $this->logger->info(
                sprintf('AI news bulletin generated for station "%s".', $station->name)
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('AI news generation failed for station "%s": %s', $station->name, $e->getMessage())
            );

            if ('error' !== $station->backend_config->ai_news_last_generation_status) {
                $this->persistStatus($station, 'error', $e->getMessage());
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
        $now = new \DateTimeImmutable('now', $station->getTimezoneObject());
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
            // Overnight range
            return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
        }

        // Legacy H-H format (hour-only)
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $activeHours, $matches)) {
            $start = (int) $matches[1];
            $end   = (int) $matches[2];

            if ($start <= $end) {
                return $currentHour >= $start && $currentHour < $end;
            }
            // Overnight range
            return $currentHour >= $start || $currentHour < $end;
        }

        return true;
    }

    /**
     * Split newline-separated source URLs into a clean list.
     *
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
     * Fetch multiple RSS/Atom feeds, collecting up to MAX_HEADLINES total.
     *
     * Any source failure causes the entire bulletin to be skipped (all sources are required).
     *
     * @param list<string> $urls
     * @return list<array{title: string, description: string}>
     * @throws \RuntimeException if any configured source cannot be fetched.
     */
    private function fetchHeadlines(array $urls): array
    {
        $headlines = [];

        foreach ($urls as $url) {
            try {
                foreach ($this->fetchAndParseUrl($url) as $item) {
                    $headlines[] = $item;
                    if (count($headlines) >= self::MAX_HEADLINES) {
                        break 2;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Source "%s" failed: %s', $url, $e->getMessage())
                );
                throw new \RuntimeException(
                    sprintf('Failed to fetch source "%s": %s', $url, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        return $headlines;
    }

    /**
     * Fetch a single RSS/Atom URL and return parsed headline items.
     *
     * @return list<array{title: string, description: string}>
     */
    private function fetchAndParseUrl(string $url): array
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
            throw new \RuntimeException('Failed to parse XML from response.');
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

            if (count($headlines) >= self::MAX_HEADLINES) {
                break;
            }
        }

        return $headlines;
    }

    /**
     * Extract plain text from a SimpleXMLElement child, stripping tags and decoding entities.
     */
    private function extractTextField(SimpleXMLElement $item, string $field): string
    {
        if (!isset($item->{$field})) {
            return '';
        }

        $text = trim(strip_tags((string) $item->{$field}));
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Build a deterministic spoken script from intro and extracted headlines.
     *
     * @param list<array{title: string, description: string}> $headlines
     */
    private function buildScript(string $intro, array $headlines): string
    {
        $lines = [$intro, ''];

        foreach ($headlines as $i => $item) {
            $num = $i + 1;
            $line = sprintf('%d. %s.', $num, $item['title']);

            if ('' !== $item['description']) {
                $desc = mb_substr($item['description'], 0, 200);
                $line .= ' ' . $desc;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Run the TTS + conversion pipeline and atomically write the final MP3.
     *
     * Pipeline: script → Piper (WAV) → ffmpeg (MP3) → atomic rename
     */
    private function generateAudio(
        string $script,
        ?string $voiceModelPath,
        string $tempDir,
        string $outputPath
    ): void {
        $modelPath = $voiceModelPath ?: self::DEFAULT_VOICE_MODEL;

        $scriptFile = $tempDir . '/news_script.txt';
        if (false === file_put_contents($scriptFile, $script)) {
            throw new \RuntimeException('Failed to write TTS script file.');
        }

        $wavFile = $tempDir . '/news_bulletin.wav';
        $tmpMp3 = $tempDir . '/news_bulletin_tmp.mp3';

        try {
            // Step 1: Piper TTS → WAV
            $piper = new Process([
                self::PIPER_BIN,
                '--model', $modelPath,
                '--output_file', $wavFile,
            ]);
            $piper->setInput($script);
            $piper->setTimeout(120);
            $piper->mustRun();

            // Step 2: ffmpeg WAV → MP3
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

            // Step 3: Atomic rename to the path Liquidsoap expects.
            if (!@rename($tmpMp3, $outputPath)) {
                throw new \RuntimeException(
                    sprintf('Failed to move bulletin to "%s".', $outputPath)
                );
            }
        } finally {
            @unlink($scriptFile);
            @unlink($wavFile);
            @unlink($tmpMp3);
        }
    }

    /**
     * Persist generation status, timestamp, and optional error message to the station entity.
     */
    private function persistStatus(Station $station, string $status, ?string $error): void
    {
        $backendConfig = $station->backend_config;
        $backendConfig->ai_news_last_generation_status = $status;
        $backendConfig->ai_news_last_generation_time = gmdate('Y-m-d\TH:i:s\Z');
        $backendConfig->ai_news_last_error = $error;
        $station->backend_config = $backendConfig;

        $this->em->persist($station);
        $this->em->flush();
    }
}
