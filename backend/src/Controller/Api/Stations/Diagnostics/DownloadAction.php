<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsReport;
use Psr\Http\Message\ResponseInterface;

final readonly class DownloadAction implements SingleActionInterface
{
    public function __construct(
        private StationDiagnosticsReport $report,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        [$start, $end, $feature] = SummaryAction::resolveFilters($request, $station);
        $contents = $this->report->render($station, $start, $end, $feature);

        $filename = sprintf(
            'station-diagnostics-%s-%s.txt',
            $station->short_name,
            date('Y-m-d')
        );

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->write($contents);
    }
}
