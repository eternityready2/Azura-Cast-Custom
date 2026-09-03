<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\AirCheckDiagnosticsRecorder;
use Psr\Http\Message\ResponseInterface;

final readonly class AirCheckRunAction implements SingleActionInterface
{
    public function __construct(
        private FeatureSuiteController $featureSuiteController,
        private AirCheckDiagnosticsRecorder $diagnosticsRecorder,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $result = $this->featureSuiteController->runAirCheck($station, true);
        $this->diagnosticsRecorder->recordRecoveryResult($station, $result);

        return $response->withJson($result);
    }
}
