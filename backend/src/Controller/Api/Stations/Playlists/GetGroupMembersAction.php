<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylistGroup;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/playlist/{id}/members',
    operationId: 'getPlaylistGroupMembers',
    summary: 'List the ordered members of a playlist group.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS]
)]
final readonly class GetGroupMembersAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $group = $this->playlistRepo->requireForStation((string)$params['id'], $request->getStation());
        if (PlaylistSources::Playlists !== $group->source) {
            throw new ValidationException('This playlist is not a group.');
        }

        $members = array_map(
            static fn(StationPlaylistGroup $member): array => [
                'id' => $member->playlist->id,
                'playlist_id' => $member->playlist->id,
                'name' => $member->playlist->name,
                'position' => $member->weight,
                'weight' => $member->weight,
                'source' => $member->playlist->source->value,
                'order' => $member->playlist->order->value,
                'consecutive_plays' => $member->consecutive_plays,
                'play_full_cycle' => $member->play_full_cycle,
                'allowed_requests' => $member->allowed_requests->value,
                'supports_full_cycle' => PlaylistSources::Songs === $member->playlist->source
                    && in_array($member->playlist->order, [PlaylistOrders::Sequential, PlaylistOrders::Shuffle], true),
            ],
            $group->playlists->toArray()
        );

        return $response->withJson($members);
    }
}
