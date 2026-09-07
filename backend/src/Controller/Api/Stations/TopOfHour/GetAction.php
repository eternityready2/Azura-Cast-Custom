<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\TopOfHour;

use App\Container\EntityManagerAwareTrait;
use App\Controller\SingleActionInterface;
use App\Entity\Enums\StationMediaTypes;
use App\Entity\Repository\ClockWheelEventRepository;
use App\Entity\StationQueue;
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
        summary: 'Get Top-of-Hour Station ID wall-clock settings and status.',
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
        $startSecond = $this->clock->getIdStartSecond($station);
        $fadeSeconds = $this->clock->getIdFadeSeconds($station);
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
        $staging = [
            'is_staged' => false,
            'queue_id' => null,
        ];

        if (null !== $plan) {
            $secondsAvailable = max(
                0.0,
                (float)$plan->boundaryAt->format('U.u') - (float)$plan->targetStartAt->format('U.u')
            );
            $recommendedSecond = max(
                TopOfHourClock::MIN_ID_START_SECOND,
                min(
                    TopOfHourClock::MAX_ID_START_SECOND,
                    (int)floor(60.0 - $plan->durationSeconds),
                )
            );

            $next = [
                'mode' => $plan->mode->value,
                'boundary_at' => $plan->boundaryAt->format(DateTimeImmutable::ATOM),
                'target_start_at' => $plan->targetStartAt->format(DateTimeImmutable::ATOM),
                'duration_seconds' => round($plan->durationSeconds, 3),
                'rigid_zero_event' => $plan->isHard(),
                'seconds_available_before_boundary' => round($secondsAvailable, 3),
                'will_be_cut_at_boundary' => $plan->isHard()
                    && $plan->durationSeconds > $secondsAvailable,
                'recommended_start_second' => $recommendedSecond,
                'media' => [
                    'id' => $plan->media->id,
                    'title' => $plan->media->title,
                    'artist' => $plan->media->artist,
                ],
            ];

            $stagedRow = $this->em->createQuery(
                <<<'DQL'
                    SELECT q FROM App\Entity\StationQueue q
                    WHERE q.station = :station
                    AND q.top_of_hour_legal_id = true
                    AND q.top_of_hour_boundary_at = :boundary
                    AND q.is_played = false
                DQL
            )->setParameters([
                'station' => $station,
                'boundary' => Time::toUtcCarbonImmutable($plan->boundaryAt),
            ])->setMaxResults(1)
                ->getOneOrNullResult();

            if ($stagedRow instanceof StationQueue) {
                $staging = [
                    'is_staged' => true,
                    'queue_id' => $stagedRow->id,
                ];
            }
        }

        return $response->withJson([
            'top_of_hour_id_enabled' => $backendConfig->top_of_hour_id_enabled,
            'top_of_hour_lookahead_minutes' => $this->clock->getLookaheadMinutes($station),
            'top_of_hour_compliance_tolerance_seconds' => $tolerance,
            'top_of_hour_id_max_seconds' => $this->clock->getIdMaxSeconds($station),
            'top_of_hour_id_start_second' => $startSecond,
            'top_of_hour_id_fade_seconds' => $fadeSeconds,
            'configured_start_label' => sprintf(':59:%02d', $startSecond),
            'id_media_count' => $idMediaCount,
            'compliance' => $this->eventRepo->getStationTopOfHourLegalIdComplianceSummary(
                $station,
                $since,
                $tolerance,
            ),
            'next' => $next,
            'staging' => $staging,
            'engine' => 'wall_clock_runtime',
            'defaults' => [
                'lookahead_minutes' => TopOfHourClock::DEFAULT_LOOKAHEAD_MINUTES,
                'compliance_tolerance_seconds' => TopOfHourClock::DEFAULT_COMPLIANCE_TOLERANCE_SECONDS,
                'id_max_seconds' => TopOfHourClock::DEFAULT_ID_MAX_SECONDS,
                'id_start_second' => TopOfHourClock::DEFAULT_ID_START_SECOND,
                'id_fade_seconds' => TopOfHourClock::DEFAULT_ID_FADE_SECONDS,
            ],
        ]);
    }
}
