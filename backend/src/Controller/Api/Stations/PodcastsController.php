<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Controller\Api\AbstractApiCrudController;
use App\Controller\Api\Traits\CanSearchResults;
use App\Entity\Api\Podcast as ApiPodcast;
use App\Entity\ApiGenerator\PodcastApiGenerator;
use App\Entity\Enums\PodcastEpisodeStorageType;
use App\Entity\Enums\PodcastImportStrategy;
use App\Entity\Podcast;
use App\Entity\PodcastCategory;
use App\Entity\Repository\PodcastRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Service\Flow\UploadedFile;
use App\Utilities\Types;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends AbstractApiCrudController<Podcast> */
#[
    OA\Get(
        path: '/station/{station_id}/podcasts',
        operationId: 'getPodcasts',
        summary: 'List all current podcasts.',
        tags: [OpenApi::TAG_STATIONS_PODCASTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: ApiPodcast::class)
                )
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Post(
        path: '/station/{station_id}/podcasts',
        operationId: 'addPodcast',
        summary: 'Create a new podcast.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: ApiPodcast::class)
        ),
        tags: [OpenApi::TAG_STATIONS_PODCASTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: ApiPodcast::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Get(
        path: '/station/{station_id}/podcast/{id}',
        operationId: 'getPodcast',
        summary: 'Retrieve details for a single podcast.',
        tags: [OpenApi::TAG_STATIONS_PODCASTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Podcast ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(
                content: new OA\JsonContent(ref: ApiPodcast::class)
            ),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Put(
        path: '/station/{station_id}/podcast/{id}',
        operationId: 'editPodcast',
        summary: 'Update details of a single podcast.',
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(ref: ApiPodcast::class)
        ),
        tags: [OpenApi::TAG_STATIONS_PODCASTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Podcast ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OpenApi\Response\Success(),
            new OpenApi\Response\AccessDenied(),
            new OpenApi\Response\NotFound(),
            new OpenApi\Response\GenericError(),
        ]
    ),
    OA\Delete(
        path: '/station/{station_id}/podcast/{id}',
        operationId: 'deletePodcast',
        summary: 'Delete a single podcast.',
        tags: [OpenApi::TAG_STATIONS_PODCASTS],
        parameters: [
            new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
            new OA\Parameter(
                name: 'id',
                description: 'Podcast ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
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
final class PodcastsController extends AbstractApiCrudController
{
    use CanSearchResults;

    protected string $entityClass = Podcast::class;
    protected string $resourceRouteName = 'api:stations:podcast';

    public function __construct(
        private readonly PodcastRepository $podcastRepository,
        private readonly PodcastApiGenerator $podcastApiGen,
        Serializer $serializer,
        ValidatorInterface $validator,
    ) {
        parent::__construct($serializer, $validator);
    }

    public function listAction(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();

        $queryBuilder = $this->em->createQueryBuilder()
            ->select('p, pc')
            ->from(Podcast::class, 'p')
            ->leftJoin('p.categories', 'pc')
            ->where('p.storage_location = :storageLocation')
            ->orderBy('p.title', 'ASC')
            ->setParameter('storageLocation', $station->podcasts_storage_location);

        $queryBuilder = $this->searchQueryBuilder(
            $request,
            $queryBuilder,
            [
                'p.title',
            ]
        );

        return $this->listPaginatedFromQuery($request, $response, $queryBuilder->getQuery());
    }

    protected function getRecord(ServerRequest $request, array $params): ?object
    {
        /** @var string $id */
        $id = $params['podcast_id'];

        return $this->podcastRepository->fetchPodcastForStation(
            $request->getStation(),
            $id
        );
    }

    protected function createRecord(ServerRequest $request, array $data): object
    {
        $station = $request->getStation();

        /** @var Podcast $record */
        $record = $this->editRecord(
            $data,
            new Podcast($station->podcasts_storage_location)
        );

        if (!empty($data['artwork_file'])) {
            $artwork = UploadedFile::fromArray($data['artwork_file'], $station->getRadioTempDir());
            $this->podcastRepository->writePodcastArt(
                $record,
                $artwork->readAndDeleteUploadedFile()
            );

            $this->em->persist($record);
            $this->em->flush();
        }

        return $record;
    }

    protected function deleteRecord(object $record): void
    {
        $this->podcastRepository->delete($record);
    }

    protected function viewRecord(object $record, ServerRequest $request): ApiPodcast
    {
        $isInternal = $request->isInternal();
        $router = $request->getRouter();

        $return = $this->podcastApiGen->__invoke($record, $request);

        $baseRouteParams = [
            'station_id' => $request->getStation()->id,
            'podcast_id' => $record->id,
        ];

        $artRouteParams = $baseRouteParams;
        if (0 !== $return->art_updated_at) {
            $artRouteParams['timestamp'] = $return->art_updated_at;
        }

        $return->art = $router->named(
            routeName: 'api:stations:podcast:art',
            routeParams: $artRouteParams,
            absolute: !$isInternal
        );

        $return->links = [
            ...$return->links,
            'self' => $router->named(
                routeName: $this->resourceRouteName,
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
            'art' => $router->named(
                routeName: 'api:stations:podcast:art',
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
            'episodes' => $router->named(
                routeName: 'api:stations:podcast:episodes',
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
            'episode_new_art' => $router->named(
                routeName: 'api:stations:podcast:episodes:new-art',
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
            'episode_new_media' => $router->named(
                routeName: 'api:stations:podcast:episodes:new-media',
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
            'batch' => $router->named(
                routeName: 'api:stations:podcast:batch',
                routeParams: $baseRouteParams,
                absolute: !$isInternal
            ),
        ];

        return $return;
    }

    /**
     * @param mixed[] $data
     * @param Podcast|null $record
     * @param array $context
     *
     * @return Podcast
     */
    protected function fromArray($data, $record = null, array $context = []): object
    {
        /** @var null|string[] $newCategories */
        $newCategories = null;
        if (isset($data['categories'])) {
            $newCategories = Types::arrayOrNull($data['categories']);
            unset($data['categories']);
        }

        if (isset($data['playlist_id'])) {
            $data['playlist'] = $data['playlist_id'];
            unset($data['playlist_id']);
        }

        $episodeStorageType = PodcastEpisodeStorageType::Podcast;
        if (isset($data['episode_storage_type'])) {
            $v = Types::stringOrNull($data['episode_storage_type']);
            $episodeStorageType = $v === 'media'
                ? PodcastEpisodeStorageType::Media
                : PodcastEpisodeStorageType::Podcast;
            unset($data['episode_storage_type']);
        }

        if (array_key_exists('media_folder_path', $data)) {
            $raw = $data['media_folder_path'];
            $data['media_folder_path'] = (is_string($raw) && trim($raw) === '') ? null : $raw;
        }

        $importStrategySet = false;
        $importStrategy = PodcastImportStrategy::LatestSingle;
        if (array_key_exists('import_strategy', $data)) {
            $v = Types::stringOrNull($data['import_strategy']);
            $importStrategy = ($v === PodcastImportStrategy::BackfillAll->value)
                ? PodcastImportStrategy::BackfillAll
                : PodcastImportStrategy::LatestSingle;
            unset($data['import_strategy']);
            $importStrategySet = true;
        }

        $importCronTouched = array_key_exists('import_cron', $data);
        $importCronValue = null;
        if ($importCronTouched) {
            $raw = Types::stringOrNull($data['import_cron']);
            $importCronValue = ($raw === null || trim($raw) === '') ? null : trim($raw);
            unset($data['import_cron']);
        }

        $autoKeepEpisodes = null;
        if (array_key_exists('auto_keep_episodes', $data)) {
            $autoKeepEpisodes = max(0, min(32767, Types::int($data['auto_keep_episodes'])));
            unset($data['auto_keep_episodes']);
        }

        $boolFields = ['auto_import_enabled', 'playlist_auto_publish', 'is_enabled', 'explicit'];
        foreach ($boolFields as $boolKey) {
            if (array_key_exists($boolKey, $data) && !is_bool($data[$boolKey])) {
                $data[$boolKey] = Types::bool($data[$boolKey], broadenValidBools: true);
            }
        }

        $record = parent::fromArray($data, $record, $context);

        $record->episode_storage_type = $episodeStorageType;
        if (null !== $autoKeepEpisodes) {
            $record->auto_keep_episodes = $autoKeepEpisodes;
        }
        if ($importStrategySet) {
            $record->import_strategy = $importStrategy;
        }
        if ($importCronTouched) {
            $record->import_cron = $importCronValue;
        }

        if (null !== $newCategories) {
            $categories = $record->categories;
            if ($categories->count() > 0) {
                foreach ($categories as $existingCategories) {
                    $this->em->remove($existingCategories);
                }
                $categories->clear();
            }

            foreach ($newCategories as $category) {
                $podcastCategory = new PodcastCategory($record, $category);
                $this->em->persist($podcastCategory);

                $categories->add($podcastCategory);
            }
        }

        return $record;
    }
}
