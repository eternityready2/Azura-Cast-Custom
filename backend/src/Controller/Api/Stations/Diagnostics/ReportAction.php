<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsDashboard;
use Psr\Http\Message\ResponseInterface;

final readonly class ReportAction implements SingleActionInterface
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
        $station = $request->getStation();
        [$start, $end, $feature] = SummaryAction::resolveFilters($request, $station);
        $snapshot = $this->dashboard->getSnapshot($station, $start, $end, $feature);

        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Could not create diagnostics report.');
        }

        fputcsv($stream, [
            'section',
            'feature',
            'category',
            'status',
            'metric',
            'successes',
            'successful_executions',
            'checks_passed',
            'warnings',
            'failures',
            'success_rate',
            'title',
            'detail',
            'timestamp',
            'source',
        ]);

        foreach ((array)($snapshot['features'] ?? []) as $featureRow) {
            if (!is_array($featureRow)) {
                continue;
            }

            $stats = (array)($featureRow['stats'] ?? []);
            fputcsv($stream, [
                'feature',
                (string)($featureRow['label'] ?? ''),
                (string)($featureRow['category'] ?? ''),
                (string)($featureRow['status'] ?? ''),
                (string)($featureRow['metric'] ?? ''),
                (int)($stats['successes'] ?? 0),
                (int)($stats['successful_executions'] ?? 0),
                (int)($stats['checks_passed'] ?? 0),
                (int)($stats['warnings'] ?? 0),
                (int)($stats['failures'] ?? 0),
                null === ($stats['success_rate'] ?? null) ? '' : (string)$stats['success_rate'],
                (string)($featureRow['headline'] ?? ''),
                (string)($featureRow['detail'] ?? ''),
                '',
                (string)($featureRow['basis'] ?? ''),
            ]);

            foreach ((array)($featureRow['top_problems'] ?? []) as $problem) {
                if (!is_array($problem)) {
                    continue;
                }
                fputcsv($stream, [
                    'problem',
                    (string)($featureRow['label'] ?? ''),
                    (string)($featureRow['category'] ?? ''),
                    (string)($problem['severity'] ?? ''),
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    (string)($problem['title'] ?? ''),
                    (string)($problem['detail'] ?? ''),
                    isset($problem['timestamp']) ? date(DATE_ATOM, (int)$problem['timestamp']) : '',
                    (string)($problem['source'] ?? ''),
                ]);
            }
        }

        foreach ((array)($snapshot['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }
            fputcsv($stream, [
                'service',
                (string)($service['name'] ?? ''),
                (string)($service['scope'] ?? ''),
                (string)($service['status'] ?? ''),
                '', '', '', '', '', '', '',
                (string)($service['recovery'] ?? ''),
                (string)($service['description'] ?? ''),
                '',
                'live',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = sprintf(
            'station-diagnostics-%s-%s.csv',
            $station->short_name,
            date('Y-m-d')
        );

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->write(false === $csv ? '' : $csv);
    }
}
