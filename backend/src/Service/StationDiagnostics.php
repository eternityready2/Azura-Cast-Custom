<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;
use DateTimeImmutable;
use Throwable;

final class StationDiagnostics
{
    private const string LOG_FILE = 'custom_diagnostics.log';
    private const int MAX_LOG_BYTES = 5 * 1024 * 1024;
    private const int RETAIN_LOG_BYTES = 2 * 1024 * 1024;

    public function getLogPath(Station $station): string
    {
        return $station->getRadioConfigDir() . '/' . self::LOG_FILE;
    }

    public function ensureLogFile(Station $station): string
    {
        $path = $this->getLogPath($station);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }

        if (is_dir($directory) && !is_file($path)) {
            @file_put_contents($path, '', LOCK_EX);
        }

        return $path;
    }

    /**
     * @return list<array{
     *     timestamp: int,
     *     timestamp_iso: string,
     *     level: string,
     *     feature: string,
     *     message: string,
     *     context: array<string, mixed>
     * }>
     */
    public function getRecentEvents(Station $station, int $windowHours = 24, int $limit = 1500): array
    {
        $path = $this->ensureLogFile($station);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || [] === $lines) {
            return [];
        }

        $windowHours = max(1, min(2160, $windowHours));
        $limit = max(1, min(20000, $limit));
        $minimumTimestamp = time() - ($windowHours * 3600);
        $events = [];

        foreach (array_reverse($lines) as $line) {
            if (count($events) >= $limit) {
                break;
            }

            $event = $this->parseLine($line);
            if (null === $event) {
                continue;
            }

            if ($event['timestamp'] < $minimumTimestamp) {
                continue;
            }

            $events[] = $event;
        }

        return array_reverse($events);
    }

    /** @param array<string, scalar|null> $context */
    public function info(Station $station, string $feature, string $message, array $context = []): void
    {
        $this->write($station, 'INFO', $feature, $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function warning(Station $station, string $feature, string $message, array $context = []): void
    {
        $this->write($station, 'WARNING', $feature, $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function error(Station $station, string $feature, string $message, array $context = []): void
    {
        $this->write($station, 'ERROR', $feature, $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(
        Station $station,
        string $level,
        string $feature,
        string $message,
        array $context,
    ): void {
        try {
            $path = $this->ensureLogFile($station);
            if (!is_file($path)) {
                return;
            }

            $this->trimIfNeeded($path);

            $timestamp = (new DateTimeImmutable('now', $station->getTimezoneObject()))->format(DATE_ATOM);
            $safeFeature = strtoupper(preg_replace('/[^A-Za-z0-9._ -]+/', '', trim($feature)) ?: 'SYSTEM');
            $safeMessage = preg_replace('/\s+/', ' ', trim($message)) ?: 'Diagnostic event';

            $line = sprintf('[%s] [%s] [%s] %s', $timestamp, $level, $safeFeature, $safeMessage);

            if ([] !== $context) {
                $encodedContext = json_encode(
                    $context,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );

                if (false !== $encodedContext) {
                    $line .= ' ' . $encodedContext;
                }
            }

            file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }

    /**
     * @return array{
     *     timestamp: int,
     *     timestamp_iso: string,
     *     level: string,
     *     feature: string,
     *     message: string,
     *     context: array<string, mixed>
     * }|null
     */
    private function parseLine(string $line): ?array
    {
        if (!preg_match('/^\[([^]]+)] \[(INFO|WARNING|ERROR)] \[([^]]+)] (.+)$/', $line, $matches)) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($matches[1]);
        } catch (Throwable) {
            return null;
        }

        $payload = $matches[4];
        $message = $payload;
        $context = [];

        $jsonStart = strrpos($payload, ' {');
        if (false !== $jsonStart) {
            $decoded = json_decode(substr($payload, $jsonStart + 1), true);
            if (is_array($decoded)) {
                $context = $decoded;
                $message = substr($payload, 0, $jsonStart);
            }
        }

        return [
            'timestamp' => $timestamp->getTimestamp(),
            'timestamp_iso' => $timestamp->format(DATE_ATOM),
            'level' => $matches[2],
            'feature' => trim($matches[3]),
            'message' => trim($message),
            'context' => $context,
        ];
    }

    private function trimIfNeeded(string $path): void
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (false === $size || $size <= self::MAX_LOG_BYTES) {
            return;
        }

        $stream = fopen($path, 'rb');
        if (false === $stream) {
            return;
        }

        fseek($stream, -min(self::RETAIN_LOG_BYTES, $size), SEEK_END);
        $tail = stream_get_contents($stream) ?: '';
        fclose($stream);

        $firstLineBreak = strpos($tail, "\n");
        if (false !== $firstLineBreak) {
            $tail = substr($tail, $firstLineBreak + 1);
        }

        $marker = sprintf(
            '[%s] [INFO] [SYSTEM] Older custom diagnostics were trimmed to keep the log bounded.%s',
            gmdate(DATE_ATOM),
            PHP_EOL
        );

        file_put_contents($path, $marker . $tail, LOCK_EX);
    }
}
