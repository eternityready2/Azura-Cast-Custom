<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration;

use App\Entity\Enums\PlaylistSources;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Service\PlaylistConfiguration\Schema\MediaEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistConfigurationSchema;
use App\Service\PlaylistConfiguration\Schema\PlaylistEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistFolderEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistMediaEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistMemberEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistScheduleEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistSmartBlockCriterionEntry;

final class PlaylistConfigurationExporter
{
    public function exportStationPlaylists(Station $station): PlaylistConfigurationSchema
    {
        return $this->build(
            $station,
            $station->playlists->toArray(),
            PlaylistConfigurationType::STATION
        );
    }

    public function exportPlaylist(StationPlaylist $playlist): PlaylistConfigurationSchema
    {
        $collectedPlaylists = [];
        $this->collectPlaylistAndMembers($playlist, $collectedPlaylists);

        return $this->build(
            $playlist->station,
            array_values($collectedPlaylists),
            PlaylistConfigurationType::PLAYLIST
        );
    }

    /**
     * @param array<int, StationPlaylist> $collectedPlaylists
     */
    private function collectPlaylistAndMembers(
        StationPlaylist $playlist,
        array &$collectedPlaylists
    ): void {
        if (isset($collectedPlaylists[$playlist->id])) {
            return;
        }

        $collectedPlaylists[$playlist->id] = $playlist;

        if (PlaylistSources::Playlists === $playlist->source) {
            foreach ($playlist->playlists as $member) {
                $this->collectPlaylistAndMembers($member->playlist, $collectedPlaylists);
            }
        }
    }

    /**
     * @param StationPlaylist[] $playlists
     */
    private function build(
        Station $station,
        array $playlists,
        PlaylistConfigurationType $type
    ): PlaylistConfigurationSchema {
        $schema = new PlaylistConfigurationSchema(
            type: $type,
            station: $station,
            mediaEntries: [],
            playlistEntries: [],
        );

        $playlistRefs = [];
        foreach ($playlists as $playlist) {
            $playlistRefs[$playlist->id] = $this->uniqueRef(
                StationPlaylist::generateShortName($playlist->name),
                $playlistRefs
            );
        }

        foreach ($playlists as $playlist) {
            $this->exportPlaylistEntry($schema, $playlist, $playlistRefs);
        }

        return $schema;
    }

