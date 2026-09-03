<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Diagnostics;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\StationDiagnostics;
use Psr\Http\Message\ResponseInterface;

final readonly class DownloadAction implements SingleActionInterface
{
    public function __construct(
        private StationDiagnostics $diagnostics,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $path = $this->diagnostics->ensureLogFile($station);
        $contents = file_get_contents($path) ?: '';
        $contents = str_replace($station->getFilteredPasswords(), '(PASSWORD)', $contents);
        $filename = sprintf(
            'custom-feature-diagnostics-%s-%s.log',
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
