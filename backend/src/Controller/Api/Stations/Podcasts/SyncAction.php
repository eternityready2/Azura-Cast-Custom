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

#[OA\Post(
    path: '/station/{station_id}/podcast/{podcast_id}/sync',
    operationId: 'syncPodcastFeed',
    summary: 'Trigger RSS feed import for this podcast now.',
    description: 'Runs the same import as the scheduled task (feed URL, auto-download). Only applies when source is "import".',
    tags: [OpenApi::TAG_STATIONS_PODCASTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'podcast_id',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string')
        ),
    ],
    responses: [
        new OpenApi\Response\Success(description: 'Sync started/completed'),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class SyncAction implements SingleActionInterface
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
                'message' => 'Podcast source must be "Import from RSS/Feed" to sync.',
            ], 400);
        }

        if (empty($podcast->feed_url)) {
            return $response->withJson([
                'success' => false,
                'message' => 'Podcast has no feed URL set.',
            ], 400);
        }

        $this->importTask->runForPodcast($podcast);

        return $response->withJson(['success' => true, 'message' => 'Sync completed.']);
    }
}
