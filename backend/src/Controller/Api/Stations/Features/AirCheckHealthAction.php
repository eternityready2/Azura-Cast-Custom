<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Features;

use App\Controller\SingleActionInterface;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Service\AirCheckHealthMonitor;
use Psr\Http\Message\ResponseInterface;

final readonly class AirCheckHealthAction implements SingleActionInterface
{
    public function __construct(
        private AirCheckHealthMonitor $healthMonitor,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        return $response->withJson(
            $this->healthMonitor->getSnapshot($request->getStation())
        );
    }
}