    /**
     * @param array<int, string> $playlistRefs
     */
    private function exportPlaylistEntry(
        PlaylistConfigurationSchema $schema,
        StationPlaylist $playlist,
        array $playlistRefs,
    ): void {
        $entry = new PlaylistEntry(
            ref: $playlistRefs[$playlist->id],
            name: $playlist->name,
            type: $playlist->type,
            source: $playlist->source,
            order: $playlist->order,
            weight: $playlist->weight,
            isEnabled: $playlist->is_enabled,
            isJingle: $playlist->is_jingle,
            avoidDuplicates: $playlist->avoid_duplicates,
            includeInRequests: $playlist->include_in_requests,
            includeInOnDemand: $playlist->include_in_on_demand,
            playPerSongs: $playlist->play_per_songs,
            playPerMinutes: $playlist->play_per_minutes,
            playPerHourMinute: $playlist->play_per_hour_minute,
            backendOptions: $playlist->backend_options,
            remoteUrl: $playlist->remote_url,
            remoteType: $playlist->remote_type,
            remoteBuffer: $playlist->remote_buffer,
            description: $playlist->description,
            rotationGoalDays: $playlist->rotation_goal_days,
            agingThresholdDays: $playlist->aging_threshold_days,
            crossfadeProfile: $playlist->crossfade_profile,
            isSponsor: $playlist->is_sponsor,
            sponsorName: $playlist->sponsor_name,
            sponsorGuaranteedPlaysPerDay: $playlist->sponsor_guaranteed_plays_per_day,
            sponsorContractStart: $playlist->sponsor_contract_start?->format(DATE_ATOM),
            sponsorContractEnd: $playlist->sponsor_contract_end?->format(DATE_ATOM),
            isSmartBlock: $playlist->is_smart_block,
            smartBlockMatchType: $playlist->smart_block_match_type,
            smartBlockLimit: $playlist->smart_block_limit,
            smartBlockLimitType: $playlist->smart_block_limit_type,
            smartBlockType: $playlist->smart_block_type,
            smartBlockSortOrder: $playlist->smart_block_sort_order,
            smartBlockAvoidDuplicates: $playlist->smart_block_avoid_duplicates,
        );

        $folderRefById = [];
        foreach ($playlist->folders as $index => $folder) {
            $folderRef = 'f' . $index;
            $folderRefById[$folder->id] = $folderRef;

            $entry->folders[] = new PlaylistFolderEntry(
                ref: $folderRef,
                path: $folder->path,
            );
        }

        foreach ($playlist->media_items as $playlistMedia) {
            $media = $playlistMedia->media;
            $mediaId = $media->id;

            if (!isset($schema->mediaEntries[$mediaId])) {
                $schema->mediaEntries[$mediaId] = new MediaEntry(
                    ref: 'm' . count($schema->mediaEntries),
                    path: $media->path,
                    uniqueId: $media->unique_id,
                    length: $media->length,
                    artist: $media->artist,
                    title: $media->title,
                    album: $media->album,
                    genre: $media->genre,
                );
            }

            $folder = $playlistMedia->folder;

            $entry->media[] = new PlaylistMediaEntry(
                mediaRef: $schema->mediaEntries[$mediaId]->ref,
                weight: $playlistMedia->weight,
                folderRef: ($folder !== null) ? ($folderRefById[$folder->id] ?? null) : null,
            );
        }

        foreach ($playlist->schedule_items as $schedule) {
            $entry->schedules[] = new PlaylistScheduleEntry(
                startTime: $schedule->start_time,
                endTime: $schedule->end_time,
                days: array_values($schedule->days),
                startDate: $schedule->start_date,
                endDate: $schedule->end_date,
                loopOnce: $schedule->loop_once,
                preventRequests: $schedule->prevent_requests,
                strictStart: $schedule->strict_start,
                isEmergency: $schedule->is_emergency,
                recurrenceType: $schedule->recurrence_type?->value,
                recurrenceInterval: $schedule->recurrence_interval,
                recurrenceMonthlyPattern: $schedule->recurrence_monthly_pattern?->value,
                recurrenceMonthlyDay: $schedule->recurrence_monthly_day,
                recurrenceMonthlyWeek: $schedule->recurrence_monthly_week,
                recurrenceMonthlyDayOfWeek: $schedule->recurrence_monthly_day_of_week,
                recurrenceEndType: $schedule->recurrence_end_type?->value,
                recurrenceEndAfter: $schedule->recurrence_end_after,
                recurrenceEndDate: $schedule->recurrence_end_date,
            );
        }

        foreach ($playlist->playlists as $playlistGroup) {
            $memberRef = $playlistRefs[$playlistGroup->playlist->id] ?? null;
            if ($memberRef === null) {
                continue;
            }

            $entry->members[] = new PlaylistMemberEntry(
                playlistRef: $memberRef,
                weight: $playlistGroup->weight,
                consecutivePlays: $playlistGroup->consecutive_plays,
                playFullCycle: $playlistGroup->play_full_cycle,
                allowedRequests: $playlistGroup->allowed_requests,
            );
        }

        foreach ($playlist->smart_block_criteria as $criterion) {
            $entry->smartBlockCriteria[] = new PlaylistSmartBlockCriterionEntry(
                field: $criterion->field->value,
                comparison: $criterion->comparison->value,
                value: $criterion->value,
                value2: $criterion->value2,
                weight: $criterion->weight,
                customFieldName: $criterion->custom_field?->name,
            );
        }

        $schema->playlistEntries[] = $entry;
    }

    /**
     * @param string[] $existingRefs
     */
    private function uniqueRef(string $base, array $existingRefs): string
    {
        $base = ('' !== $base) ? $base : 'playlist';

        $ref = $base;
        $suffix = 2;
        while (in_array($ref, $existingRefs, true)) {
            $ref = $base . '_' . $suffix;
            $suffix++;
        }

        return $ref;
    }
}
