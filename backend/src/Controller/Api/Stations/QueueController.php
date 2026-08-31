<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Cache\QueueLogCache;
use App\Entity\Api\StationQueueDetailed;
use App\Entity\Api\Status;
use App\Entity\ApiGenerator\StationQueueApiGenerator;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\StationQueue;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends AbstractStationApiCrudController<StationQueue> */
#[
    OA\Get(
        path: '/station/{station_id}/queue',
        operationId: 'getQueue',
        summary: 'Return information about the upcoming song playback queue.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        allOf: [
                            new OA\Schema(ref: StationQueue::class),
                            new OA\Schema(ref: StationQueueDetailed::class),
                        ]
                    )
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/queue/{id}',
        operationId: 'getQueueItem',
        summary: 'Retrieve details of a single queued item.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Queue Item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: StationQueue::class),
                        new OA\Schema(ref: StationQueueDetailed::class),
                    ]
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/queue/{id}',
        operationId: 'deleteQueueItem',
        summary: 'Delete a single queued item.',
        tags: [OpenApi::TAG_STATIONS_QUEUE],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Queue Item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', format: 'int64')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    )
]
final class QueueController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationQueue::class;
    protected string $resourceRouteName = 'api:stations:queue:record';

    public function __construct(
        private readonly StationQueueApiGenerator $queueApiGenerator,
        private readonly StationQueueRepository $queueRepo,
        private readonly QueueLogCache $queueLogCache,
        Serializer $serializer,
        ValidatorInterface $validator
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $qb = $this->queueRepo->getUnplayedBaseQuery($station);

        $searchPhrase = Types::stringOrNull($request->getQueryParam('searchPhrase'), true);
        if (null !== $searchPhrase) {
            $qb->andWhere('(sm.title LIKE :query OR sm.artist LIKE :query OR sm.text LIKE :query)')
                ->setParameter('query', '%' . $searchPhrase . '%');
        }

        // Playlist Groups filtering: a specific playlist (by id), a specific group (by name,
        // matched against the recorded playlist_chain), or "any track queued via a group".
        $filterPlaylistId = Types::intOrNull($request->getQueryParam('filter_playlist_id'));
        if (null !== $filterPlaylistId) {
            $qb->andWhere('sp.id = :filterPlaylistId')
                ->setParameter('filterPlaylistId', $filterPlaylistId);
        }

        $filterGroup = Types::stringOrNull($request->getQueryParam('filter_group'), true);
        if (null !== $filterGroup) {
            $qb->andWhere('sq.playlist_chain LIKE :filterGroup')
                ->setParameter('filterGroup', '%"' . $filterGroup . '"%');
        }

        if (Types::bool($request->getQueryParam('filter_via_group'))) {
            $qb->andWhere('sq.playlist_chain IS NOT NULL');
        }

        return $this->listPaginatedFromQuery(
            $request,
            $response,
            $qb->getQuery()
        );
    }

    protected function viewRecord(object $record, ServerRequest $request): array
    {
        $isInternal = $request->isInternal();
        $router = $request->getRouter();

        $row = $this->queueApiGenerator->__invoke($record);

        $apiResponse = new StationQueueDetailed();
        $apiResponse->sent_to_autodj = $record->sent_to_autodj;
        $apiResponse->is_played = $record->is_played;
        $apiResponse->autodj_custom_uri = $record->autodj_custom_uri;
        $apiResponse->log = $this->queueLogCache->getLog($record);

        // Expose media type so UIs (e.g. the linear log viewer) can filter by
        // content category. Only categories that are actually derivable from
        // persisted queue data are used here -- 'promo', 'jingle', and 'podcast'
        // are deliberately NOT produced: nothing in this codebase tags a queue
        // row with those categories (clock wheel slot types like Promo/Ad exist,
        // but aren't persisted per-queue-row, and podcast episodes never enter
        // the AutoDJ queue at all), so a UI filter for them would be permanently
        // dead. AI DJ-generated clips are distinguished from genuine remote
        // stream URLs by path, matching the convention already used by
        // AiDjQueueListener::hasUpcomingDjClip().
        $apiResponse->media_type = match(true) {
            $record->autodj_custom_uri !== null
                && str_contains($record->autodj_custom_uri, 'ai_dj') => 'talk',
            $record->autodj_custom_uri !== null     => 'stream',
            $record->top_of_hour_legal_id           => 'id',
            $record->media !== null                 => $record->media->type ?? 'music',
            default                                 => 'music',
        };

        $apiResponse->links = [
            'self' => $router->fromHere(
                $this->resourceRouteName,
                ['id' => $record->id],
                [],
                !$isInternal
            ),
        ];

        return [
            ...get_object_vars($row),
            ...get_object_vars($apiResponse),
        ];
    }

    public function clearAction(
        ServerRequest $request,
        Response $response
    ): ResponseInterface {
        $station = $request->getStation();
        $this->queueRepo->clearUpcomingQueue($station);

        return $response->withJson(Status::deleted());
    }
}

