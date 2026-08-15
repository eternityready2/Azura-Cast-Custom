<?php

declare(strict_types=1);

namespace PureUnit;

use App\Entity\Enums\PlaylistSources;
use App\Media\GenrePlaylistPlanner;
use PHPUnit\Framework\TestCase;

final class GenrePlaylistPlannerTest extends TestCase
{
    public function testGroupsOneScalarGenreIgnoringCaseAndWhitespace(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [
                ['id' => 1, 'path' => 'one.mp3', 'genre' => ' Rock '],
                ['id' => 2, 'path' => 'two.mp3', 'genre' => "ROCK\t"],
            ],
            []
        );

        self::assertCount(1, $plan['entries']);
        self::assertSame('Rock', $plan['entries'][0]['name']);
        self::assertSame([1, 2], $plan['entries'][0]['media_ids']);
        self::assertSame('create', $plan['entries'][0]['status']);
    }

    public function testSkipsEmptyGenre(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [
                ['id' => 1, 'path' => 'null.mp3', 'genre' => null],
                ['id' => 2, 'path' => 'empty.mp3', 'genre' => '   '],
            ],
            []
        );

        self::assertSame([], $plan['entries']);
        self::assertSame(2, $plan['skipped_count']);
        self::assertSame(['null.mp3', 'empty.mp3'], $plan['skipped_files']);
    }

    public function testCreatesOneEntryForEachDistinctGenre(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [
                ['id' => 1, 'path' => 'rock.mp3', 'genre' => 'Rock'],
                ['id' => 2, 'path' => 'jazz.mp3', 'genre' => 'Jazz'],
            ],
            []
        );

        self::assertSame(['Rock', 'Jazz'], array_column($plan['entries'], 'name'));
        self::assertSame([1, 1], array_column($plan['entries'], 'media_count'));
    }

    public function testReusesCompatibleSongsPlaylist(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [['id' => 1, 'path' => 'song.mp3', 'genre' => 'Rock']],
            [['id' => 10, 'name' => ' rock ', 'source' => PlaylistSources::Songs]]
        );

        self::assertSame('reuse', $plan['entries'][0]['status']);
        self::assertSame(10, $plan['entries'][0]['playlist_id']);
    }

    public function testRejectsIncompatiblePlaylistName(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [['id' => 1, 'path' => 'song.mp3', 'genre' => 'Rock']],
            [['id' => 10, 'name' => 'ROCK', 'source' => PlaylistSources::RemoteUrl]]
        );

        self::assertSame('conflict', $plan['entries'][0]['status']);
        self::assertNull($plan['entries'][0]['playlist_id']);
        self::assertSame(PlaylistSources::RemoteUrl->value, $plan['entries'][0]['conflict_source']);
    }

    public function testDetectsCollisionAfterPlaylistNameSanitization(): void
    {
        $plan = (new GenrePlaylistPlanner())->plan(
            [
                ['id' => 1, 'path' => 'one.mp3', 'genre' => 'Rock;Pop'],
                ['id' => 2, 'path' => 'two.mp3', 'genre' => 'Rock:Pop'],
            ],
            []
        );

        self::assertCount(1, $plan['entries']);
        self::assertSame('Rock:Pop', $plan['entries'][0]['name']);
        self::assertSame([1, 2], $plan['entries'][0]['media_ids']);
    }
}
