<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[
    OA\Put(
        path: '/station/{station_id}/top-of-hour',
        operationId: 'putStationTopOfHourSettings',
        summary: 'Save top-of-hour legal ID protection settings.',
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

    /** @var array<int, string> */
    private const array VALID_FIELDS = [
        'top_of_hour_id_enabled',
        'top_of_hour_id_mode',
        'top_of_hour_lookahead_minutes',
        'top_of_hour_compliance_tolerance_seconds',
        'top_of_hour_finish_buffer_seconds',
        'top_of_hour_id_max_seconds',
        'top_of_hour_hard_trigger_enabled',
        'top_of_hour_hard_trigger_seconds',
        'top_of_hour_hard_trigger_fade',
        'top_of_hour_duck_enabled',
        'top_of_hour_duck_attenuation',
        'top_of_hour_duck_delay',
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $body = (array) $request->getParsedBody();

        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;

        foreach (self::VALID_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $backendConfig->$field = $body[$field];
            }
        }

        $this->validateRanges($backendConfig);

        $station->backend_config = $backendConfig;
        $this->em->persist($station);
        $this->em->flush();

        return $response->withJson(Status::updated());
    }

    private function validateRanges(object $backendConfig): void
    {
        $errors = $this->validator->validate($backendConfig->top_of_hour_lookahead_minutes, [
            new Range(
                min: HourBoundaryPlanner::MIN_LOOKAHEAD_MINUTES,
                max: HourBoundaryPlanner::MAX_LOOKAHEAD_MINUTES,
            ),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $errors = $this->validator->validate($backendConfig->top_of_hour_compliance_tolerance_seconds, [
            new Range(
                min: HourBoundaryPlanner::MIN_COMPLIANCE_TOLERANCE_SECONDS,
                max: HourBoundaryPlanner::MAX_COMPLIANCE_TOLERANCE_SECONDS,
            ),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $errors = $this->validator->validate($backendConfig->top_of_hour_finish_buffer_seconds, [
            new Range(
                min: HourBoundaryPlanner::MIN_FINISH_BUFFER_SECONDS,
                max: HourBoundaryPlanner::MAX_FINISH_BUFFER_SECONDS,
            ),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }

        $errors = $this->validator->validate($backendConfig->top_of_hour_id_max_seconds, [
            new Range(
                min: HourBoundaryPlanner::MIN_ID_MAX_SECONDS,
                max: HourBoundaryPlanner::MAX_ID_MAX_SECONDS,
            ),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }
    }
}
