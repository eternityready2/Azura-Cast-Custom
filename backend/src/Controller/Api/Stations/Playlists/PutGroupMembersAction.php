<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistGroupMemberRepository;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/playlist/{id}/members',
    operationId: 'putPlaylistGroupMembers',
    summary: 'Replace the ordered members of a playlist group.',
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
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['members'],
            properties: [
                new OA\Property(
                    property: 'members',
                    type: 'array',
                    items: new OA\Items(
                        required: ['playlist_id', 'consecutive_plays', 'play_full_cycle'],
                        properties: [
                            new OA\Property(
                                property: 'playlist_id',
                                type: 'integer',
                                format: 'int64'
                            ),
                            new OA\Property(
                                property: 'consecutive_plays',
                                type: 'integer',
                                maximum: 65535,
                                minimum: 1,
                                default: 1
                            ),
                            new OA\Property(
                                property: 'play_full_cycle',
                                type: 'boolean',
                                default: false
                            ),
                            new OA\Property(
                                property: 'order',
                                type: 'string',
                                enum: ['shuffle', 'random', 'sequential']
                            ),
                        ],
                        type: 'object'
                    )
                ),
            ],
            type: 'object'
        )
    ),
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
        new OpenApi\Response\NotFound(),
        new OpenApi\Response\GenericError(),
    ]
)]
final readonly class PutGroupMembersAction implements SingleActionInterface
{
    private const int MAX_CONSECUTIVE_PLAYS = 65535;

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
        $station = $request->getStation();

        $group = $this->playlistRepo->requireForStation($id, $station);
        if (PlaylistSources::Group !== $group->source) {
            throw new ValidationException('This playlist is not a group.');
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || (!array_key_exists('members', $body) && !array_key_exists('playlist_ids', $body))) {
            throw new ValidationException('members is required.');
        }

        $memberSettings = [];
        if (array_key_exists('members', $body)) {
            $memberDefinitions = $body['members'];
            if (!is_array($memberDefinitions) || !array_is_list($memberDefinitions)) {
                throw new ValidationException('members must be a list.');
            }

            $playlistIds = [];
            foreach ($memberDefinitions as $memberDefinition) {
                if (!is_array($memberDefinition)) {
                    throw new ValidationException('Every member must be an object.');
                }

                $playlistIds[] = $memberDefinition['playlist_id'] ?? null;
                $consecutivePlays = $memberDefinition['consecutive_plays'] ?? 1;
                $playFullCycle = $memberDefinition['play_full_cycle'] ?? false;
                $order = $memberDefinition['order'] ?? null;

                if (
                    !is_int($consecutivePlays)
                    || $consecutivePlays < 1
                    || $consecutivePlays > self::MAX_CONSECUTIVE_PLAYS
                ) {
                    throw new ValidationException('consecutive_plays must be an integer between 1 and 65535.');
                }
                if (!is_bool($playFullCycle)) {
                    throw new ValidationException('play_full_cycle must be a boolean.');
                }
                if (null !== $order && !is_string($order)) {
                    throw new ValidationException('order must be Sequential, Shuffle or Random.');
                }

                $playlistOrder = null !== $order
                    ? PlaylistOrders::tryFrom($order)
                    : null;
                if (
                    null !== $order
                    && !in_array(
                        $playlistOrder,
                        [PlaylistOrders::Sequential, PlaylistOrders::Shuffle, PlaylistOrders::Random],
                        true
                    )
                ) {
                    throw new ValidationException('order must be Sequential, Shuffle or Random.');
                }

                $memberSettings[] = [
                    'consecutive_plays' => $consecutivePlays,
                    'play_full_cycle' => $playFullCycle,
                    'order' => $playlistOrder,
                ];
            }
        } else {
            $playlistIds = $body['playlist_ids'];
            if (!is_array($playlistIds) || !array_is_list($playlistIds)) {
                throw new ValidationException('playlist_ids must be a list.');
            }
        }
        if (count($playlistIds) > StationPlaylistGroupMemberRepository::MAX_MEMBERS) {
            throw new ValidationException('A playlist group cannot contain more than 32768 members.');
        }

        $playlists = [];
        $playlistOrders = [];
        foreach ($playlistIds as $playlistId) {
            if (!is_int($playlistId) || $playlistId <= 0) {
                throw new ValidationException('Every playlist ID must be a positive integer.');
            }

            $playlist = $this->playlistRepo->findForStation($playlistId, $station);
            if (!$playlist instanceof StationPlaylist) {
                throw new ValidationException('A playlist ID is invalid for this station.');
            }
            if ($playlist->id === $group->id) {
                throw new ValidationException('A playlist group cannot contain itself.');
            }
            if (PlaylistSources::Group === $playlist->source) {
                throw new ValidationException('Nested playlist groups are not supported.');
            }

            $settings = $memberSettings[count($playlists)] ?? null;
            $requestedOrder = $settings['order'] ?? null;
            if ($requestedOrder instanceof PlaylistOrders) {
                if (PlaylistSources::Songs !== $playlist->source) {
                    throw new ValidationException('order can only be changed for song playlists.');
                }

                $existingOrder = $playlistOrders[$playlist->id] ?? null;
                if ($existingOrder instanceof PlaylistOrders && $existingOrder !== $requestedOrder) {
                    throw new ValidationException(
                        'All occurrences of the same playlist must use the same order.'
                    );
                }
                $playlistOrders[$playlist->id] = $requestedOrder;
            }

            $playlists[] = $playlist;
        }

        foreach ($playlists as $position => $playlist) {
            $effectiveOrder = $playlistOrders[$playlist->id] ?? $playlist->order;
            if (
                true === ($memberSettings[$position]['play_full_cycle'] ?? false)
                && (
                    PlaylistSources::Songs !== $playlist->source
                    || PlaylistOrders::Random === $effectiveOrder
                )
            ) {
                throw new ValidationException(
                    'play_full_cycle is only supported for Sequential or Shuffle song playlists.'
                );
            }
        }

        $members = $this->memberRepo->setMembers(
            $group,
            $playlists,
            $memberSettings,
            $playlistOrders
        );

        return $response->withJson(array_map(
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
            $members
        ));
    }
}
