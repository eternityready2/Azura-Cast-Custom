<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\CustomField;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\StationMedia;
use App\Exception;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/playlist/{id}/smart-block',
    operationId: 'getStationPlaylistSmartBlock',
    summary: 'Get the Smart Block criteria for the specified playlist, plus a live preview of currently matching tracks.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'Playlist ID',
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
)]
final readonly class GetSmartBlockAction implements SingleActionInterface
{
    /** Number of sample matching tracks returned for the live preview panel. */
    private const int PREVIEW_SAMPLE_SIZE = 25;

    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private StationPlaylistSmartBlockCriteriaRepository $criteriaRepo,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        /** @var string $id */
        $id = $params['id'];

        $station = $request->getStation();
        $record = $this->playlistRepo->requireForStation($id, $station);

        if (PlaylistSources::Songs !== $record->source) {
            throw new Exception(__('Smart Blocks are only available for Song-Based playlists.'));
        }

        $matchingMedia = ($record->is_smart_block && $record->smart_block_criteria->count() > 0)
            ? $this->criteriaRepo->getMatchingMedia($record)
            : [];

        $matchingDuration = array_sum(
            array_map(static fn (StationMedia $media) => $media->length, $matchingMedia)
        );

        $preview = array_map(
            static fn (StationMedia $media) => [
                'id' => $media->id,
                'title' => $media->title,
                'artist' => $media->artist,
                'album' => $media->album,
                'genre' => $media->genre,
                'length' => $media->length,
            ],
            array_slice($matchingMedia, 0, self::PREVIEW_SAMPLE_SIZE)
        );

        $customFields = $this->em->createQueryBuilder()
            ->select(['cf.id', 'cf.name', 'cf.short_name'])
            ->from(CustomField::class, 'cf')
            ->orderBy('cf.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $response->withJson(
            [
                'id' => $record->id,
                'name' => $record->name,
                'is_enabled' => $record->is_enabled,
                'weight' => $record->weight,
                'is_smart_block' => $record->is_smart_block,
                'smart_block_type' => $record->smart_block_type->value,
                'smart_block_match_type' => $record->smart_block_match_type->value,
                'smart_block_limit' => $record->smart_block_limit,
                'smart_block_limit_type' => $record->smart_block_limit_type->value,
                'smart_block_sort_order' => $record->smart_block_sort_order->value,
                'smart_block_avoid_duplicates' => $record->smart_block_avoid_duplicates,
                'criteria' => $record->smart_block_criteria->toArray(),
                'current_member_count' => $record->media_items->count(),
                'matching_count' => count($matchingMedia),
                'matching_duration_seconds' => $matchingDuration,
                'preview' => $preview,
                'available_custom_fields' => $customFields,
            ]
        );
    }
}
