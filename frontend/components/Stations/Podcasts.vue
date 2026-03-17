<template>
    <loading :loading="propsLoading" lazy>
        <card-page>
            <template #header>
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <h2 class="card-title">
                            {{ $gettext('Podcasts') }}
                        </h2>
                    </div>
                    <div class="col-md-5 text-end">
                        <stations-common-quota
                            ref="$quota"
                            :quota-url="quotaUrl"
                        />
                    </div>
                </div>
            </template>
            <template #actions>
                <add-button
                    :text="$gettext('Add Podcast')"
                    @click="doCreate"
                />
            </template>

            <data-table
                id="station_podcasts"
                ref="$dataTable"
                paginated
                :fields="fields"
                :provider="listItemProvider"
            >
                <template #cell(art)="{item}">
                    <album-art :src="item.art"/>
                </template>
                <template #cell(title)="{item}">
                    <h5 class="m-0">
                        {{ item.title }}
                    </h5>
                    <div v-if="item.is_published && item.is_enabled">
                        <a
                            :href="item.links.public_episodes"
                            target="_blank"
                        >{{ $gettext('Public Page') }}</a> &bull;
                        <a
                            :href="item.links.public_feed"
                            target="_blank"
                        >{{ $gettext('RSS Feed') }}</a>
                    </div>
                    <div class="badges">
                        <span
                            v-if="item.source === 'playlist'"
                            class="badge text-bg-info"
                        >
                            {{ $gettext('Playlist-Based') }}
                        </span>
                        <span
                            v-if="item.source === 'import'"
                            class="badge text-bg-info"
                        >
                            {{ $gettext('RSS Import') }}
                        </span>
                        <span
                            v-if="!item.is_published"
                            class="badge text-bg-info"
                        >
                            {{ $gettext('Unpublished') }}
                        </span>
                        <span
                            v-if="item.explicit"
                            class="badge text-bg-danger"
                        >
                            {{ $gettext('Explicit') }}
                        </span>
                        <span
                            v-if="!item.is_enabled"
                            class="badge text-bg-danger"
                        >
                            {{ $gettext('Disabled') }}
                        </span>
                    </div>
                </template>
                <template #cell(actions)="{item}">
                    <div class="btn-group btn-group-sm">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="doEdit(item.links.self)"
                        >
                            {{ $gettext('Edit') }}
                        </button>
                        <button
                            v-if="item.source === 'import'"
                            type="button"
                            class="btn btn-info"
                            :disabled="syncLoading === item.id"
                            @click="doSync(item)"
                        >
                            {{ syncLoading === item.id ? $gettext('Syncing…') : $gettext('Sync now') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger"
                            @click="doDelete(item.links.self)"
                        >
                            {{ $gettext('Delete') }}
                        </button>
                        <router-link
                            class="btn btn-secondary"
                            :to="{name: 'stations:podcast:episodes', params: {podcast_id: item.id}}"
                        >
                            {{ $gettext('Episodes') }}
                        </router-link>
                    </div>
                </template>
            </data-table>
        </card-page>

        <edit-modal
            ref="$editPodcastModal"
            :create-url="listUrl"
            :new-art-url="newArtUrl"
            :language-options="props!.languageOptions"
            :categories-options="props!.categoriesOptions"
            @relist="relist"
        />
    </loading>
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import EditModal from "~/components/Stations/Podcasts/PodcastEditModal.vue";
import AlbumArt from "~/components/Common/AlbumArt.vue";
import StationsCommonQuota from "~/components/Stations/Common/Quota.vue";
import {useTranslate} from "~/vendor/gettext";
import {shallowRef, useTemplateRef} from "vue";
import AddButton from "~/components/Common/AddButton.vue";
import CardPage from "~/components/Common/CardPage.vue";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete.ts";
import useHasEditModal from "~/functions/useHasEditModal.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {ApiStationsVuePodcastsProps, StorageLocationTypes} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useQuery} from "@tanstack/vue-query";
import Loading from "~/components/Common/Loading.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const quotaUrl = getStationApiUrl(`/quota/${StorageLocationTypes.StationPodcasts}`);
const listUrl = getStationApiUrl('/podcasts');
const newArtUrl = getStationApiUrl('/podcasts/art');
const propsUrl = getStationApiUrl('/vue/podcasts');

const {axios} = useAxios();

const {data: props, isLoading: propsLoading} = useQuery<ApiStationsVuePodcastsProps>({
    queryKey: queryKeyWithStation(
        [
            QueryKeys.StationPodcasts,
            'props'
        ]
    ),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ApiStationsVuePodcastsProps>(propsUrl.value, {signal});
        return data;
    }
});

const {$gettext} = useTranslate();

const fields: DataTableField[] = [
    {key: 'art', label: $gettext('Art'), sortable: false, class: 'shrink pe-0'},
    {key: 'title', label: $gettext('Podcast'), sortable: false},
    {
        key: 'episodes',
        label: $gettext('# Episodes'),
        sortable: false,
    },
    {key: 'actions', label: $gettext('Actions'), sortable: false, class: 'shrink'}
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([
        QueryKeys.StationPodcasts,
        'data'
    ])
);

const {refresh} = listItemProvider;

const $quota = useTemplateRef('$quota');

const relist = () => {
    $quota.value?.update();
    void refresh();
};

const $editPodcastModal = useTemplateRef('$editPodcastModal');

const {doCreate, doEdit} = useHasEditModal($editPodcastModal);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Podcast?'),
    () => relist()
);

const syncLoading = shallowRef<string | null>(null);

const doSync = async (item: { id: string }) => {
    syncLoading.value = item.id;
    try {
        const url = getStationApiUrl(`/podcast/${item.id}/sync`);
        await axios.post(url.value);
        await refresh();
    } finally {
        syncLoading.value = null;
    }
};
</script>
