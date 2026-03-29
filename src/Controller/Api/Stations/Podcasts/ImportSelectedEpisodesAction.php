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
    path: '/station/{station_id}/podcast/{podcast_id}/import-selected',
    operationId: 'importSelectedPodcastFeedItems',
    summary: 'Download selected episodes from the RSS feed by key.',
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
final readonly class ImportSelectedEpisodesAction implements SingleActionInterface
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
                'message' => 'Only RSS import podcasts support selective import.',
                'episodes_added' => 0,
                'log' => [],
            ], 400);
        }

        $body = $request->getParsedBody();
        $keys = [];
        if (is_array($body) && isset($body['keys']) && is_array($body['keys'])) {
            foreach ($body['keys'] as $k) {
                if (is_string($k) && $k !== '') {
                    $keys[] = $k;
                }
            }
        }

        $result = $this->importTask->importFeedItemsByKeys($podcast, $keys);

        return $response->withJson([
            'success' => $result['success'],
            'message' => $result['message'] ?? null,
            'episodes_added' => $result['episodes_added'],
            'log' => $result['log'],
        ]);
    }
}
