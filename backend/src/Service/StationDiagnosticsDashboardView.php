<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Station;
use App\Entity\StationStreamer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Presentation layer for the station diagnostics snapshot.
 *
 * Keeps the collector focused on gathering evidence while this service applies
 * operator-facing confidence semantics, fills operational areas backed by
 * existing database history, and normalizes totals used by the dashboard/report.
 */
final readonly class StationDiagnosticsDashboardView
{
    public function __construct(
        private StationDiagnosticsDashboard $dashboard,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function getSnapshot(
        Station $station,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        ?string $featureFilter = null,
    ): array {
        // Always collect the full snapshot here so presentation-layer feature
        // additions participate in the same filter/export behavior as native
        // collector features.
        $snapshot = $this->dashboard->getSnapshot(
            $station,
            $startTimestamp,
            $endTimestamp,
            null,
        );

        $window = (array)($snapshot['window'] ?? []);
        $start = (int)($window['start'] ?? $startTimestamp ?? (time() - 86400));
        $end = (int)($window['end'] ?? $endTimestamp ?? time());
        $generatedAt = (int)($snapshot['generated_at'] ?? time());

        $features = (array)($snapshot['features'] ?? []);
        $recentIssues = (array)($snapshot['recent_issues'] ?? []);

        [$liveBroadcastingFeature, $liveBroadcastingIssues] = $this->buildLiveBroadcastingFeature(
            $station,
            $start,
            $end,
            $generatedAt,
        );
        $features[] = $liveBroadcastingFeature;
        $recentIssues = [...$recentIssues, ...$liveBroadcastingIssues];

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

        $availableFeatures = (array)($snapshot['available_features'] ?? []);
        $availableFeatures[] = [
            'key' => 'live-broadcasting',
            'label' => __('Live Broadcasting'),
            'category' => 'runtime',
        ];
        $availableFeatures = $this->uniqueFeatureDefinitions($availableFeatures);
        $snapshot['available_features'] = $availableFeatures;

        $validFeatureKeys = array_column($availableFeatures, 'key');
        if (null !== $featureFilter && !in_array($featureFilter, $validFeatureKeys, true)) {
            $featureFilter = null;
        }

        $services = (array)($snapshot['services'] ?? []);
        if (null !== $featureFilter && '' !== $featureFilter) {
            $features = array_values(array_filter(
                $features,
                static fn(array $feature): bool => ($feature['key'] ?? null) === $featureFilter,
            ));
            $recentIssues = array_values(array_filter(
                $recentIssues,
                static fn(array $issue): bool => ($issue['feature_key'] ?? null) === $featureFilter,
            ));
            if ('station-services' !== $featureFilter) {
                $services = [];
            }
        }

        $recentIssues = $this->sortIssues($recentIssues);
        $snapshot['filter'] = ['feature' => $featureFilter];
        $snapshot['features'] = array_values($features);
        $snapshot['distribution'] = $this->buildDistribution($features);
        $snapshot['health_score'] = $this->calculateHealthScore($features);
        $snapshot['overall_status'] = $this->calculateOverallStatus($features, $services);

        $counts = (array)($snapshot['counts'] ?? []);
        foreach (['healthy', 'monitoring', 'warning', 'critical', 'inactive'] as $status) {
            $counts[$status] = (int)($snapshot['distribution'][$status] ?? 0);
        }

        $successes = 0;
        $warnings = 0;
        $failures = 0;
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $stats = (array)($feature['stats'] ?? []);
            $successes += (int)($stats['successes'] ?? 0);
            $warnings += (int)($stats['warnings'] ?? 0);
            $failures += (int)($stats['failures'] ?? 0);
        }
        $counts['successes'] = $successes;
        $counts['warning_signals'] = $warnings;
        $counts['failures'] = $failures;
        $counts['active_issues'] = count($recentIssues);
        $snapshot['counts'] = $counts;

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
        $snapshot['recent_issues'] = array_slice($recentIssues, 0, 40);

        return $snapshot;
    }

    /**
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function buildLiveBroadcastingFeature(
        Station $station,
        int $start,
        int $end,
        int $generatedAt,
    ): array {
        $enabled = $station->enable_streamers;
        $accounts = 0;
        $activeAccounts = 0;
        $scheduledAccounts = 0;

        foreach ($station->streamers as $streamer) {
            if (!$streamer instanceof StationStreamer) {
                continue;
            }
            ++$accounts;
            if ($streamer->is_active) {
                ++$activeAccounts;
            }
            if ($streamer->enforce_schedule && $streamer->schedule_items->count() > 0) {
                ++$scheduledAccounts;
            }
        }

        [$broadcasts, $lastBroadcastAt] = $this->getLiveBroadcastStats($station, $start, $end);
        $issues = [];
        $status = 'inactive';
        if ($enabled) {
            if (0 === $activeAccounts) {
                $status = 'warning';
                $issues[] = [
                    'severity' => 'warning',
                    'feature_key' => 'live-broadcasting',
                    'feature' => __('Live Broadcasting'),
                    'title' => __('Live broadcasting is enabled but no active DJ accounts are available'),
                    'detail' => __('Add or reactivate a streamer/DJ account before expecting a live connection.'),
                    'timestamp' => $generatedAt,
                    'source' => 'state',
                ];
            } else {
                $status = 'healthy';
            }
        }

        $checksPassed = $enabled ? $activeAccounts : 0;
        $successes = $checksPassed + $broadcasts;
        $warnings = count($issues);
        $observations = $successes + $warnings;
        $successRate = $observations > 0 ? round(($successes / $observations) * 100, 1) : null;

        $drilldown = [
            [
                'state' => $enabled ? 'success' : 'warning',
                'title' => __('Live broadcasting'),
                'detail' => $enabled ? __('Enabled') : __('Disabled'),
                'timestamp' => $generatedAt,
                'source' => 'state',
            ],
            [
                'state' => $activeAccounts > 0 ? 'success' : 'warning',
                'title' => __('Active DJ accounts'),
                'detail' => (string)$activeAccounts,
                'timestamp' => $generatedAt,
                'source' => 'state',
            ],
        ];
        if (null !== $lastBroadcastAt) {
            $drilldown[] = [
                'state' => 'success',
                'title' => __('Live broadcast observed'),
                'detail' => sprintf(__('%d live broadcast session(s) observed in the selected range.'), $broadcasts),
                'timestamp' => $lastBroadcastAt,
                'source' => 'database',
            ];
        }
        foreach ($issues as $issue) {
            $drilldown[] = [
                'state' => 'warning',
                'title' => (string)$issue['title'],
                'detail' => (string)$issue['detail'],
                'timestamp' => (int)$issue['timestamp'],
                'source' => (string)$issue['source'],
            ];
        }

        return [[
            'key' => 'live-broadcasting',
            'label' => __('Live Broadcasting'),
            'category' => 'runtime',
            'status' => $status,
            'headline' => !$enabled
                ? __('Live DJ/streamer broadcasting disabled')
                : __('Live DJ connection readiness'),
            'detail' => __('Tracks streamer/DJ account readiness and actual live broadcast sessions recorded in the selected range.'),
            'metric' => $enabled
                ? sprintf('%d active DJs · %d broadcasts', $activeAccounts, $broadcasts)
                : __('Off'),
            'basis' => 'state+database',
            'issues' => count($issues),
            'details' => [
                ['label' => __('Live broadcasting enabled'), 'value' => $enabled ? __('Yes') : __('No')],
                ['label' => __('DJ accounts'), 'value' => $accounts],
                ['label' => __('Active DJ accounts'), 'value' => $activeAccounts],
                ['label' => __('Scheduled DJ accounts'), 'value' => $scheduledAccounts],
                ['label' => __('Broadcasts in range'), 'value' => $broadcasts],
            ],
            'stats' => [
                'successes' => $successes,
                'successful_executions' => $broadcasts,
                'checks_passed' => $checksPassed,
                'warnings' => $warnings,
                'failures' => 0,
                'observations' => $observations,
                'success_rate' => $successRate,
            ],
            'top_problems' => array_slice($issues, 0, 4),
            'activity' => [],
            'drilldown' => array_slice($drilldown, 0, 12),
            'last_success_at' => $lastBroadcastAt ?? ($checksPassed > 0 ? $generatedAt : null),
            'last_failure_at' => null,
        ], $issues];
    }

    /** @return array{0:int,1:int|null} */
    private function getLiveBroadcastStats(Station $station, int $start, int $end): array
    {
        try {
            $utc = new DateTimeZone('UTC');
            $startDate = (new DateTimeImmutable('@' . $start))->setTimezone($utc);
            $endDate = (new DateTimeImmutable('@' . $end))->setTimezone($utc);

            /** @var array{count:mixed,last:mixed}|null $result */
            $result = $this->em->createQuery(
                <<<'DQL'
                    SELECT COUNT(b.id) AS count, MAX(b.timestampStart) AS last
                    FROM App\Entity\StationStreamerBroadcast b
                    WHERE b.station = :station AND b.timestampStart BETWEEN :start AND :end
                DQL
            )
                ->setParameter('station', $station)
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate)
                ->getOneOrNullResult();

            $last = $result['last'] ?? null;
            $lastTimestamp = null;
            if ($last instanceof DateTimeImmutable) {
                $lastTimestamp = $last->getTimestamp();
            } elseif (is_string($last) && '' !== $last) {
                $lastTimestamp = (new DateTimeImmutable($last, $utc))->getTimestamp();
            }

            return [(int)($result['count'] ?? 0), $lastTimestamp];
        } catch (Throwable) {
            return [0, null];
        }
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

    /** @param list<array<string,mixed>> $definitions @return list<array<string,mixed>> */
    private function uniqueFeatureDefinitions(array $definitions): array
    {
        $seen = [];
        $result = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $key = (string)($definition['key'] ?? '');
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $definition;
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function sortIssues(array $issues): array
    {
        usort($issues, static function (array $a, array $b): int {
            $severity = ['critical' => 2, 'warning' => 1, 'success' => 0];
            $cmp = ($severity[$b['severity'] ?? ''] ?? 0) <=> ($severity[$a['severity'] ?? ''] ?? 0);
            return 0 !== $cmp ? $cmp : ((int)($b['timestamp'] ?? 0) <=> (int)($a['timestamp'] ?? 0));
        });
        return $issues;
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
