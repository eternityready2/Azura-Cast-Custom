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
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[
    OA\Put(
        path: '/station/{station_id}/top-of-hour',
        operationId: 'putStationTopOfHourSettings',
        summary: 'Save Top-of-Hour Station ID broadcast-clock settings.',
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
        'top_of_hour_lookahead_minutes',
        'top_of_hour_compliance_tolerance_seconds',
        'top_of_hour_id_max_seconds',
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
        $body = (array)$request->getParsedBody();
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
        $this->validateRange(
            $backendConfig->top_of_hour_lookahead_minutes,
            TopOfHourClock::MIN_LOOKAHEAD_MINUTES,
            TopOfHourClock::MAX_LOOKAHEAD_MINUTES,
        );
        $this->validateRange(
            $backendConfig->top_of_hour_compliance_tolerance_seconds,
            TopOfHourClock::MIN_COMPLIANCE_TOLERANCE_SECONDS,
            TopOfHourClock::MAX_COMPLIANCE_TOLERANCE_SECONDS,
        );
        $this->validateRange(
            $backendConfig->top_of_hour_id_max_seconds,
            TopOfHourClock::MIN_ID_MAX_SECONDS,
            TopOfHourClock::MAX_ID_MAX_SECONDS,
        );
    }

    private function validateRange(int $value, int $min, int $max): void
    {
        $errors = $this->validator->validate($value, [
            new Range(min: $min, max: $max),
        ]);

        if (count($errors) > 0) {
            throw ValidationException::fromValidationErrors($errors);
        }
    }
}
