<?php

declare(strict_types=1);

namespace App\Service\PlaylistConfiguration;

use App\Container\EntityManagerAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\CustomField;
use App\Entity\Enums\PlaylistGroupAllowedRequests;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Enums\RecurrenceEndType;
use App\Entity\Enums\RecurrenceMonthlyPattern;
use App\Entity\Enums\RecurrenceType;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistFolder;
use App\Entity\StationPlaylistGroup;
use App\Entity\StationPlaylistMedia;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Entity\StationSchedule;
use App\Entity\StorageLocation;
use App\Service\PlaylistConfiguration\Schema\MediaEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistConfigurationSchema;
use App\Service\PlaylistConfiguration\Schema\PlaylistEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistFolderEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistMediaEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistMemberEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistScheduleEntry;
use App\Service\PlaylistConfiguration\Schema\PlaylistSmartBlockCriterionEntry;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class PlaylistConfigurationImporter
{
    use EntityManagerAwareTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly StationMediaRepository $mediaRepo,
        private readonly DummyMediaGenerator $dummyMediaGenerator
    ) {
    }

    public function import(
        array $dump,
        Station $station,
        ?string $namePrefix = null
    ): ImportSummary {
        $version = (int)($dump['schema_version'] ?? 0);
        if (PlaylistConfigurationSchema::VERSION !== $version) {
            throw new RuntimeException(
                sprintf(
                    'Unsupported playlist configuration dump version "%d"; expected "%d".',
                    $version,
                    PlaylistConfigurationSchema::VERSION
                )
            );
        }

        $schema = PlaylistConfigurationSchema::fromArray($dump, $station);
        $summary = new ImportSummary();

        $this->em->wrapInTransaction(
            function () use ($station, $schema, $namePrefix, $summary): void {
                $this->createPlaylists($station, $schema, $summary, $namePrefix);
                $this->populatePlaylistContents($station, $schema, $summary);
                $this->em->flush();

                $this->recreateGroupMemberships($schema, $summary);
                $this->em->flush();
            }
        );

        return $summary;
    }

    private function createPlaylists(
        Station $station,
        PlaylistConfigurationSchema $schema,
        ImportSummary $summary,
        ?string $namePrefix
    ): void {
        foreach ($schema->playlistEntries as $entry) {
            $playlist = new StationPlaylist($station);

            $name = !empty($entry->name) ? $entry->name : 'Imported Playlist';
            if (!empty($namePrefix)) {
                $name = $namePrefix . $name;
            }

            $playlist->name = $name;
            $playlist->description = $entry->description;
            $playlist->source = $entry->source;

            if (in_array($entry->source, [PlaylistSources::Songs, PlaylistSources::Playlists], true)) {
                $playlist->type = $entry->type;
            }

            $playlist->order = $entry->order;
            $playlist->weight = $entry->weight ?: StationPlaylist::DEFAULT_WEIGHT;
            $playlist->is_enabled = $entry->isEnabled;
            $playlist->is_jingle = $entry->isJingle;
            $playlist->avoid_duplicates = $entry->avoidDuplicates;
            $playlist->include_in_requests = $entry->includeInRequests;
            $playlist->include_in_on_demand = $entry->includeInOnDemand;
            $playlist->play_per_songs = $entry->playPerSongs;
            $playlist->play_per_minutes = $entry->playPerMinutes;
            $playlist->play_per_hour_minute = $entry->playPerHourMinute;
            $playlist->backend_options = $entry->backendOptions;
            $playlist->remote_url = $entry->remoteUrl;
            $playlist->remote_type = $entry->remoteType;
            $playlist->remote_buffer = $entry->remoteBuffer;

            $playlist->rotation_goal_days = $entry->rotationGoalDays;
            $playlist->aging_threshold_days = $entry->agingThresholdDays;
            $playlist->crossfade_profile = $entry->crossfadeProfile;

            $playlist->is_sponsor = $entry->isSponsor;
            $playlist->sponsor_name = $entry->sponsorName;
            $playlist->sponsor_guaranteed_plays_per_day = $entry->sponsorGuaranteedPlaysPerDay;
            $playlist->sponsor_contract_start = $this->parseDateTime($entry->sponsorContractStart);
            $playlist->sponsor_contract_end = $this->parseDateTime($entry->sponsorContractEnd);

            $playlist->is_smart_block = $entry->isSmartBlock;
            $playlist->smart_block_match_type = $entry->smartBlockMatchType;
            $playlist->smart_block_limit = $entry->smartBlockLimit;
            $playlist->smart_block_limit_type = $entry->smartBlockLimitType;
            $playlist->smart_block_type = $entry->smartBlockType;
            $playlist->smart_block_sort_order = $entry->smartBlockSortOrder;
            $playlist->smart_block_avoid_duplicates = $entry->smartBlockAvoidDuplicates;

            $this->em->persist($playlist);
            $summary->playlistsByRef[$entry->ref] = $playlist;
        }
    }

    private function populatePlaylistContents(
        Station $station,
        PlaylistConfigurationSchema $schema,
        ImportSummary $summary
    ): void {
        $mediaIndex = $this->indexMedia($schema->mediaEntries);

        foreach ($schema->playlistEntries as $entry) {
            $playlist = $summary->playlistsByRef[$entry->ref];

            $folderByRef = $this->createFolders(
                $station,
                $playlist,
                $summary,
                $entry->folders
            );

            $this->createMediaItems(
                $station->media_storage_location,
                $playlist,
                $summary,
                $entry->media,
                $mediaIndex,
                $folderByRef,
            );

            $this->createSchedules($playlist, $summary, $entry->schedules);
            $this->createSmartBlockCriteria($playlist, $summary, $entry->smartBlockCriteria);
        }
    }

    /**
     * @param MediaEntry[] $mediaEntries
     * @return array<string, MediaEntry>
     */
    private function indexMedia(array $mediaEntries): array
    {
        $index = [];
        foreach ($mediaEntries as $media) {
            if (!empty($media->ref)) {
                $index[$media->ref] = $media;
            }
        }
        return $index;
    }

    /**
     * @param PlaylistFolderEntry[] $folderEntries
     * @return array<string, StationPlaylistFolder>
     */
    private function createFolders(
        Station $station,
        StationPlaylist $playlist,
        ImportSummary $summary,
        array $folderEntries
    ): array {
        $folderByRef = [];

        foreach ($folderEntries as $folderEntry) {
            $folder = new StationPlaylistFolder($station, $playlist, $folderEntry->path);
            $this->em->persist($folder);

            $folderByRef[$folderEntry->ref] = $folder;
            $summary->foldersCreated++;
        }

        return $folderByRef;
    }

    /**
     * @param PlaylistMediaEntry[] $playlistMediaEntries
     * @param array<string, MediaEntry> $mediaIndex
     * @param array<string, StationPlaylistFolder> $folderByRef
     */
    private function createMediaItems(
        StorageLocation $storageLocation,
        StationPlaylist $playlist,
        ImportSummary $summary,
        array $playlistMediaEntries,
        array $mediaIndex,
        array $folderByRef
    ): void {
        foreach ($playlistMediaEntries as $playlistMediaEntry) {
            $mediaRef = $playlistMediaEntry->mediaRef;
            $mediaEntry = $mediaIndex[$mediaRef] ?? null;

            if ($mediaEntry === null) {
                $summary->warnings[] = sprintf('Media ref "%s" is not defined in the dump; skipping.', $mediaRef);
                continue;
            }

            $media = $this->resolveMedia($storageLocation, $mediaEntry, $summary, $mediaRef);
            if ($media === null) {
                continue;
            }

            $folder = null;
            if ($playlistMediaEntry->folderRef !== null) {
                $folder = $folderByRef[$playlistMediaEntry->folderRef] ?? null;
            }

            $spm = new StationPlaylistMedia($playlist, $media, $folder);
            $spm->weight = $playlistMediaEntry->weight;
            $this->em->persist($spm);

            $summary->mediaItemsCreated++;
        }
    }

    private function resolveMedia(
        StorageLocation $storageLocation,
        MediaEntry $mediaEntry,
        ImportSummary $summary,
        string $mediaRef
    ): ?StationMedia {
        if (isset($summary->mediaByRef[$mediaRef])) {
            return $summary->mediaByRef[$mediaRef];
        }

        $media = null;
        if (!empty($mediaEntry->uniqueId)) {
            $media = $this->mediaRepo->findByUniqueId($mediaEntry->uniqueId, $storageLocation);
        }

        $media ??= $this->mediaRepo->findByPath($mediaEntry->path, $storageLocation);

        if ($media !== null) {
            $summary->mediaRelinked++;
        } else {
            $media = $this->generateDummyMedia($storageLocation, $mediaEntry, $summary);
            if ($media === null) {
                return null;
            }
        }

        $summary->mediaByRef[$mediaRef] = $media;
        return $media;
    }

    private function generateDummyMedia(
        StorageLocation $storageLocation,
        MediaEntry $mediaEntry,
        ImportSummary $summary
    ): ?StationMedia {
        try {
            $media = $this->dummyMediaGenerator->generate($storageLocation, $mediaEntry);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Failed to generate dummy media during playlist import.',
                [
                    'path' => $mediaEntry->path,
                    'exception' => $exception,
                ]
            );

            $summary->warnings[] = sprintf(
                'Failed to generate dummy media for "%s": %s',
                $mediaEntry->path,
                $exception->getMessage()
            );
            return null;
        }

        if ($media !== null) {
            $summary->mediaGenerated++;
            return $media;
        }

        $summary->warnings[] = sprintf('Could not create media for "%s"; skipping.', $mediaEntry->path);
        return null;
    }

    /**
     * @param PlaylistScheduleEntry[] $scheduleEntries
     */
    private function createSchedules(
        StationPlaylist $playlist,
        ImportSummary $summary,
        array $scheduleEntries
    ): void {
        foreach ($scheduleEntries as $scheduleEntry) {
            $schedule = new StationSchedule($playlist);
            $schedule->start_time = $scheduleEntry->startTime;
            $schedule->end_time = $scheduleEntry->endTime;
            $schedule->days = $scheduleEntry->days;
            $schedule->start_date = $scheduleEntry->startDate;
            $schedule->end_date = $scheduleEntry->endDate;
            $schedule->loop_once = $scheduleEntry->loopOnce;
            $schedule->prevent_requests = $scheduleEntry->preventRequests;
            $schedule->strict_start = $scheduleEntry->strictStart;
            $schedule->is_emergency = $scheduleEntry->isEmergency;
            $schedule->recurrence_type = $this->tryEnum(RecurrenceType::class, $scheduleEntry->recurrenceType);
            $schedule->recurrence_interval = max(1, $scheduleEntry->recurrenceInterval);
            $schedule->recurrence_monthly_pattern = $this->tryEnum(
                RecurrenceMonthlyPattern::class,
                $scheduleEntry->recurrenceMonthlyPattern
            );
            $schedule->recurrence_monthly_day = $scheduleEntry->recurrenceMonthlyDay;
            $schedule->recurrence_monthly_week = $scheduleEntry->recurrenceMonthlyWeek;
            $schedule->recurrence_monthly_day_of_week = $scheduleEntry->recurrenceMonthlyDayOfWeek;
            $schedule->recurrence_end_type = $this->tryEnum(
                RecurrenceEndType::class,
                $scheduleEntry->recurrenceEndType
            ) ?? RecurrenceEndType::Never;
            $schedule->recurrence_end_after = $scheduleEntry->recurrenceEndAfter;
            $schedule->recurrence_end_date = $scheduleEntry->recurrenceEndDate;

            $this->em->persist($schedule);
            $summary->schedulesCreated++;
        }
    }

    /**
     * @param PlaylistSmartBlockCriterionEntry[] $criteriaEntries
     */
    private function createSmartBlockCriteria(
        StationPlaylist $playlist,
        ImportSummary $summary,
        array $criteriaEntries
    ): void {
        foreach ($criteriaEntries as $entry) {
            $field = SmartBlockCriteriaField::tryFrom($entry->field);
            $comparison = SmartBlockCriteriaComparison::tryFrom($entry->comparison);

            if ($field === null || $comparison === null) {
                $summary->warnings[] = sprintf(
                    'Smart Block criterion on "%s" has an unsupported field or comparison; skipping.',
                    $playlist->name
                );
                continue;
            }

            $criterion = new StationPlaylistSmartBlockCriteria($playlist);
            $criterion->field = $field;
            $criterion->comparison = $comparison;
            $criterion->value = $entry->value;
            $criterion->value2 = $entry->value2;
            $criterion->weight = $entry->weight;

            if ($entry->customFieldName !== null) {
                /** @var CustomField|null $customField */
                $customField = $this->em->getRepository(CustomField::class)->findOneBy([
                    'name' => $entry->customFieldName,
                ]);

                if ($customField === null) {
                    $summary->warnings[] = sprintf(
                        'Custom field "%s" used by Smart Block "%s" does not exist on this installation.',
                        $entry->customFieldName,
                        $playlist->name
                    );
                    continue;
                }

                $criterion->custom_field = $customField;
            }

            $this->em->persist($criterion);
            $summary->smartBlockCriteriaCreated++;
        }
    }

    private function recreateGroupMemberships(
        PlaylistConfigurationSchema $schema,
        ImportSummary $summary
    ): void {
        $memberRefsByPlaylist = $this->mapMemberRefsByPlaylist($schema->playlistEntries);

        foreach ($schema->playlistEntries as $entry) {
            if (empty($entry->members)) {
                continue;
            }

            $this->createMembers($summary, $entry->ref, $entry->members, $memberRefsByPlaylist);
        }
    }

    /**
     * @param PlaylistEntry[] $playlistEntries
     * @return array<string, string[]>
     */
    private function mapMemberRefsByPlaylist(array $playlistEntries): array
    {
        $map = [];
        foreach ($playlistEntries as $entry) {
            $map[$entry->ref] = array_map(
                static fn(PlaylistMemberEntry $member): string => $member->playlistRef,
                $entry->members
            );
        }
        return $map;
    }

    /**
     * @param PlaylistMemberEntry[] $memberEntries
     * @param array<string, string[]> $memberRefsByPlaylist
     */
    private function createMembers(
        ImportSummary $summary,
        string $playlistRef,
        array $memberEntries,
        array $memberRefsByPlaylist
    ): void {
        $container = $summary->playlistsByRef[$playlistRef];

        foreach ($memberEntries as $memberEntry) {
            $memberRef = $memberEntry->playlistRef;
            $member = $summary->playlistsByRef[$memberRef] ?? null;

            if ($member === null) {
                $summary->warnings[] = sprintf(
                    'Playlist group "%s" references unknown member "%s"; skipping.',
                    $container->name,
                    $memberRef
                );
                continue;
            }

            if ($member === $container) {
                $summary->warnings[] = sprintf(
                    'Playlist group "%s" cannot contain itself; skipping.',
                    $container->name
                );
                continue;
            }

            if ($this->wouldCreateCircularReference($playlistRef, $memberRef, $memberRefsByPlaylist)) {
                $summary->warnings[] = sprintf(
                    'Adding "%s" to "%s" would create a circular reference; skipping.',
                    $member->name,
                    $container->name
                );
                continue;
            }

            $playlistGroup = new StationPlaylistGroup($member, $container);
            $playlistGroup->weight = $memberEntry->weight;
            $playlistGroup->consecutive_plays = max(0, $memberEntry->consecutivePlays);
            $playlistGroup->play_full_cycle = $memberEntry->playFullCycle;

            $allowedRequests = $memberEntry->allowedRequests;
            if (
                PlaylistGroupAllowedRequests::Playlist === $allowedRequests
                && !in_array($member->source, [PlaylistSources::Songs, PlaylistSources::Playlists], true)
            ) {
                $allowedRequests = PlaylistGroupAllowedRequests::Any;
            }
            $playlistGroup->allowed_requests = $allowedRequests;

            $this->em->persist($playlistGroup);
            $summary->membersCreated++;
        }
    }

    /**
     * @param array<string, string[]> $memberRefsByPlaylist
     * @param array<string, bool> $checkedPlaylistRefs
     */
    private function wouldCreateCircularReference(
        string $containerPlaylistRef,
        string $memberPlaylistRef,
        array $memberRefsByPlaylist,
        array $checkedPlaylistRefs = []
    ): bool {
        if (isset($checkedPlaylistRefs[$memberPlaylistRef])) {
            return false;
        }

        $checkedPlaylistRefs[$memberPlaylistRef] = true;

        foreach ($memberRefsByPlaylist[$memberPlaylistRef] ?? [] as $nestedMemberRef) {
            if ($nestedMemberRef === $containerPlaylistRef) {
                return true;
            }

            if ($this->wouldCreateCircularReference(
                $containerPlaylistRef,
                $nestedMemberRef,
                $memberRefsByPlaylist,
                $checkedPlaylistRefs
            )) {
                return true;
            }
        }

        return false;
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return T|null
     */
    private function tryEnum(string $enumClass, ?string $value): ?\BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $enumClass::tryFrom($value);
    }
}
