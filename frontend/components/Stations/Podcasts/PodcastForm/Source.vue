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
                    {{ $gettext('Choose where episode files are stored. Media folder makes episodes available in the Media library and in scheduled playlists.') }}
                </p>
                <div class="row g-3 mb-3">
                    <form-group-select
                        id="form_edit_episode_storage_type"
                        class="col-md-12"
                        :field="r$.episode_storage_type"
                        :options="episodeStorageTypeOptions"
                        :label="$gettext('Store episode files in')"
                    />
                    <form-group-field
                        v-show="form.episode_storage_type === 'media'"
                        id="form_edit_media_folder_path"
                        class="col-md-12"
                        :field="r$.media_folder_path"
                        :label="$gettext('Media subfolder (optional)')"
                        :description="$gettext('Subfolder within station media (e.g. Radio Shows/MyShow). Leave blank for default.')"
                    />
                </div>
            </div>
        </section>

        <section
            v-show="form.source === 'import' && form.episode_storage_type === 'media'"
            class="card mb-3"
            role="region"
        >
            <div class="card-header text-bg-secondary">
                <h2 class="card-title">
                    {{ $gettext('Target Playlist for Episodes') }}
                </h2>
            </div>
            <div class="card-body">
                <p>
                    {{ $gettext('Optionally assign a playlist. New episodes will be added to this playlist for scheduling.') }}
                </p>
                <loading :loading="playlistsLoading">
                    <form-group-select
                        id="form_edit_playlist_id_import"
                        class="col-md-12"
                        :field="r$.playlist_id"
                        :options="playlistOptions"
                        :label="$gettext('Playlist (optional)')"
                    />
                </loading>
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

                    <form-group-checkbox
                        id="form_edit_auto_import_enabled"
                        class="col-md-12"
                        :field="r$.auto_import_enabled"
                        :label="$gettext('Enable Auto-Download')"
                        :description="$gettext('Automatically fetch and import new episodes when the sync task runs.')"
                    />

                    <form-group-field
                        id="form_edit_auto_keep_episodes"
                        class="col-md-12"
                        :field="r$.auto_keep_episodes"
                        type="number"
                        :min="0"
                        :label="$gettext('Keep Last N Episodes')"
                        :description="$gettext('After import, keep only the N newest episodes (0 = keep all). Useful with “Import all from feed”.')"
                    />

                    <form-group-select
                        id="form_edit_import_strategy"
                        class="col-md-12"
                        :field="r$.import_strategy"
                        :options="importStrategyOptions"
                        :label="$gettext('Auto-import mode')"
                        :description="$gettext('Latest only: one episode (newest by date), replaces the previous file. Import all: download every episode not yet in the library.')"
                    />

                    <form-group-field
                        id="form_edit_import_cron"
                        class="col-md-12"
                        :field="r$.import_cron"
                        :label="$gettext('Auto-import schedule (cron)')"
                        :description="$gettext('Leave empty to check the feed about every 15 minutes. Or set a cron, e.g. 30 7 1 * * = 07:30 on the 1st of each month (before your show airs). Ignored when “Sync before air” is set.')"
                        input-trim
                    />

                    <form-group-field
                        id="form_edit_import_sync_before_hours"
                        class="col-md-12"
                        :field="r$.import_sync_before_hours"
                        type="number"
                        :min="0"
                        :max="168"
                        :label="$gettext('Sync N hours before air (optional)')"
                        :description="$gettext('If this podcast has a linked playlist with a schedule: run auto-import only once within this many hours before the next scheduled start (e.g. 5 = about 5 hours before). Leave 0 or empty to use the cron above or every-tick checks.')"
                    />
                </div>
            </div>
        </section>
    </tab>
</template>

<script setup lang="ts">
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import Tab from "~/components/Common/Tab.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import {useTranslate} from "~/vendor/gettext.ts";
import {computed, onMounted, ref, shallowRef} from "vue";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import {ApiFormSimpleOptions} from "~/entities/ApiInterfaces.ts";
import {storeToRefs} from "pinia";
import {useStationsPodcastsForm} from "~/components/Stations/Podcasts/PodcastForm/form.ts";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";

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

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const playlistsApiUrl = getStationApiUrl('/podcasts/playlists');

const loadPlaylists = async () => {
    try {
        const {data} = await axios.get<ApiFormSimpleOptions>(playlistsApiUrl.value);
        playlistOptions.value = data;
    } finally {
        playlistsLoading.value = false;
    }
};

onMounted(loadPlaylists);

const episodeStorageTypeOptions = [
    { value: 'podcast', text: $gettext('Podcast folder (default)') },
    { value: 'media', text: $gettext('Station media folder (for playlists)') }
];

const importStrategyOptions = [
    { value: 'latest_single', text: $gettext('Latest episode only (replace previous)') },
    { value: 'backfill_all', text: $gettext('Import all new episodes from feed') }
];
</script>
