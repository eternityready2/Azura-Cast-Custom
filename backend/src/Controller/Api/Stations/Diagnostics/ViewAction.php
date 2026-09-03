<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\Api\Traits\HasLogViewer;
use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnostics;
use Psr\Http\Message\ResponseInterface;

final class ViewAction implements SingleActionInterface
{
    use HasLogViewer;

    public function __construct(
        private readonly StationDiagnostics $diagnostics,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        return $this->streamLogToResponse(
            $request,
            $response,
            $this->diagnostics->ensureLogFile($station),
            true,
            $station->getFilteredPasswords()
        );
    }
}
