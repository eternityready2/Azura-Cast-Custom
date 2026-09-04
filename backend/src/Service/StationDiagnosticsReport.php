<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class StationDiagnosticsReport
{
    public function __construct(
        private StationDiagnosticsFinalizer $dashboard,
    ) {
    }

    public function render(
        Station $station,
        int $startTimestamp,
        int $endTimestamp,
        ?string $feature = null,
    ): string {
        try {
            $snapshot = $this->dashboard->getSnapshot(
                $station,
                $startTimestamp,
                $endTimestamp,
                $feature,
            );
        } catch (Throwable $e) {
            return $this->renderFailure(
                $station,
                $startTimestamp,
                $endTimestamp,
                $feature,
                $e,
            );
        }

        $timezone = $station->getTimezoneObject();
        $generatedAt = (int)($snapshot['generated_at'] ?? time());
        $lines = [
            'AZURACAST STATION DIAGNOSTICS REPORT',
            str_repeat('=', 72),
            'Station: ' . $station->name,
            'Generated: ' . $this->formatTimestamp($generatedAt, $timezone),
            'Range: ' . $this->formatTimestamp($startTimestamp, $timezone)
                . ' through ' . $this->formatTimestamp($endTimestamp, $timezone),
            'Feature filter: ' . ($feature ?: 'All feature areas'),
            '',
            'OPERATIONS SUMMARY',
            str_repeat('-', 72),
            'Overall status: ' . strtoupper((string)($snapshot['overall_status'] ?? 'unknown')),
            'Health score: ' . (int)($snapshot['health_score'] ?? 0) . '/100',
        ];

        $counts = (array)($snapshot['counts'] ?? []);
        $lines[] = sprintf(
            'Signals: %d success / %d warning / %d failure',
            (int)($counts['successes'] ?? 0),
            (int)($counts['warning_signals'] ?? 0),
            (int)($counts['failures'] ?? 0),
        );
        $lines[] = sprintf(
            'Feature health: %d healthy / %d monitoring / %d warning / %d critical / %d inactive',
            (int)($counts['healthy'] ?? 0),
            (int)($counts['monitoring'] ?? 0),
            (int)($counts['warning'] ?? 0),
            (int)($counts['critical'] ?? 0),
            (int)($counts['inactive'] ?? 0),
        );
        $lines[] = sprintf(
            'Runtime services: %d/%d online',
            (int)($counts['services_running'] ?? 0),
            (int)($counts['services_total'] ?? 0),
        );
        $lines[] = '';
        $lines[] = 'FEATURE DIAGNOSTICS';
        $lines[] = str_repeat('-', 72);

        foreach ((array)($snapshot['features'] ?? []) as $featureRow) {
            if (!is_array($featureRow)) {
                continue;
            }

            $stats = (array)($featureRow['stats'] ?? []);
            $lines[] = sprintf(
                '[%s] %s — %s',
                strtoupper((string)($featureRow['status'] ?? 'unknown')),
                (string)($featureRow['label'] ?? 'Feature'),
                (string)($featureRow['metric'] ?? ''),
            );
            $lines[] = '  ' . (string)($featureRow['headline'] ?? '');
            $detail = trim((string)($featureRow['detail'] ?? ''));
            if ('' !== $detail) {
                $lines[] = '  ' . $detail;
            }
            $confidence = trim((string)($featureRow['confidence_note'] ?? ''));
            if ('' !== $confidence) {
                $lines[] = '  Confidence: ' . $confidence;
            }
            $lines[] = sprintf(
                '  Results: %d success (%d execution + %d checks), %d warning, %d failure%s',
                (int)($stats['successes'] ?? 0),
                (int)($stats['successful_executions'] ?? 0),
                (int)($stats['checks_passed'] ?? 0),
                (int)($stats['warnings'] ?? 0),
                (int)($stats['failures'] ?? 0),
                null === ($stats['success_rate'] ?? null)
                    ? ''
                    : sprintf(' — %.1f%% success', (float)$stats['success_rate']),
            );

            $details = (array)($featureRow['details'] ?? []);
            if ([] !== $details) {
                $lines[] = '  Current state:';
                foreach ($details as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '    - %s: %s',
                        (string)($row['label'] ?? 'Check'),
                        $this->scalarToString($row['value'] ?? ''),
                    );
                }
            }

            $problems = (array)($featureRow['top_problems'] ?? []);
            if ([] !== $problems) {
                $lines[] = '  Top problems:';
                foreach (array_slice($problems, 0, 4) as $problem) {
                    if (!is_array($problem)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '    - [%s] %s — %s (%s)',
                        strtoupper((string)($problem['severity'] ?? 'warning')),
                        (string)($problem['title'] ?? 'Issue'),
                        (string)($problem['detail'] ?? ''),
                        $this->formatTimestamp((int)($problem['timestamp'] ?? $generatedAt), $timezone),
                    );
                }
            } else {
                $lines[] = '  Top problems: none detected in this range.';
            }

            $lines[] = '';
        }

        $services = (array)($snapshot['services'] ?? []);
        if ([] !== $services) {
            $lines[] = 'RUNTIME SERVICES';
            $lines[] = str_repeat('-', 72);
            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }
                $lines[] = sprintf(
                    '[%s] %s (%s) — %s',
                    strtoupper((string)($service['status'] ?? 'unknown')),
                    (string)($service['name'] ?? 'Service'),
                    (string)($service['scope'] ?? 'system'),
                    (string)($service['problem'] ?? $service['description'] ?? ''),
                );
            }
            $lines[] = '';
        }

        $issues = (array)($snapshot['recent_issues'] ?? []);
        $lines[] = 'PRIORITY ISSUES';
        $lines[] = str_repeat('-', 72);
        if ([] === $issues) {
            $lines[] = 'No active warning or critical issues were detected in the selected range.';
        } else {
            foreach ($issues as $issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $lines[] = sprintf(
                    '[%s] %s / %s — %s',
                    strtoupper((string)($issue['severity'] ?? 'warning')),
                    (string)($issue['feature'] ?? 'Feature'),
                    (string)($issue['title'] ?? 'Issue'),
                    (string)($issue['detail'] ?? ''),
                );
                $lines[] = '  ' . $this->formatTimestamp(
                    (int)($issue['timestamp'] ?? $generatedAt),
                    $timezone,
                ) . ' · source=' . (string)($issue['source'] ?? 'diagnostics');
            }
        }

        $lines[] = '';
        $lines[] = 'NOTES';
        $lines[] = str_repeat('-', 72);
        $lines[] = 'This report combines live service state, current station configuration,';
        $lines[] = 'database-backed execution history, custom diagnostic events, and recent';
        $lines[] = 'runtime log failures. An empty individual error/access log means that file';
        $lines[] = 'has no entries at the moment; it does not make this diagnostics report empty.';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function renderFailure(
        Station $station,
        int $startTimestamp,
        int $endTimestamp,
        ?string $feature,
        Throwable $error,
    ): string {
        $timezone = $station->getTimezoneObject();
        $message = str_replace($station->getFilteredPasswords(), '(PASSWORD)', $error->getMessage());

        return implode(PHP_EOL, [
            'AZURACAST STATION DIAGNOSTICS REPORT',
            str_repeat('=', 72),
            'Station: ' . $station->name,
            'Generated: ' . $this->formatTimestamp(time(), $timezone),
            'Range: ' . $this->formatTimestamp($startTimestamp, $timezone)
                . ' through ' . $this->formatTimestamp($endTimestamp, $timezone),
            'Feature filter: ' . ($feature ?: 'All feature areas'),
            '',
            'DIAGNOSTICS ENGINE FAILURE',
            str_repeat('-', 72),
            'The operational snapshot could not be fully generated.',
            'Error: ' . $message,
            '',
            'The raw station/service logs remain available on the Logs page.',
        ]) . PHP_EOL;
    }

    private function formatTimestamp(int $timestamp, DateTimeZone $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s T');
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (null === $value) {
            return '—';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
    }
}
