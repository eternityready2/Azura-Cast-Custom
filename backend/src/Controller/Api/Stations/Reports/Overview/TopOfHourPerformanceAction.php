<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Reports\Overview;

use App\Entity\Api\Status;
use App\Entity\Repository\StationQueueRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Radio\AutoDJ\HourBoundaryPlanner;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/reports/overview/top-of-hour-performance',
    operationId: 'getStationReportTopOfHourPerformance',
    summary: 'Get station-wide Top of Hour ID compliance.',
    tags: [OpenApi::TAG_STATIONS_REPORTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final class TopOfHourPerformanceAction extends AbstractReportAction
{
    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly HourBoundaryPlanner $hourBoundaryPlanner,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        if (!$this->isAnalyticsEnabled()) {
            return $response->withStatus(400)
                ->withJson(new Status(false, 'Reporting is restricted due to system analytics level.'));
        }

        $station = $request->getStation();
        $dateRange = $this->getDateRange($request, $station->getTimezoneObject());

        return $response->withJson([
            'compliance' => $this->queueRepo->getTopOfHourLegalIdComplianceSummary(
                $station,
                $dateRange->start,
                $this->hourBoundaryPlanner->getComplianceToleranceSeconds($station),
                $dateRange->end,
            ),
        ]);
    }
}
