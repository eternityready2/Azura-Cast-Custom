<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsDashboard;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class SummaryAction implements SingleActionInterface
{
    public function __construct(
        private StationDiagnosticsDashboard $dashboard,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        try {
            return $response->withJson(
                $this->dashboard->getSnapshot($request->getStation())
            );
        } catch (Throwable $e) {
            $timestamp = time();

            return $response->withJson([
                'generated_at' => $timestamp,
                'window_hours' => 24,
                'overall_status' => 'critical',
                'health_score' => 0,
                'counts' => [
                    'critical' => 1,
                    'warning' => 0,
                    'healthy' => 0,
                    'inactive' => 0,
                    'recent_events' => 0,
                    'active_issues' => 1,
                    'services_running' => 0,
                    'services_total' => 0,
                ],
                'station' => [],
                'distribution' => [
                    'healthy' => 0,
                    'warning' => 0,
                    'critical' => 1,
                    'inactive' => 0,
                ],
                'timeline' => [],
                'features' => [[
                    'key' => 'diagnostics-engine',
                    'label' => __('Diagnostics Engine'),
                    'status' => 'critical',
                    'headline' => __('A diagnostics source could not be analyzed'),
                    'detail' => $e->getMessage(),
                    'metric' => __('Check failed'),
                    'basis' => 'runtime',
                    'issues' => 1,
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
}
