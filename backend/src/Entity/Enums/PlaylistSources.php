<?php

declare(strict_types=1);

namespace App\Entity\Enums;

use OpenApi\Attributes as OA;

#[OA\Schema(type: 'string')]
enum PlaylistSources: string
{
    case Songs = 'songs';
    case RemoteUrl = 'remote_url';

    /**
     * A "Playlist Group" -- this playlist's content is a flat, explicitly-ordered sequence
     * of *other* playlists (Clock Wheel-style grouping), rather than songs of its own.
     *
     * Renamed from the original `Playlists = 'playlists'` case when the nested/tree grouping
     * model was replaced with a flat StationPlaylistGroupMember sequence to fix the AutoDJ
     * playback-continuation bug. The value itself also changed (playlists -> group) to match
     * the tested reference implementation exactly; existing rows are converted by migration.
     */
    case Group = 'group';

    /**
     * This playlist's content is pulled live from the station's Request Queue when its
     * rotation slot comes up, rather than from its own media library.
     */
    case Requests = 'requests';
}
