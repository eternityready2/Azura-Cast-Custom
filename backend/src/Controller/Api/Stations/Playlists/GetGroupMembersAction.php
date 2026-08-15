<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylistGroupMember;
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
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
        new OA\Parameter(
            name: 'id',
            description: 'Playlist group ID',
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
final readonly class GetGroupMembersAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private StationPlaylistGroupMemberRepository $memberRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        /** @var string $id */
        $id = $params['id'];

        $group = $this->playlistRepo->requireForStation($id, $request->getStation());
        if (PlaylistSources::Group !== $group->source) {
            throw new ValidationException('This playlist is not a group.');
        }

        $members = array_map(
            static fn(StationPlaylistGroupMember $member): array => [
                'id' => $member->id,
                'playlist_id' => $member->playlist->id,
                'name' => $member->playlist->name,
                'position' => $member->position,
                'source' => $member->playlist->source->value,
                'order' => $member->playlist->order->value,
                'consecutive_plays' => $member->consecutive_plays,
                'play_full_cycle' => $member->play_full_cycle,
                'supports_full_cycle' => PlaylistSources::Songs === $member->playlist->source
                    && PlaylistOrders::Random !== $member->playlist->order,
            ],
            $this->memberRepo->getMembers($group)
        );

        return $response->withJson($members);
    }
}
