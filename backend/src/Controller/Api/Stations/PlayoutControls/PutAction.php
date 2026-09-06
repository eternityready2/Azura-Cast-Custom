<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\StationBackendConfiguration;
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
        summary: 'Save playout control settings.',
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
        $originalConfig = clone $config;
        $originalNeedsRestart = $station->needs_restart;

        if (array_key_exists('hard_clock_enabled', $body)) {
            $config->playout_hard_clock_enabled = $body['hard_clock_enabled'];
        }
        if (array_key_exists('hard_clock_trigger_seconds', $body)) {
            $this->validateRange($body['hard_clock_trigger_seconds'], 1, 30);
            $config->playout_hard_clock_trigger_seconds = $body['hard_clock_trigger_seconds'];
        }
        if (array_key_exists('hard_clock_fade_seconds', $body)) {
            $this->validateRange($body['hard_clock_fade_seconds'], 0, 10);
            $config->playout_hard_clock_fade_seconds = $body['hard_clock_fade_seconds'];
        }
        if (array_key_exists('stretch_squeeze_enabled', $body)) {
            $config->playout_stretch_squeeze_enabled = $body['stretch_squeeze_enabled'];
        }
        if (array_key_exists('stretch_squeeze_max_percent', $body)) {
            $this->validateRange($body['stretch_squeeze_max_percent'], 0.5, 5);
            $config->playout_stretch_squeeze_max_percent = $body['stretch_squeeze_max_percent'];
        }
        if (array_key_exists('smart_duck_enabled', $body)) {
            $config->playout_smart_duck_enabled = $body['smart_duck_enabled'];
        }
        if (array_key_exists('smart_duck_attenuation', $body)) {
            $this->validateRange($body['smart_duck_attenuation'], 0, 1);
            $config->playout_smart_duck_attenuation = $body['smart_duck_attenuation'];
        }
        if (array_key_exists('smart_duck_delay', $body)) {
            $this->validateRange($body['smart_duck_delay'], 0.5, 15);
            $config->playout_smart_duck_delay = $body['smart_duck_delay'];
        }

        $requiresRestart = $this->requiresRestart($originalConfig, $config);

        $station->backend_config = $config;
        if (!$requiresRestart) {
            // Stretch/squeeze is frozen into each queue row while AutoDJ plans it.
            // A runtime setting change therefore applies to newly planned rows
            // without rewriting already-committed queue decisions.
            $station->needs_restart = $originalNeedsRestart;
        }

        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    private function requiresRestart(
        StationBackendConfiguration $original,
        StationBackendConfiguration $updated,
    ): bool {
        return $original->playout_hard_clock_enabled !== $updated->playout_hard_clock_enabled
            || $original->playout_hard_clock_trigger_seconds !== $updated->playout_hard_clock_trigger_seconds
            || $original->playout_hard_clock_fade_seconds !== $updated->playout_hard_clock_fade_seconds
            || $original->playout_smart_duck_enabled !== $updated->playout_smart_duck_enabled
            || $original->playout_smart_duck_attenuation !== $updated->playout_smart_duck_attenuation
            || $original->playout_smart_duck_delay !== $updated->playout_smart_duck_delay;
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
