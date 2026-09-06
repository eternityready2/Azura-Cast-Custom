<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\TopOfHour\TopOfHourClock;
use App\Utilities\Time;
use DateTimeImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/top-of-hour',
        operationId: 'getStationTopOfHourSettings',
        summary: 'Get Top-of-Hour Station ID broadcast-clock settings and status.',
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
final class GetAction implements SingleActionInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly ClockWheelEventRepository $eventRepo,
        private readonly TopOfHourClock $clock,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;
        $tolerance = $this->clock->getComplianceToleranceSeconds($station);
        $since = new DateTimeImmutable('-7 days', $station->getTimezoneObject());

        $idMediaCount = (int)$this->em->createQuery(
            <<<'DQL'
                SELECT COUNT(m.id) FROM App\Entity\StationMedia m
                WHERE m.storage_location = :storageLocation
                AND m.type IN (:types)
            DQL
        )->setParameters([
            'storageLocation' => $station->media_storage_location,
            'types' => StationMediaTypes::stationIdTypeValues(),
        ])->getSingleScalarResult();

        $plan = $this->clock->plan($station, Time::nowUtc());
        $next = null;
        if (null !== $plan) {
            $next = [
                'mode' => $plan->mode->value,
                'boundary_at' => $plan->boundaryAt->format(DateTimeImmutable::ATOM),
                'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
                'duration_seconds' => round($plan->durationSeconds, 3),
                'rigid_zero_event' => $plan->isHard(),
                'media' => [
                    'id' => $plan->media->id,
                    'title' => $plan->media->title,
                    'artist' => $plan->media->artist,
                ],
            ];
        }

        return $response->withJson([
            'top_of_hour_id_enabled' => $backendConfig->top_of_hour_id_enabled,
            'top_of_hour_lookahead_minutes' => $this->clock->getLookaheadMinutes($station),
            'top_of_hour_compliance_tolerance_seconds' => $tolerance,
            'top_of_hour_id_max_seconds' => $this->clock->getIdMaxSeconds($station),
            'id_media_count' => $idMediaCount,
            'compliance' => $this->eventRepo->getStationTopOfHourLegalIdComplianceSummary(
                $station,
                $since,
                $tolerance,
            ),
            'next' => $next,
            'engine' => 'broadcast_clock',
            'defaults' => [
                'lookahead_minutes' => TopOfHourClock::DEFAULT_LOOKAHEAD_MINUTES,
                'compliance_tolerance_seconds' => TopOfHourClock::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
                'id_max_seconds' => TopOfHourClock::DEFAULT_ID_MAX_SECONDS,
            ],
        ]);
    }
}
