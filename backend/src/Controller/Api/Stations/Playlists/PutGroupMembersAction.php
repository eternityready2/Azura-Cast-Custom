<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\Playlists;

use App\Controller\SingleActionInterface;
use App\Entity\Enums\PlaylistGroupAllowedRequests;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistRepository;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroup;
use App\Exception\ValidationException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use App\Utilities\Types;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Put(
    path: '/station/{station_id}/playlist/{id}/members',
    operationId: 'putPlaylistGroupMembers',
    summary: 'Set the member playlists of the specified playlist group.',
    tags: [OpenApi::TAG_STATIONS_PLAYLISTS]
)]
final readonly class PutGroupMembersAction implements SingleActionInterface
{
    public function __construct(
        private StationPlaylistRepository $playlistRepo,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $record = $this->playlistRepo->requireForStation((string)$params['id'], $station);

        if (PlaylistSources::Playlists !== $record->source) {
            throw new ValidationException('This playlist is not a playlist group.');
        }

        /** @var array<array{id?:mixed,weight?:mixed,consecutive_plays?:mixed,play_full_cycle?:mixed,allowed_requests?:mixed}> $members */
        $members = Types::array($request->getParam('members'));

        foreach ($members as $member) {
            $memberId = Types::int($member['id'] ?? null);
            if ($memberId === $record->id) {
                throw new ValidationException('A playlist group cannot contain itself.');
            }

            $memberPlaylist = $this->playlistRepo->findForStation($memberId, $station);
            if (!$memberPlaylist instanceof StationPlaylist) {
                throw new ValidationException(sprintf('Playlist %d does not belong to this station.', $memberId));
            }

            if ($this->wouldCreateCircularReference($record, $memberPlaylist)) {
                throw new ValidationException(
                    sprintf('Adding playlist "%s" would create a circular reference.', $memberPlaylist->name)
                );
            }
        }

        $this->entityManager->createQuery(
            <<<'DQL'
                DELETE FROM App\Entity\StationPlaylistGroup spg
                WHERE spg.playlist_group = :playlistGroup
            DQL
        )->setParameter('playlistGroup', $record)
            ->execute();

        foreach ($members as $member) {
            $memberId = Types::int($member['id'] ?? null);
            $memberPlaylist = $this->playlistRepo->findForStation($memberId, $station);
            if (!$memberPlaylist instanceof StationPlaylist) {
                continue;
            }

            $relation = new StationPlaylistGroup($memberPlaylist, $record);
            $relation->weight = max(0, Types::int($member['weight'] ?? 0));
            $relation->consecutive_plays = max(0, Types::int($member['consecutive_plays'] ?? 0));
            $relation->play_full_cycle = Types::bool($member['play_full_cycle'] ?? false);

            $allowedRequests = PlaylistGroupAllowedRequests::tryFrom(
                Types::stringOrNull($member['allowed_requests'] ?? null) ?? ''
            ) ?? PlaylistGroupAllowedRequests::Any;

            if (
                PlaylistGroupAllowedRequests::Playlist === $allowedRequests
                && !in_array($memberPlaylist->source, [PlaylistSources::Songs, PlaylistSources::Playlists], true)
            ) {
                $allowedRequests = PlaylistGroupAllowedRequests::Any;
            }

            $relation->allowed_requests = $allowedRequests;
            $this->entityManager->persist($relation);
        }

        $this->entityManager->flush();
        return $response->withJson(['success' => true]);
    }

    private function wouldCreateCircularReference(
        StationPlaylist $group,
        StationPlaylist $candidate
    ): bool {
        if (PlaylistSources::Playlists !== $candidate->source) {
            return false;
        }

        foreach ($candidate->playlists as $relation) {
            $child = $relation->playlist;
            if ($child->id === $group->id) {
                return true;
            }

            if ($this->wouldCreateCircularReference($group, $child)) {
                return true;
            }
        }

        return false;
    }
}
