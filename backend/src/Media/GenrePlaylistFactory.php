<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Entity\StationPlaylist;

final class GenrePlaylistFactory
{
    public function create(Station $station, string $name): StationPlaylist
    {
        $playlist = new StationPlaylist($station);
        $playlist->name = $name;
        $playlist->source = PlaylistSources::Songs;
        $playlist->type = PlaylistTypes::Standard;
        $playlist->order = PlaylistOrders::Shuffle;
        $playlist->is_enabled = false;

        return $playlist;
    }
}
