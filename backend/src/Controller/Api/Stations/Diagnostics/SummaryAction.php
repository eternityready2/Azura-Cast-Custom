<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Entity\Station;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsDashboardView;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class SummaryAction implements SingleActionInterface
{
    public function __construct(
        private StationDiagnosticsDashboardView $dashboard,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        [$start, $end, $feature] = self::resolveFilters($request, $station);

        try {
            return $response->withJson(
                $this->dashboard->getSnapshot($station, $start, $end, $feature)
            );
        } catch (Throwable $e) {
            $timestamp = time();
            $windowHours = max(1, (int)ceil(($end - $start) / 3600));

            return $response->withJson([
                'generated_at' => $timestamp,
                'window_hours' => $windowHours,
                'window' => [
                    'start' => $start,
                    'end' => $end,
                    'hours' => $windowHours,
                    'bucket_seconds' => $windowHours <= 48 ? 3600 : 86400,
                ],
                'filter' => [
                    'feature' => $feature,
                ],
                'available_features' => [[
                    'key' => 'diagnostics-engine',
                    'label' => __('Diagnostics Engine'),
                    'category' => 'runtime',
                ]],
                'overall_status' => 'critical',
                'health_score' => 0,
                'counts' => [
                    'critical' => 1,
                    'warning' => 0,
                    'healthy' => 0,
                    'monitoring' => 0,
                    'inactive' => 0,
                    'recent_events' => 0,
                    'active_issues' => 1,
                    'services_running' => 0,
                    'services_total' => 0,
                    'successes' => 0,
                    'failures' => 1,
                    'warning_signals' => 0,
                ],
                'station' => [],
                'distribution' => [
                    'healthy' => 0,
                    'monitoring' => 0,
                    'warning' => 0,
                    'critical' => 1,
                    'inactive' => 0,
                ],
                'timeline' => [],
                'features' => [[
                    'key' => 'diagnostics-engine',
                    'label' => __('Diagnostics Engine'),
                    'category' => 'runtime',
                    'status' => 'critical',
                    'headline' => __('A diagnostics source could not be analyzed'),
                    'detail' => $e->getMessage(),
                    'metric' => __('Check failed'),
                    'basis' => 'runtime',
                    'issues' => 1,
                    'details' => [],
                    'stats' => [
                        'successes' => 0,
                        'successful_executions' => 0,
                        'checks_passed' => 0,
                        'warnings' => 0,
                        'failures' => 1,
                        'observations' => 1,
                        'success_rate' => 0.0,
                    ],
                    'top_problems' => [[
                        'severity' => 'critical',
                        'feature_key' => 'diagnostics-engine',
                        'feature' => __('Diagnostics Engine'),
                        'title' => __('Dashboard analysis failed'),
                        'detail' => $e->getMessage(),
                        'timestamp' => $timestamp,
                        'source' => 'diagnostics',
                    ]],
                    'activity' => [],
                    'drilldown' => [[
                        'state' => 'failure',
                        'title' => __('Dashboard analysis failed'),
                        'detail' => $e->getMessage(),
                        'timestamp' => $timestamp,
                        'source' => 'diagnostics',
                    ]],
                    'last_success_at' => null,
                    'last_failure_at' => $timestamp,
                ]],
                'services' => [],
                'recent_issues' => [[
                    'severity' => 'critical',
                    'feature_key' => 'diagnostics-engine',
                    'feature' => __('Diagnostics Engine'),
                    'title' => __('Dashboard analysis failed'),
                    'detail' => $e->getMessage(),
                    'timestamp' => $timestamp,
                    'source' => 'diagnostics',
                ]],
            ]);
        }
    }

    /** @return array{0:int,1:int,2:string|null} */
    public static function resolveFilters(ServerRequest $request, Station $station): array
    {
        $query = $request->getQueryParams();
        $timezone = $station->getTimezoneObject();
        $now = new DateTimeImmutable('now', $timezone);
        $range = strtolower(trim((string)($query['range'] ?? '24h')));

        $end = $now;
        $start = match ($range) {
            '7d' => $end->modify('-7 days'),
            '30d' => $end->modify('-30 days'),
            default => $end->modify('-24 hours'),
        };

        if ('custom' === $range) {
            try {
                $startRaw = trim((string)($query['start'] ?? ''));
                $endRaw = trim((string)($query['end'] ?? ''));

                if ('' !== $startRaw) {
                    $start = (new DateTimeImmutable($startRaw, $timezone))->setTime(0, 0, 0);
                }
                if ('' !== $endRaw) {
                    $end = (new DateTimeImmutable($endRaw, $timezone))->setTime(23, 59, 59);
                }
            } catch (Throwable) {
                $end = $now;
                $start = $end->modify('-24 hours');
            }
        }

        if ($end > $now->modify('+5 minutes')) {
            $end = $now;
        }
        if ($start >= $end) {
            $start = $end->modify('-24 hours');
        }

        $earliest = $end->modify('-90 days');
        if ($start < $earliest) {
            $start = $earliest;
        }

        $feature = trim((string)($query['feature'] ?? ''));
        if ('' === $feature || 'all' === strtolower($feature)) {
            $feature = null;
        }

        return [$start->getTimestamp(), $end->getTimestamp(), $feature];
    }
}
