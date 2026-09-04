<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnosticsDashboard;
use Psr\Http\Message\ResponseInterface;

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
        return $response->withJson(
            $this->dashboard->getSnapshot($request->getStation())
        );
    }
}
