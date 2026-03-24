<template>
    <tab
        :label="$gettext('Source')"
        :item-header-class="tabClass"
    >
        <div class="row g-3">
            <form-group-multi-check
                id="edit_form_source"
                class="col-md-12"
                :field="r$.source"
                :options="sourceOptions"
                stacked
                radio
                :label="$gettext('Source')"
            />
        </div>

        <section
            v-show="form.source === 'playlist'"
            class="card mb-3"
            role="region"
        >
            <div class="card-header text-bg-primary">
                <h2 class="card-title">
                    {{ $gettext('Playlist-Based Podcast') }}
                </h2>
            </div>
            <div class="card-body">
                <p>
                    {{
                        $gettext('Playlist-based podcasts will automatically sync with the contents of a playlist, creating new podcast episodes for any media added to the playlist.')
                    }}
                </p>

                <loading :loading="playlistsLoading">
                    <div class="row g-3 mb-3">
                        <form-group-select
                            id="form_edit_playlist_id"
                            class="col-md-12"
                            :field="r$.playlist_id"
                            :options="playlistOptions"
                            :label="$gettext('Select Playlist')"
                        />

                        <form-group-checkbox
                            id="form_edit_playlist_auto_publish"
                            class="col-md-12"
                            :field="r$.playlist_auto_publish"
                            :label="$gettext('Automatically Publish New Episodes')"
                            :description="$gettext('Whether new episodes should be marked as published or held for review as unpublished.')"
                        />
                    </div>
                </loading>
            </div>
        </section>

        <section
            v-show="form.source === 'manual' || form.source === 'import'"
            class="card mb-3"
            role="region"
        >
            <div class="card-header text-bg-secondary">
                <h2 class="card-title">
                    {{ $gettext('Episode File Folder') }}
                </h2>
            </div>
            <div class="card-body">
                <p>
                    {{
                        $gettext('Choose where episode files are stored. To use the station Media library, pick an existing folder below—files are saved there with no extra auto-generated paths.')
                    }}
                </p>
                <div class="row g-3 mb-3">
                    <form-group-select
                        id="form_edit_episode_storage_type"
                        class="col-md-12"
                        :field="r$.episode_storage_type"
                        :options="episodeStorageTypeOptions"
                        :label="$gettext('Store episode files in')"
                    />
                    <loading :loading="mediaFoldersLoading">
                        <form-group-select
                            v-show="form.episode_storage_type === 'media'"
                            id="form_edit_media_folder_path"
                            class="col-md-12"
                            :field="r$.media_folder_path"
                            :options="mediaFolderSelectOptions"
                            :label="$gettext('Media folder')"
                            :description="$gettext('Existing folders from your station Media library. Episodes appear there; link them to playlists from the Media or Playlists pages if needed.')"
                        />
                    </loading>
                </div>
            </div>
        </section>

        <section
            v-show="form.source === 'import'"
            class="card mb-3"
            role="region"
        >
            <div class="card-header text-bg-primary">
                <h2 class="card-title">
                    {{ $gettext('Import from RSS/Feed') }}
                </h2>
            </div>
            <div class="card-body">
                <p>
                    {{ $gettext('Episodes will be automatically downloaded from the feed. You can replace old episodes with new ones by keeping only the last N episodes.') }}
                </p>

                <div class="row g-3 mb-3">
                    <form-group-field
                        id="form_edit_feed_url"
                        class="col-md-12"
                        :field="r$.feed_url"
                        :label="$gettext('Feed URL')"
                        :description="$gettext('RSS or Atom feed URL (e.g. https://example.com/feed.xml)')"
                        input-trim
                    />

                    <form-group-select
                        id="form_edit_rss_background_sync"
                        v-model="rssBackgroundSyncMode"
                        class="col-md-12"
                        :options="rssBackgroundSyncOptions"
                        :label="$gettext('Background RSS sync')"
                        :description="$gettext('Manual sync from the podcast list always works. This only controls whether the scheduled task fetches the feed automatically.')"
                    />

                    <form-group-field
                        v-show="rssBackgroundSyncMode === 'before_air'"
                        id="form_edit_import_sync_before_hours"
                        class="col-md-12"
                        :field="r$.import_sync_before_hours"
                        type="number"
                        :min="1"
                        :max="168"
                        :label="$gettext('Hours before scheduled playlist start')"
                        :description="$gettext('When this podcast has a linked playlist with a schedule, import runs only inside this window before the next start. If there is no linked playlist, the server still imports on every scheduled run (same as “Every scheduled run”).')"
                    />

                    <form-group-field
                        id="form_edit_auto_keep_episodes"
                        class="col-md-12"
                        :field="r$.auto_keep_episodes"
                        type="number"
                        :min="0"
                        :label="$gettext('Keep Last N Episodes')"
                        :description="$gettext('After each import, keep only the N newest episodes (0 = keep all). Use 1 for a single latest episode, or a higher number for a rolling window.')"
                    />

                </div>
            </div>
        </section>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import Tab from "~/components/Common/Tab.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import {useTranslate} from "~/vendor/gettext.ts";
