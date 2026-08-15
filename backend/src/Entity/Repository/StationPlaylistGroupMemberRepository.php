<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Enums\PlaylistOrders;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistGroupMember;

/**
 * @extends Repository<StationPlaylistGroupMember>
 */
final class StationPlaylistGroupMemberRepository extends Repository
{
    public const int MAX_MEMBERS = 32768;

    protected string $entityClass = StationPlaylistGroupMember::class;

    /** @return list<StationPlaylistGroupMember> */
    public function getMembers(StationPlaylist $group): array
    {
        return $this->repository->findBy(
            ['group' => $group],
            ['position' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * @param list<StationPlaylist> $playlists
     * @param list<array{consecutive_plays: int, play_full_cycle: bool}> $playbackSettings
     * @param array<int, PlaylistOrders> $playlistOrders
     * @return list<StationPlaylistGroupMember>
     */
    public function setMembers(
        StationPlaylist $group,
        array $playlists,
        array $playbackSettings = [],
        array $playlistOrders = [],
    ): array
    {
        if (count($playlists) > self::MAX_MEMBERS) {
            throw new \InvalidArgumentException('A playlist group cannot contain more than 32768 members.');
        }

        return $this->em->wrapInTransaction(
            function () use ($group, $playlists, $playbackSettings, $playlistOrders): array {
                foreach ($playlists as $playlist) {
                    $order = $playlistOrders[$playlist->id] ?? null;
                    if ($order instanceof PlaylistOrders) {
                        $playlist->order = $order;
                        $this->em->persist($playlist);
                    }
                }

                $existingMembers = $this->getMembers($group);
                $membersByPlaylistId = [];

                foreach ($existingMembers as $member) {
                    if ($member->position >= self::MAX_MEMBERS) {
                        throw new \InvalidArgumentException('The playlist group contains an invalid member position.');
                    }

                    $membersByPlaylistId[$member->playlist->id][] = $member;
                    $member->position += self::MAX_MEMBERS;
                }

                $this->em->flush();

                $newMembers = [];
                foreach ($playlists as $position => $playlist) {
                    $playlistId = $playlist->id;
                    $availableMembers = $membersByPlaylistId[$playlistId] ?? [];
                    $member = array_shift($availableMembers);
                    $membersByPlaylistId[$playlistId] = $availableMembers;

                    if (!$member instanceof StationPlaylistGroupMember) {
                        $member = new StationPlaylistGroupMember($group, $playlist, $position);
                        $this->em->persist($member);
                    } else {
                        $member->position = $position;
                    }

                    $settings = $playbackSettings[$position] ?? null;
                    if (null !== $settings) {
                        $member->consecutive_plays = $settings['consecutive_plays'];
                        $member->play_full_cycle = $settings['play_full_cycle'];
                    }

                    $newMembers[] = $member;
                }

                foreach ($membersByPlaylistId as $unusedMembers) {
                    foreach ($unusedMembers as $unusedMember) {
                        $this->em->remove($unusedMember);
                    }
                }

                $group->group_next_position = 0;
                $this->em->persist($group);
                $this->em->flush();

                return $newMembers;
            }
        );
    }

    /** @return list<int> */
    public function getChildPlaylistIds(Station $station): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT DISTINCT IDENTITY(spgm.playlist) AS playlist_id
                FROM App\Entity\StationPlaylistGroupMember spgm
                JOIN spgm.group parentPlaylist
                WHERE parentPlaylist.station = :station
            DQL
        )->setParameter('station', $station)
            ->getScalarResult();

        return array_map(
            static fn(array $row): int => (int)$row['playlist_id'],
            $rows
        );
    }
}
