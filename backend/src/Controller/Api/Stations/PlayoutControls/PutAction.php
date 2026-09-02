<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\PlayoutControls;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Api\Status;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationBackendConfiguration;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\StretchSqueezeQueueTiming;
use App\Utilities\Types;
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

    private const bool DEFAULT_STRETCH_SQUEEZE_ENABLED = true;

    private const float DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT = 5.0;

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly StationQueueRepository $queueRepo,
        private readonly StretchSqueezeQueueTiming $stretchSqueezeQueueTiming,
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
            $config->top_of_hour_hard_trigger_enabled = $body['hard_clock_enabled'];
        }
        if (array_key_exists('hard_clock_trigger_seconds', $body)) {
            $this->validateRange($body['hard_clock_trigger_seconds'], 1, 30);
            $config->top_of_hour_hard_trigger_seconds = $body['hard_clock_trigger_seconds'];
        }
        if (array_key_exists('hard_clock_fade_seconds', $body)) {
            $this->validateRange($body['hard_clock_fade_seconds'], 0, 10);
            $config->top_of_hour_hard_trigger_fade = $body['hard_clock_fade_seconds'];
        }
        if (array_key_exists('stretch_squeeze_enabled', $body)) {
            $config->fromArray([
                'playout_stretch_squeeze_enabled' => Types::bool(
                    $body['stretch_squeeze_enabled'],
                    false,
                    true
                ),
            ]);
        }
        if (array_key_exists('stretch_squeeze_max_percent', $body)) {
            $this->validateRange($body['stretch_squeeze_max_percent'], 0.5, 5);
            $config->fromArray([
                'playout_stretch_squeeze_max_percent' => Types::float(
                    $body['stretch_squeeze_max_percent'],
                    self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT
                ),
            ]);
        }
        if (array_key_exists('smart_duck_enabled', $body)) {
            $config->top_of_hour_duck_enabled = $body['smart_duck_enabled'];
        }
        if (array_key_exists('smart_duck_attenuation', $body)) {
            $this->validateRange($body['smart_duck_attenuation'], 0, 1);
            $config->top_of_hour_duck_attenuation = $body['smart_duck_attenuation'];
        }
        if (array_key_exists('smart_duck_delay', $body)) {
            $this->validateRange($body['smart_duck_delay'], 0.5, 15);
            $config->top_of_hour_duck_delay = $body['smart_duck_delay'];
        }

        $requiresRestart = $this->requiresRestart($originalConfig, $config);
        $stretchSqueezeChanged = $this->stretchSqueezeSettingsChanged($originalConfig, $config);

        $station->backend_config = $config;
        if (!$requiresRestart) {
            // Stretch / Squeeze is consumed dynamically from AutoDJ annotation
            // metadata, so a stretch-only update must not create a new restart
            // requirement or clear a restart that was already pending beforehand.
            $station->needs_restart = $originalNeedsRestart;
        }

        $this->em->persist($station);
        $this->em->flush();

        if ($stretchSqueezeChanged) {
            // Rows already handed to Liquidsoap keep the annotations and duration
            // they were sent with. Reconcile only unsent rows against the newly
            // persisted runtime setting so their projected duration matches the
            // annotation they will receive when they are eventually handed off.
            foreach ($this->queueRepo->getUnplayedQueue($station) as $queueRow) {
                if ($queueRow->sent_to_autodj) {
                    continue;
                }

                $this->stretchSqueezeQueueTiming->normalizeQueueRow($station, $queueRow);
            }

            $this->em->flush();
        }

        return $response->withJson(Status::updated());
    }

    private function requiresRestart(
        StationBackendConfiguration $original,
        StationBackendConfiguration $updated,
    ): bool {
        return $original->top_of_hour_hard_trigger_enabled !== $updated->top_of_hour_hard_trigger_enabled
            || $original->top_of_hour_hard_trigger_seconds !== $updated->top_of_hour_hard_trigger_seconds
            || $original->top_of_hour_hard_trigger_fade !== $updated->top_of_hour_hard_trigger_fade
            || $original->top_of_hour_duck_enabled !== $updated->top_of_hour_duck_enabled
            || $original->top_of_hour_duck_attenuation !== $updated->top_of_hour_duck_attenuation
            || $original->top_of_hour_duck_delay !== $updated->top_of_hour_duck_delay;
    }

    private function stretchSqueezeSettingsChanged(
        StationBackendConfiguration $original,
        StationBackendConfiguration $updated,
    ): bool {
        $originalRaw = $original->toArray(true) ?? [];
        $updatedRaw = $updated->toArray(true) ?? [];

        $originalEnabled = Types::bool(
            $originalRaw['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_STRETCH_SQUEEZE_ENABLED,
            self::DEFAULT_STRETCH_SQUEEZE_ENABLED,
            true
        );
        $updatedEnabled = Types::bool(
            $updatedRaw['playout_stretch_squeeze_enabled'] ?? self::DEFAULT_STRETCH_SQUEEZE_ENABLED,
            self::DEFAULT_STRETCH_SQUEEZE_ENABLED,
            true
        );

        $originalMaxPercent = Types::float(
            $originalRaw['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT,
            self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT
        );
        $updatedMaxPercent = Types::float(
            $updatedRaw['playout_stretch_squeeze_max_percent'] ?? self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT,
            self::DEFAULT_STRETCH_SQUEEZE_MAX_PERCENT
        );

        return $originalEnabled !== $updatedEnabled
            || abs($originalMaxPercent - $updatedMaxPercent) > 0.0001;
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
