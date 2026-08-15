<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\StationPlaylist;

/**
 * Just-in-time Smart Block sync gate used by {@see \App\Radio\AutoDJ\QueueBuilder}
 * immediately before it picks a track from a playlist.
 *
 * Smart Block membership is normally kept up to date by a recurring background task
 * ({@see \App\Sync\Task\CheckSmartBlockPlaylistsTask}), but that task can run minutes
 * behind a criteria change or a fresh media upload. Rather than let AutoDJ pick from a
 * stale membership list (or attempt to play a Smart Block that currently matches no
 * media at all), QueueBuilder calls {@see self::prepare()} right before selection so
 * the block is synced on demand and empty blocks are skipped for that pick.
 */
final class SmartBlockPlaybackPreparer
{
    /**
     * Playlist ID => whether it had usable media after being prepared this cycle.
     * Reset at the start of every queue-build pass via {@see self::beginQueueBuild()}
     * so a Smart Block referenced multiple times in one build (e.g. via a Playlist
     * Group) is only synced once, instead of re-querying its criteria on every pick.
     *
     * @var array<int, bool>
     */
    private array $preparedPlaylists = [];

    public function __construct(
        private readonly SmartBlockSyncer $syncer,
    ) {
    }

    /**
     * Clears the per-cycle prepared cache. Must be called once at the start of each
     * AutoDJ queue-build pass, before any playlist selection happens.
     */
    public function beginQueueBuild(): void
    {
        $this->preparedPlaylists = [];
    }

    /**
     * Prepares a playlist for playback selection. Non-Smart-Block playlists always
     * pass through untouched. Smart Block playlists are synced against their live
     * criteria (once per queue-build cycle); the pick should be skipped if the sync
     * confirms the block currently has no matching media.
     *
     * Fails open on sync errors: playback is never blocked by this check failing, so
     * a Smart Block falls back to its last-known (possibly slightly stale) membership
     * rather than being skipped outright.
     */
    public function prepare(StationPlaylist $playlist): bool
    {
        if (!$playlist->is_smart_block) {
            return true;
        }

        $playlistId = $playlist->id;

        if (isset($this->preparedPlaylists[$playlistId])) {
            return $this->preparedPlaylists[$playlistId];
        }

        try {
            $result = $this->syncer->sync($playlist);
            $hasMedia = $result['total'] > 0;
        } catch (\Throwable) {
            // Fail-safe: don't let a sync error take a Smart Block out of rotation.
            // Fall back to whatever membership it already has on disk.
            $hasMedia = $playlist->media_items->count() > 0;
        }

        $this->preparedPlaylists[$playlistId] = $hasMedia;

        return $hasMedia;
    }
}
