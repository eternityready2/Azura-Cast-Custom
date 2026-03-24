<template>
    <card-page>
        <template #header>
            <div class="row align-items-center">
                <div class="col-md-7">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 pe-3">
                            <album-art :src="podcast.art"/>
                        </div>
                        <div class="flex-fill">
                            <h2 class="card-title">
                                {{ podcast.title }}
                            </h2>
                            <h4 class="card-subtitle">
                                {{ $gettext('Episodes') }}
                            </h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-5 text-end">
                    <stations-common-quota
                        ref="$quota"
                        :quota-url="quotaUrl"
                    />
                </div>
            </div>
        </template>
        <template
            v-if="podcastIsPlaylist"
            #info
        >
            <p class="card-text">
                {{
                    $gettext('This podcast is automatically synchronized with a playlist. Episodes cannot be manually added or removed via this panel.')
                }}
            </p>
        </template>
        <template #actions>
            <router-link
                class="btn btn-secondary"
                :to="{name: 'stations:podcasts:index'}"
            >
                <icon-bi-chevron-left/>

                {{ $gettext('All Podcasts') }}
            </router-link>

            <add-button
                v-if="podcastIsManual"
                :text="$gettext('Add Episode')"
                @click="doCreate"
            />
        </template>

        <podcast-rss-feed-panel
            v-if="podcastIsImport"
            :podcast-id="podcast.id"
            @imported="relist"
        />

        <episodes-toolbar
            :batch-url="podcast.links.batch"
            :selected-items="selectedItems"
            :podcast-is-manual="podcastIsManual"
            @relist="relist"
            @batch-edit="doBatchEdit"
        />

        <data-table
            id="station_podcast_episodes"
            ref="$dataTable"
            selectable
            paginated
            select-fields
            :fields="fields"
            :provider="episodesTableProvider"
            :row-selectable="rowSelectableFn"
            :skip-refresh-on-sort="podcastIsImport"
            @row-selected="onRowSelected"
        >
            <template #cell(art)="{item}">
                <album-art v-if="item.art !== null" :src="item.art"/>
            </template>
            <template #cell(title)="{item}">
                <h5 class="m-0">
                    {{ item.title }}
                    <span
                        v-if="item._feedCatalogOnly"
                        class="badge text-bg-secondary ms-1"
                    >{{ $gettext('RSS catalog') }}</span>
                </h5>
                <div v-if="item.is_published">
                    <a
                        :href="item.links.public"
                        target="_blank"
                    >{{ $gettext('Public Page') }}</a>
                </div>
                <div
                    v-else
                    class="badges"
                >
                    <span class="badge text-bg-info">
                        {{ $gettext('Unpublished') }}
                    </span>
                </div>
            </template>
            <template #cell(media)="{item}">
                <template v-if="item.media">
                    <span>{{ item.media.original_name }}</span>
                    <br>
                    <small>{{ item.media.length_text }}</small>
                </template>
                <template v-else-if="item.playlist_media">
                    <span>{{ item.playlist_media.text }}</span>
                </template>
                <template v-else>
                    &nbsp;
                </template>
            </template>
            <template #cell(is_published)="{item}">
                <span v-if="item.is_published">
                    {{ $gettext('Yes') }}
                </span>
                <span v-else>
                    {{ $gettext('No') }}
                </span>
            </template>
            <template #cell(explicit)="{item}">
                <span
                    v-if="item.explicit"
                    class="badge text-bg-danger"
                >{{ $gettext('Explicit') }}</span>
                <span v-else>&nbsp;</span>
            </template>
            <template #cell(actions)="{item}">
                <div class="btn-group btn-group-sm">
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="!item.links?.self || item._feedCatalogOnly"
                        :title="item._feedCatalogOnly ? $gettext('Import this episode from the RSS catalog above first.') : undefined"
                        @click="doEdit(item.links!.self)"
                    >
                        {{ $gettext('Edit') }}
                    </button>
                    <button
                        v-if="podcastIsManual"
                        type="button"
                        class="btn btn-danger"
                        @click="doDelete(item.links.self)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </template>
        </data-table>
    </card-page>

    <edit-modal
        ref="$editEpisodeModal"
        :podcast="podcast"
        :create-url="podcast.links.episodes"
        @relist="relist"
    />

    <batch-edit-modal
        ref="$batchEditModal"
        :id="podcast.id"
        :batch-url="podcast.links.batch"
        :selected-items="selectedItems"
        @relist="relist"
    />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import EditModal from "~/components/Stations/Podcasts/EpisodeEditModal.vue";
