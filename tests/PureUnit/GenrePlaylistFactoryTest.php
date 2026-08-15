<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Station;
use App\Media\GenrePlaylistFactory;
use PHPUnit\Framework\TestCase;

final class GenrePlaylistFactoryTest extends TestCase
{
    public function testCreatesDisabledShuffleSongsPlaylist(): void
    {
        $station = new Station();
        $playlist = (new GenrePlaylistFactory())->create($station, 'Rock');

        self::assertSame($station, $playlist->station);
        self::assertSame('Rock', $playlist->name);
        self::assertSame(PlaylistSources::Songs, $playlist->source);
        self::assertSame(PlaylistTypes::Standard, $playlist->type);
        self::assertSame(PlaylistOrders::Shuffle, $playlist->order);
        self::assertFalse($playlist->is_enabled);
    }
}
