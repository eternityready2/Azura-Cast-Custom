<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[
    OA\Put(
        path: '/station/{station_id}/playout-controls',
        operationId: 'putStationPlayoutControls',
        summary: 'Save station-wide playout control settings.',
        tags: [OpenApi::TAG_STATIONS_BROADCASTING],
        parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
        ]
    )
]
final class PutAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $body = (array)$request->getParsedBody();
        $station = $this->em->refetch($request->getStation());
        $config = $station->backend_config;

        if (array_key_exists('scheduled_boundary_enabled', $body)) {
            $config->top_of_hour_hard_trigger_enabled = $body['scheduled_boundary_enabled'];
        }
        if (array_key_exists('scheduled_boundary_window_seconds', $body)) {
            $this->validateRange($body['scheduled_boundary_window_seconds'], 60, 180);
            $config->top_of_hour_hard_trigger_seconds = $body['scheduled_boundary_window_seconds'];
        }
        if (array_key_exists('interrupting_duck_enabled', $body)) {
            $config->top_of_hour_duck_enabled = $body['interrupting_duck_enabled'];
        }
        if (array_key_exists('interrupting_duck_attenuation', $body)) {
            $this->validateRange($body['interrupting_duck_attenuation'], 0, 1);
            $config->top_of_hour_duck_attenuation = $body['interrupting_duck_attenuation'];
        }
        if (array_key_exists('interrupting_duck_delay', $body)) {
            $this->validateRange($body['interrupting_duck_delay'], 0.5, 15);
            $config->top_of_hour_duck_delay = $body['interrupting_duck_delay'];
        }

        $station->backend_config = $config;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    private function validateRange(mixed $value, int|float $min, int|float $max): void
    {
        $errors = $this->validator->validate($value, [
        new Range(min: $min, max: $max),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }
    }
}
