<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[
    ORM\Entity,
    ORM\Table(
        name: 'station_playlist_group_members',
        uniqueConstraints: [
            new ORM\UniqueConstraint(name: 'uniq_playlist_group_position', columns: ['group_id', 'position']),
        ]
    )
]
final class StationPlaylistGroupMember implements Interfaces\IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'group_members')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationPlaylist $group;

    #[ORM\ManyToOne(fetch: 'EAGER', inversedBy: 'group_memberships')]
    #[ORM\JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StationPlaylist $playlist;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    public int $position;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 1])]
    public int $consecutive_plays = 1;

    #[ORM\Column(options: ['default' => false])]
    public bool $play_full_cycle = false;

    public function __construct(StationPlaylist $group, StationPlaylist $playlist, int $position)
    {
        $this->group = $group;
        $this->playlist = $playlist;
        $this->position = $position;
    }
}
