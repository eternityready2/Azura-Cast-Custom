<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\StationBackendConfiguration;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\Adapters;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Radio\Backend\Liquidsoap;
use App\Utilities\Time;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

#[
    OA\Put(
        path: '/station/{station_id}/top-of-hour',
        operationId: 'putStationTopOfHourSettings',
        summary: 'Save Top-of-Hour Station ID wall-clock settings.',
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
    private const array ENTITY_FIELDS = [
        'top_of_hour_id_enabled',
        'top_of_hour_lookahead_minutes',
        'top_of_hour_compliance_tolerance_seconds',
        'top_of_hour_id_max_seconds',
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly TopOfHourClock $clock,
        private readonly Adapters $adapters,
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
        $originalNeedsRestart = $station->needs_restart;

        foreach (self::ENTITY_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $backendConfig->$field = $body[$field];
            }
        }

        // These two runtime-specific values intentionally live in the backend
        // configuration's forward-compatible extra-data bag, avoiding a schema
        // migration solely for operator controls.
        $extra = [];
        if (array_key_exists(TopOfHourClock::CONFIG_ID_START_SECOND, $body)) {
            $extra[TopOfHourClock::CONFIG_ID_START_SECOND] = (int)$body[TopOfHourClock::CONFIG_ID_START_SECOND];
        }
        if (array_key_exists(TopOfHourClock::CONFIG_ID_FADE_SECONDS, $body)) {
            $extra[TopOfHourClock::CONFIG_ID_FADE_SECONDS] = (float)$body[TopOfHourClock::CONFIG_ID_FADE_SECONDS];
        }
        if ([] !== $extra) {
            $backendConfig->fromArray($extra);
        }

        $this->validateRanges($backendConfig);

        $station->backend_config = $backendConfig;

        // The runtime lane is always present in generated Liquidsoap config.
        // Settings changes therefore do not themselves require a station restart;
        // synchronize a running instance immediately where possible.
        $station->needs_restart = $originalNeedsRestart;

        $this->em->persist($station);
        $this->em->flush();

        $this->syncRunningLiquidsoap($station);

        return $response->withJson(Status::updated());
    }

    private function validateRanges(StationBackendConfiguration $backendConfig): void
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

        $raw = $backendConfig->toArray(true) ?? [];
        $this->validateRange(
            (int)($raw[TopOfHourClock::CONFIG_ID_START_SECOND] ?? TopOfHourClock::DEFAULT_ID_START_SECOND),
            TopOfHourClock::MIN_ID_START_SECOND,
            TopOfHourClock::MAX_ID_START_SECOND,
        );
        $this->validateRange(
            (float)($raw[TopOfHourClock::CONFIG_ID_FADE_SECONDS] ?? TopOfHourClock::DEFAULT_ID_FADE_SECONDS),
            TopOfHourClock::MIN_ID_FADE_SECONDS,
            TopOfHourClock::MAX_ID_FADE_SECONDS,
        );
    }

    private function syncRunningLiquidsoap(object $station): void
    {
        try {
            /** @var \App\Entity\Station $station */
            $backend = $this->adapters->getBackendAdapter($station);
            if (!$backend instanceof Liquidsoap) {
                return;
            }

            $enabled = $this->clock->isEnabled($station);
            $backend->command(
                $station,
                'top_of_hour_id_control.enabled ' . ($enabled ? 'true' : 'false')
            );
            $backend->command(
                $station,
                'top_of_hour_id_control.fade_seconds '
                . number_format($this->clock->getIdFadeSeconds($station), 1, '.', '')
            );

            if (!$enabled) {
                $backend->command($station, 'top_of_hour_id_control.clear');
                return;
            }

            $plan = $this->clock->plan($station, Time::nowUtc());
            if (null === $plan) {
                return;
            }

            $backend->command(
                $station,
                'top_of_hour_id_control.target_epoch '
                . number_format((float)$plan->targetStartAt->format('U.u'), 3, '.', '')
            );
            $backend->command(
                $station,
                'top_of_hour_id_control.boundary_epoch '
                . number_format((float)$plan->boundaryAt->format('U.u'), 3, '.', '')
            );
            $backend->command(
                $station,
                'top_of_hour_id_control.hard ' . ($plan->isHard() ? 'true' : 'false')
            );
        } catch (Throwable) {
            // Saving settings must still succeed while the station is stopped or
            // restarting. The minute staging task will synchronize on next run.
        }
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