import {computed, onMounted, ref, shallowRef, watch} from "vue";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import {ApiFormSimpleOptions} from "~/entities/ApiInterfaces.ts";
import {storeToRefs} from "pinia";
import {useStationsPodcastsForm} from "~/components/Stations/Podcasts/PodcastForm/form.ts";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";

type MediaFolderRow = {
    path: string,
    name: string
};

type RssBackgroundSyncMode = 'off' | 'every' | 'before_air';

const {r$, form} = storeToRefs(useStationsPodcastsForm());

const tabClass = useFormTabClass(computed(() => r$.value.$groups.sourceTab));

const {$gettext} = useTranslate();

const sourceOptions = [
    {
        value: 'manual',
        text: $gettext('Manually Add Episodes'),
        description: $gettext('Create podcast episodes independent of your station\'s media collection.')
    },
    {
        value: 'playlist',
        text: $gettext('Synchronize with Playlist'),
        description: $gettext('Automatically create new podcast episodes when media is added to a specified playlist.')
    },
    {
        value: 'import',
        text: $gettext('Import from RSS/Feed'),
        description: $gettext('Auto-download and import podcast episodes from an RSS or Atom feed URL.')
    }
];

const playlistsLoading = ref<boolean>(true);
const playlistOptions = shallowRef<ApiFormSimpleOptions>([]);

const mediaFoldersLoading = ref<boolean>(true);
const mediaFolderRows = shallowRef<MediaFolderRow[]>([]);

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const playlistsApiUrl = getStationApiUrl('/podcasts/playlists');
const mediaFoldersApiUrl = getStationApiUrl('/podcasts/media-folders');

const loadPlaylists = async () => {
    try {
        const {data} = await axios.get<ApiFormSimpleOptions>(playlistsApiUrl.value);
        playlistOptions.value = data;
    } finally {
        playlistsLoading.value = false;
    }
};

const loadMediaFolders = async () => {
    try {
        const {data} = await axios.get<{ directories: MediaFolderRow[] }>(mediaFoldersApiUrl.value);
        mediaFolderRows.value = data.directories;
    } finally {
        mediaFoldersLoading.value = false;
    }
};

onMounted(() => {
    void loadPlaylists();
    void loadMediaFolders();
});

const mediaFolderSelectOptions = computed<ApiFormSimpleOptions>(() =>
    mediaFolderRows.value.map((d) => ({
        value: d.path,
        text: d.name
    }))
);

const episodeStorageTypeOptions = [
    { value: 'podcast', text: $gettext('Podcast folder (default)') },
    { value: 'media', text: $gettext('Station media folder') }
];

const rssBackgroundSyncOptions = [
    {
        value: 'off' as const,
        text: $gettext('Off (manual sync only)'),
        description: $gettext('Do not fetch the feed on the scheduled task.')
    },
    {
        value: 'every' as const,
        text: $gettext('Every scheduled run'),
        description: $gettext('Fetch and import on each sync run (typically every few minutes).')
    },
    {
        value: 'before_air' as const,
        text: $gettext('Only within N hours before air'),
        description: $gettext('Requires a linked playlist with a schedule; imports only inside the window before the next start.')
    }
];

const rssBackgroundSyncMode = computed<RssBackgroundSyncMode>({
    get(): RssBackgroundSyncMode {
        const f = form.value;
        if (!f.auto_import_enabled) {
            return 'off';
        }
        const h = f.import_sync_before_hours;
        if (h !== null && h !== undefined && h > 0) {
            return 'before_air';
        }
        return 'every';
    },
    set(mode: RssBackgroundSyncMode) {
        const f = form.value;
        if (mode === 'off') {
            f.auto_import_enabled = false;
            f.import_sync_before_hours = null;
        } else if (mode === 'every') {
            f.auto_import_enabled = true;
            f.import_sync_before_hours = null;
        } else {
            f.auto_import_enabled = true;
            if (f.import_sync_before_hours === null || f.import_sync_before_hours < 1) {
                f.import_sync_before_hours = 5;
            }
        }
    }
});

/** If user lowers hours below 1 while in before-air mode, treat as “every run”. */
watch(
    () => form.value.import_sync_before_hours,
    (h) => {
        if (!form.value.auto_import_enabled) {
            return;
        }
        if (h !== null && h !== undefined && h < 1) {
            form.value.import_sync_before_hours = null;
        }
    }
);
</script>
