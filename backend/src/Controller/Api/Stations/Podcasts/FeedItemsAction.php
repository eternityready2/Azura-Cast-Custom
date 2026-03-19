<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Podcasts;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PodcastSources;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Sync\Task\ImportPodcastFeedsTask;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/podcast/{podcast_id}/feed-items',
    operationId: 'getPodcastFeedItems',
    summary: 'List all items from the RSS/Atom feed with import status.',
    tags: [OpenApi::TAG_STATIONS_PODCASTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(name: 'podcast_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
    ]
)]
final readonly class FeedItemsAction implements SingleActionInterface
{
    public function __construct(
        private ImportPodcastFeedsTask $importTask
    ) {
    }

    public function __invoke(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $podcast = $request->getPodcast();

        if ($podcast->source !== PodcastSources::Import) {
            return $response->withJson([
                'success' => false,
                'message' => 'Only RSS import podcasts have a feed catalog.',
                'items' => [],
            ], 400);
        }

        $result = $this->importTask->getFeedItemsPreview($podcast);

        return $response->withJson([
            'success' => $result['success'],
            'message' => $result['message'] ?? null,
            'items' => $result['items'],
        ]);
    }
}