import AlbumArt from "~/components/Common/AlbumArt.vue";
import StationsCommonQuota from "~/components/Stations/Common/Quota.vue";
import {useTranslate} from "~/vendor/gettext";
import {computed, shallowRef, toRef, useTemplateRef, type ComputedRef} from "vue";
import AddButton from "~/components/Common/AddButton.vue";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete.ts";
import {ApiPodcast} from "~/entities/ApiInterfaces.ts";
import useHasEditModal from "~/functions/useHasEditModal.ts";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import CardPage from "~/components/Common/CardPage.vue";
import EpisodesToolbar from "~/components/Stations/Podcasts/EpisodesToolbar.vue";
import PodcastRssFeedPanel from "~/components/Stations/Podcasts/PodcastRssFeedPanel.vue";
import BatchEditModal from "~/components/Stations/Podcasts/BatchEditModal.vue";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {
    isImportEpisodeRowSelectable,
    useImportPodcastFeedMergedProvider,
    type ImportPodcastEpisodeTableRow,
} from "~/functions/dataTable/useImportPodcastFeedMergedProvider.ts";
import {DataTableItemProvider} from "~/functions/useHasDatatable.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import IconBiChevronLeft from "~icons/bi/chevron-left";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const props = defineProps<{
    podcast: Required<ApiPodcast>
}>();

const podcast = toRef(props, 'podcast');

const {getStationApiUrl} = useApiRouter();
const quotaUrl = getStationApiUrl('/quota/station_podcasts');

const {$gettext} = useTranslate();

const {formatTimestampAsDateTime} = useStationDateTimeFormatter();

type Row = ImportPodcastEpisodeTableRow

const fields: DataTableField<Row>[] = [
    {
        key: 'art',
        label: $gettext('Art'),
        sortable: false,
        class: 'shrink pe-0',
        selectable: true
    },
    {
        key: 'title',
        label: $gettext('Episode'),
        sortable: false
    },
    {
        key: 'media',
        label: $gettext('File Name'),
        sortable: false
    },
    {
        key: 'is_published',
        label: $gettext('Is Published'),
        visible: false,
        sortable: false,
        selectable: true
    },
    {
        key: 'publish_at',
        label: $gettext('Publish At'),
        formatter: (_col, _key, item) => formatTimestampAsDateTime(item.publish_at ?? null),
        sortable: true,
        selectable: true
    },
    {
        key: 'explicit',
        label: $gettext('Explicit'),
        sortable: true,
        selectable: true
    },
    {
        key: 'season_number',
        label: $gettext('Season Number'),
        visible: false,
        sortable: true,
        selectable: true
    },
    {
        key: 'episode_number',
        label: $gettext('Episode Number'),
        visible: false,
        sortable: true,
        selectable: true
    },
    {
        key: 'actions',
        label: $gettext('Actions'),
        sortable: false,
        class: 'shrink'
    }
];

const standardEpisodesUrl = computed(() =>
    podcast.value.source === 'import' ? null : podcast.value.links.episodes
);

const standardEpisodesProvider = useApiItemProvider<Row>(
    standardEpisodesUrl,
    queryKeyWithStation(
        [
            QueryKeys.StationPodcasts,
            computed(() => podcast.value.id),
            'episodes',
        ]
    ),
);

const feedItemsApiUrl = computed(() =>
    podcast.value.source === 'import'
        ? getStationApiUrl(`/podcast/${podcast.value.id}/feed-items`).value
        : null
);

const importEpisodesUrl = computed(() =>
    podcast.value.source === 'import' ? podcast.value.links.episodes : null
);

const importFeedMergedProvider = useImportPodcastFeedMergedProvider(
    importEpisodesUrl,
    feedItemsApiUrl,
    queryKeyWithStation([
        QueryKeys.StationPodcasts,
        computed(() => podcast.value.id),
        'episodes',
        'import-feed-merged',
    ])
);

const episodesTableProvider: ComputedRef<DataTableItemProvider<Row>> = computed(() =>
    podcast.value.source === 'import' ? importFeedMergedProvider : standardEpisodesProvider
);

const rowSelectableFn = computed(() =>
    podcast.value.source === 'import' ? isImportEpisodeRowSelectable : undefined
);

const relistRefresh = () => {
    if (podcast.value.source === 'import') {
        return importFeedMergedProvider.refresh();
    }
    return standardEpisodesProvider.refresh();
};

const podcastIsManual = computed(() => {
    return podcast.value?.source == 'manual';
});

const podcastIsPlaylist = computed(() => podcast.value?.source === 'playlist');

const podcastIsImport = computed(() => podcast.value?.source === 'import');

const $quota = useTemplateRef('$quota');

const relist = () => {
    $quota.value?.update();
    void relistRefresh();
};

const $editEpisodeModal = useTemplateRef('$editEpisodeModal');

const {doCreate, doEdit} = useHasEditModal($editEpisodeModal);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Episode?'),
    () => relist()
);

const selectedItems = shallowRef<ImportPodcastEpisodeTableRow[]>([]);

const onRowSelected = (rows: Row[]) => {
    selectedItems.value = rows;
};

const $batchEditModal = useTemplateRef('$batchEditModal');

const {show: showBatchEditModal} = useHasModal($batchEditModal);

const doBatchEdit = () => {
    showBatchEditModal();
};
</script>
