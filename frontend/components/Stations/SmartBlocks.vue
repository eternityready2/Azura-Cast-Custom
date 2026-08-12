<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_smart_blocks"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2
                        id="hdr_smart_blocks"
                        class="card-title"
                    >
                        {{ $gettext('Smart Blocks') }}
                    </h2>
                </div>
                <div class="col-md-4 text-end">
                    <router-link
                        :to="{name: 'stations:playlists:index'}"
                        class="btn btn-sm btn-secondary"
                    >
                        {{ $gettext('Go to Playlists') }}
                    </router-link>
                </div>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted mb-0">
                {{
                    $gettext('A Smart Block automatically fills itself with tracks from your media library that match a set of rules — genre, BPM, mood, or any Custom Field — instead of being built by hand. Schedule it like any other playlist to fill empty airtime automatically. Smart Blocks also show up in your regular Playlists list, tagged accordingly.')
                }}
            </p>
        </div>

        <div
            v-if="loading"
            class="p-5 text-center"
        >
            <div class="spinner-border" />
        </div>

        <div
            v-else
            class="row gx-1 pt-3 overflow-hidden"
        >
            <div class="col-12 col-lg-4">
                <h4 class="bg-primary text-bg-primary text-center p-3 mb-0 shadow">
                    {{ $gettext('Smart Blocks') }}
                </h4>

                <div class="card-body-flush border border-3 border-top-0 border-primary p-3 shadow">
                    <add-button
                        :text="$gettext('New Smart Block')"
                        @click="doCreate"
                    />
                </div>

                <ul class="list-group list-group-flush h-100 shadow">
                    <li
                        v-if="smartBlocks.length === 0"
                        class="no-drag"
                    >
                        <div class="p-5 text-center fs-5 text-muted">
                            {{ $gettext('No Smart Blocks yet — create one to get started.') }}
                        </div>
                    </li>

                    <li
                        v-for="item in smartBlocks"
                        :key="item.id"
                        class="list-group-item p-0"
                        :class="{active: selectedId === item.id}"
                    >
                        <button
                            type="button"
                            class="smart-block-selection-item d-block w-100 p-3 text-start border-0 bg-transparent"
                            @click="selectedId = item.id"
                        >
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span class="fs-5">{{ item.name }}</span>
                                <span
                                    v-if="!item.is_enabled"
                                    class="badge text-bg-danger"
                                >
                                    {{ $gettext('Disabled') }}
                                </span>
                            </div>
                            <div class="text-muted small mt-1">
                                {{ $gettext('%{count} tracks', {count: item.num_songs ?? 0}) }}
                            </div>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="col-12 col-lg-8">
                <h4 class="bg-primary text-bg-primary text-center p-3 mb-0 shadow">
                    {{ $gettext('Smart Block Editor') }}
                </h4>

                <div
                    v-if="!selectedId"
                    class="border border-3 border-top-0 border-primary p-5 text-center fs-5 text-muted shadow"
                >
                    {{ $gettext('Select a Smart Block on the left, or create a new one.') }}
                </div>

                <div
                    v-else
                    class="border border-3 border-top-0 border-primary p-3 shadow"
                >
                    <smart-block-criteria-editor
                        :key="selectedId"
                        standalone
                        :smart-block-url="smartBlockUrl(selectedId)"
                        :playlist-url="playlistUrl(selectedId)"
                        @saved="loadSmartBlocks"
                        @deleted="onDeleted"
                    />
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import {onMounted, ref} from "vue";
import AddButton from "~/components/Common/AddButton.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import SmartBlockCriteriaEditor from "~/components/Stations/Playlists/SmartBlockCriteriaEditor.vue";
import {
    PlaylistOrders,
    PlaylistSources,
    PlaylistTypes,
    type StationPlaylist,
} from "~/entities/ApiInterfaces.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {getErrorAsString, useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/playlists');

type SmartBlockListItem = StationPlaylist & {
    id: number,
    num_songs?: number,
    links?: {self?: string},
};

const loading = ref<boolean>(true);
const smartBlocks = ref<SmartBlockListItem[]>([]);
const selectedId = ref<number | null>(null);

const loadSmartBlocks = async (): Promise<void> => {
    loading.value = true;
    try {
        const {data} = await axios.get(listUrl.value, {
            params: {rowCount: -1, is_smart_block: '1'},
        });
        const items: SmartBlockListItem[] = (data.rows ?? data ?? []) as SmartBlockListItem[];

        smartBlocks.value = items.filter(
            (item) => item.source === PlaylistSources.Songs && item.is_smart_block,
        );
    } catch (err) {
        notifyError(`${$gettext('Failed to load Smart Blocks.')}: ${getErrorAsString(err)}`);
    } finally {
        loading.value = false;
    }
};

const playlistUrl = (id: number): string => {
    const record = smartBlocks.value.find((item) => item.id === id);
    return record?.links?.self ?? `${listUrl.value}/${id}`;
};

const smartBlockUrl = (id: number): string => `${playlistUrl(id)}/smart-block`;

const doCreate = async (): Promise<void> => {
    try {
        const {data} = await axios.post(listUrl.value, {
            name: $gettext('New Smart Block'),
            source: PlaylistSources.Songs,
            type: PlaylistTypes.Standard,
            order: PlaylistOrders.Shuffle,
            is_enabled: true,
            weight: 3,
            is_smart_block: true,
        });

        await loadSmartBlocks();

        const newId = data.id as number;
        if (newId) {
            selectedId.value = newId;
        }

        notifySuccess($gettext('Smart Block created. Add some criteria to get started.'));
    } catch (err) {
        notifyError(`${$gettext('Failed to create Smart Block.')}: ${getErrorAsString(err)}`);
    }
};

const onDeleted = async (): Promise<void> => {
    selectedId.value = null;
    await loadSmartBlocks();
};

onMounted(() => {
    void loadSmartBlocks();
});
</script>

<style lang="scss" scoped>
.list-group-item.active {
    background-color: var(--bs-secondary-bg);
    background-image: linear-gradient(to bottom, rgba(0, 0, 0, .12), rgba(0, 0, 0, .12));
    border-color: var(--bs-list-group-border-color);
    color: var(--bs-heading-color);
}

.smart-block-selection-item {
    cursor: pointer;

    &:hover {
        background-image: linear-gradient(to bottom, rgba(0, 0, 0, .12), rgba(0, 0, 0, .12));
    }
}
</style>
