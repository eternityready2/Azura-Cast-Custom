<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Enums\PlaylistSources;

final class GenrePlaylistPlanner
{
    /**
     * @param array<int, array{id: int, path: string, genre: ?string}> $media
     * @param array<int, array{id: int, name: string, source: PlaylistSources}> $playlists
     * @return array{
     *     entries: array<int, array{
     *         name: string,
     *         media_ids: int[],
     *         files: string[],
     *         media_count: int,
     *         status: 'create'|'reuse'|'conflict',
     *         playlist_id: ?int,
     *         conflict_source: ?string
     *     }>,
     *     skipped_files: string[],
     *     skipped_count: int
     * }
     */
    public function plan(array $media, array $playlists): array
    {
        $groupedMedia = [];
        $skippedFiles = [];

        foreach ($media as $item) {
            $genre = self::cleanGenre($item['genre']);
            if (null === $genre) {
                $skippedFiles[] = $item['path'];
                continue;
            }

            $genreKey = self::normalizeName($genre);
            if (!isset($groupedMedia[$genreKey])) {
                $groupedMedia[$genreKey] = [
                    'name' => self::toPlaylistName($genre),
                    'media_ids' => [],
                    'files' => [],
                ];
            }

            $groupedMedia[$genreKey]['media_ids'][] = $item['id'];
            $groupedMedia[$genreKey]['files'][] = $item['path'];
        }

        $existingByName = [];
        foreach ($playlists as $playlist) {
            $existingByName[self::normalizeName(self::toPlaylistName($playlist['name']))][] = $playlist;
        }

        $entriesByPlaylistName = [];
        foreach ($groupedMedia as $group) {
            $playlistNameKey = self::normalizeName($group['name']);

            if (isset($entriesByPlaylistName[$playlistNameKey])) {
                $entriesByPlaylistName[$playlistNameKey]['media_ids'] = array_merge(
                    $entriesByPlaylistName[$playlistNameKey]['media_ids'],
                    $group['media_ids']
                );
                $entriesByPlaylistName[$playlistNameKey]['files'] = array_merge(
                    $entriesByPlaylistName[$playlistNameKey]['files'],
                    $group['files']
                );
                $entriesByPlaylistName[$playlistNameKey]['media_count'] = count(
                    $entriesByPlaylistName[$playlistNameKey]['media_ids']
                );
                continue;
            }

            $matchingPlaylists = $existingByName[$playlistNameKey] ?? [];
            $incompatiblePlaylist = null;
            $compatiblePlaylist = null;

            foreach ($matchingPlaylists as $playlist) {
                if (PlaylistSources::Songs === $playlist['source']) {
                    $compatiblePlaylist ??= $playlist;
                } else {
                    $incompatiblePlaylist ??= $playlist;
                }
            }

            if (null !== $incompatiblePlaylist) {
                $status = 'conflict';
                $playlistId = null;
                $conflictSource = $incompatiblePlaylist['source']->value;
            } elseif (null !== $compatiblePlaylist) {
                $status = 'reuse';
                $playlistId = $compatiblePlaylist['id'];
                $conflictSource = null;
            } else {
                $status = 'create';
                $playlistId = null;
                $conflictSource = null;
            }

            $entriesByPlaylistName[$playlistNameKey] = [
                'name' => $group['name'],
                'media_ids' => $group['media_ids'],
                'files' => $group['files'],
                'media_count' => count($group['media_ids']),
                'status' => $status,
                'playlist_id' => $playlistId,
                'conflict_source' => $conflictSource,
            ];
        }

        return [
            'entries' => array_values($entriesByPlaylistName),
            'skipped_files' => $skippedFiles,
            'skipped_count' => count($skippedFiles),
        ];
    }

    private static function cleanGenre(?string $genre): ?string
    {
        if (null === $genre) {
            return null;
        }

        $cleaned = preg_replace('/\s+/u', ' ', trim($genre));
        return (null === $cleaned || '' === $cleaned) ? null : $cleaned;
    }

    private static function toPlaylistName(string $name): string
    {
        return mb_substr(str_replace(';', ':', $name), 0, 200, 'UTF-8');
    }

    private static function normalizeName(string $name): string
    {
        $cleaned = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return mb_strtolower($cleaned, 'UTF-8');
    }
}
