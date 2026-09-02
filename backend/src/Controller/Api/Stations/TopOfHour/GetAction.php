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
use App\Radio\AutoDJ\HourBoundaryPlanner;
use DateTimeImmutable;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[
    OA\Get(
        path: '/station/{station_id}/top-of-hour',
        operationId: 'getStationTopOfHourSettings',
        summary: 'Get top-of-hour legal ID protection settings.',
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
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $this->em->refetch($request->getStation());
        $backendConfig = $station->backend_config;
        $tolerance = $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station);
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

        return $response->withJson([
        'top_of_hour_id_enabled' => $backendConfig->top_of_hour_id_enabled,
        'top_of_hour_lookahead_minutes' => $backendConfig->top_of_hour_lookahead_minutes,
        'top_of_hour_compliance_tolerance_seconds' => $backendConfig->top_of_hour_compliance_tolerance_seconds,
        'top_of_hour_id_max_seconds' => $backendConfig->top_of_hour_id_max_seconds,
        'id_media_count' => $idMediaCount,
        'legal_id_media_count' => $idMediaCount,
        'compliance' => $this->eventRepo->getStationTopOfHourLegalIdComplianceSummary(
            $station,
            $since,
            $tolerance,
        ),
        'defaults' => [
        'lookahead_minutes' => HourBoundaryPlanner::DEFAULT_LOOKAHEAD_MINUTES,
        'compliance_tolerance_seconds' => HourBoundaryPlanner::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
        'id_max_seconds' => HourBoundaryPlanner::DEFAULT_ID_MAX_SECONDS,
        ],
        ]);
    }
}
