<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\MediaCategories;

use App\Cache\MediaListCache;
use App\Controller\Api\Stations\AbstractStationApiCrudController;
use App\Entity\StationMediaCategory;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[
    OA\Get(
        path: '/station/{station_id}/media-categories',
        operationId: 'getMediaCategories',
        summary: 'List all media categories for a station.',
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: StationMediaCategory::class))
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/media-categories',
        operationId: 'addMediaCategory',
        summary: 'Create a new media category.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: StationMediaCategory::class)),
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED)],
        responses: [
            new OpenApi\Response\Success(content: new OA\JsonContent(ref: StationMediaCategory::class)),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/media-category/{id}',
        operationId: 'getMediaCategory',
        summary: 'Get a single media category.',
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(content: new OA\JsonContent(ref: StationMediaCategory::class)),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/media-category/{id}',
        operationId: 'editMediaCategory',
        summary: 'Update a media category.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: StationMediaCategory::class)),
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/media-category/{id}',
        operationId: 'deleteMediaCategory',
        summary: 'Delete a media category.',
        tags: [OpenApi::TAG_STATIONS_MEDIA],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
]
final class MediaCategoriesController extends AbstractStationApiCrudController
{
    protected string $entityClass = StationMediaCategory::class;
    protected string $resourceRouteName = 'api:stations:media-category';

    public function __construct(
        Serializer $serializer,
        ValidatorInterface $validator,
        private readonly MediaListCache $mediaListCache,
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $query = $this->em->createQuery(
            'SELECT e FROM App\Entity\StationMediaCategory e
             WHERE e.station = :station
             ORDER BY e.name ASC'
        )->setParameter('station', $station);

        return $this->listPaginatedFromQuery($request, $response, $query);
    }

    public function createAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $resp = parent::createAction($request, $response, $params);
        $this->mediaListCache->clearCache($request->getStation()->media_storage_location);
        return $resp;
    }

    public function editAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $resp = parent::editAction($request, $response, $params);
        $this->mediaListCache->clearCache($request->getStation()->media_storage_location);
        return $resp;
    }

    public function deleteAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $resp = parent::deleteAction($request, $response, $params);
        $this->mediaListCache->clearCache($request->getStation()->media_storage_location);
        return $resp;
    }
}
