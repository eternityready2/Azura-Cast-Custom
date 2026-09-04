<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Entity\Api\LogContents;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsReport;
use Psr\Http\Message\ResponseInterface;

final readonly class ViewAction implements SingleActionInterface
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

        return $response->withJson(
            new LogContents(
                $this->report->render($station, $start, $end, $feature),
                true,
            )
        );
    }
}
