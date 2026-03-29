<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Podcasts;

use App\Controller\SingleActionInterface;
use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use League\Flysystem\StorageAttributes;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/podcasts/media-folders',
    operationId: 'getStationPodcastMediaFolders',
    summary: 'List station media directories for choosing where imported podcast files are stored.',
    tags: [OpenApi::TAG_STATIONS_PODCASTS],
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
final readonly class ListPodcastMediaFoldersAction implements SingleActionInterface
{
    public function __construct(
        private StationFilesystems $stationFilesystems
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $fsMedia = $this->stationFilesystems->getMediaFilesystem($station);

        $fsIterator = $fsMedia->listContents('/', true)->filter(
            fn(StorageAttributes $attrs) => $attrs->isDir() && !StationFilesystems::isDotFile($attrs->path())
        )->sortByPath();

        $directories = [
            [
                'path' => '',
                'name' => '/ (' . __('Media library root') . ')',
            ],
        ];

        /** @var StorageAttributes $dir */
        foreach ($fsIterator->getIterator() as $dir) {
            $directories[] = [
                'path' => $dir->path(),
                'name' => '/' . $dir->path(),
            ];
        }

        return $response->withJson(
            [
                'directories' => $directories,
            ]
        );
    }
}
