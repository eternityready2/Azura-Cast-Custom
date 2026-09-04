<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;

/**
 * Presentation layer for the station diagnostics snapshot.
 *
 * Keeps the collector focused on gathering evidence while this service applies
 * operator-facing confidence semantics (healthy vs monitoring), fixes derived
 * playout-control state, and normalizes totals used by the dashboard/report.
 */
final readonly class StationDiagnosticsDashboardView
{
    public function __construct(
        private StationDiagnosticsDashboard $dashboard,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(
        Station $station,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        ?string $featureFilter = null,
    ): array {
        $snapshot = $this->dashboard->getSnapshot(
            $station,
            $startTimestamp,
            $endTimestamp,
            $featureFilter,
        );

        $features = (array)($snapshot['features'] ?? []);
        foreach ($features as &$feature) {
            if (!is_array($feature)) {
                continue;
            }

            if ('playout-controls' === ($feature['key'] ?? null)) {
                $this->applyPlayoutControlState($station, $feature);
            }

            $this->applyConfidenceStatus($feature);
        }
        unset($feature);

        $snapshot['features'] = array_values($features);
        $snapshot['distribution'] = $this->buildDistribution($features);
        $snapshot['health_score'] = $this->calculateHealthScore($features);
        $snapshot['overall_status'] = $this->calculateOverallStatus(
            $features,
            (array)($snapshot['services'] ?? []),
        );

        $counts = (array)($snapshot['counts'] ?? []);
        foreach (['healthy', 'monitoring', 'warning', 'critical', 'inactive'] as $status) {
            $counts[$status] = (int)($snapshot['distribution'][$status] ?? 0);
        }
        $snapshot['counts'] = $counts;

        $services = (array)($snapshot['services'] ?? []);
        foreach ($services as &$service) {
            if (!is_array($service)) {
                continue;
            }
            $service['problem'] = 'critical' === ($service['status'] ?? null)
                ? $this->serviceProblem($service)
                : null;
        }
        unset($service);
        $snapshot['services'] = array_values($services);

        return $snapshot;
    }

    /** @param array<string, mixed> $feature */
    private function applyPlayoutControlState(Station $station, array &$feature): void
    {
        $config = $station->backend_config;
        $raw = $config->toArray(true) ?? [];

        $hardClock = $config->top_of_hour_hard_trigger_enabled;
        $stretch = (bool)($raw['playout_stretch_squeeze_enabled'] ?? true);
        $stretchMax = (float)($raw['playout_stretch_squeeze_max_percent'] ?? 5.0);
        $smartDuck = $config->top_of_hour_duck_enabled;
        $enabledCount = (int)$hardClock + (int)$stretch + (int)$smartDuck;

        $feature['metric'] = sprintf('%d/3 enabled', $enabledCount);
        $feature['headline'] = $enabledCount > 0
            ? __('Advanced playout controls configured')
            : __('Advanced playout controls are inactive');
        $feature['details'] = [
            [
                'label' => __('Hard Clock'),
                'value' => $hardClock ? __('Enabled') : __('Disabled'),
            ],
            [
                'label' => __('Stretch / Squeeze'),
                'value' => $stretch
                    ? sprintf(__('Enabled (max %.1f%%)'), $stretchMax)
                    : __('Disabled'),
            ],
            [
                'label' => __('Smart Ducking'),
                'value' => $smartDuck ? __('Enabled') : __('Disabled'),
            ],
        ];

        $stats = (array)($feature['stats'] ?? []);
        $stats['checks_passed'] = $enabledCount;
        $stats['successes'] = $enabledCount + (int)($stats['successful_executions'] ?? 0);
        $stats['observations'] = (int)$stats['successes']
            + (int)($stats['warnings'] ?? 0)
            + (int)($stats['failures'] ?? 0);
        $stats['success_rate'] = $stats['observations'] > 0
            ? round(((int)$stats['successes'] / (int)$stats['observations']) * 100, 1)
            : null;
        $feature['stats'] = $stats;

        if (!in_array((string)($feature['status'] ?? ''), ['critical', 'warning'], true)) {
            $feature['status'] = $enabledCount > 0 ? 'healthy' : 'inactive';
        }
    }

    /** @param array<string, mixed> $feature */
    private function applyConfidenceStatus(array &$feature): void
    {
        if ('healthy' !== ($feature['status'] ?? null)) {
            return;
        }

        $basis = strtolower((string)($feature['basis'] ?? ''));
        if (str_contains($basis, 'live')) {
            return;
        }

        $stats = (array)($feature['stats'] ?? []);
        $executions = (int)($stats['successful_executions'] ?? 0);
        $failures = (int)($stats['failures'] ?? 0);
        $warnings = (int)($stats['warnings'] ?? 0);
        if ($executions > 0 || $failures > 0 || $warnings > 0) {
            return;
        }

        $needsRuntimeEvidence = str_contains($basis, 'history')
            || str_contains($basis, 'events')
            || str_contains($basis, 'logs')
            || str_contains($basis, 'database');

        if ($needsRuntimeEvidence) {
            $feature['status'] = 'monitoring';
            $feature['confidence_note'] = __('Configuration checks passed, but no successful runtime execution was observed in the selected range.');
        }
    }

    /** @param list<array<string, mixed>> $features @return array<string, int> */
    private function buildDistribution(array $features): array
    {
        $distribution = [
            'healthy' => 0,
            'monitoring' => 0,
            'warning' => 0,
            'critical' => 0,
            'inactive' => 0,
        ];

        foreach ($features as $feature) {
            $status = is_array($feature) ? (string)($feature['status'] ?? 'inactive') : 'inactive';
            if (array_key_exists($status, $distribution)) {
                ++$distribution[$status];
            }
        }

        return $distribution;
    }

    /** @param list<array<string, mixed>> $features */
    private function calculateHealthScore(array $features): int
    {
        $weights = [
            'healthy' => 100,
            'monitoring' => 82,
            'warning' => 58,
            'critical' => 12,
        ];
        $scores = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $status = (string)($feature['status'] ?? 'inactive');
            if (isset($weights[$status])) {
                $scores[] = $weights[$status];
            }
        }

        return [] === $scores ? 100 : (int)round(array_sum($scores) / count($scores));
    }

    /** @param list<array<string, mixed>> $features @param list<array<string, mixed>> $services */
    private function calculateOverallStatus(array $features, array $services): string
    {
        foreach ([...$features, ...$services] as $item) {
            if (is_array($item) && 'critical' === ($item['status'] ?? null)) {
                return 'critical';
            }
        }
        foreach ($features as $feature) {
            if (is_array($feature) && 'warning' === ($feature['status'] ?? null)) {
                return 'warning';
            }
        }
        foreach ($features as $feature) {
            if (is_array($feature) && 'monitoring' === ($feature['status'] ?? null)) {
                return 'monitoring';
            }
        }

        return 'healthy';
    }

    /** @param array<string, mixed> $service */
    private function serviceProblem(array $service): string
    {
        $description = trim((string)($service['description'] ?? ''));
        $recovery = trim((string)($service['recovery'] ?? ''));

        if ('' !== $description && '' !== $recovery) {
            return $description . ' ' . sprintf(__('Recovery: %s'), $recovery);
        }
        if ('' !== $description) {
            return $description;
        }
        if ('' !== $recovery) {
            return sprintf(__('Recovery: %s'), $recovery);
        }

        return __('Service is configured but is not currently running.');
    }
}
