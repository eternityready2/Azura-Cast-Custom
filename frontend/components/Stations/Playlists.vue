<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_playlists"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_playlists"
                        class="card-title"
                    >
                        {{ $gettext('Playlists') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <time-zone />
                </div>
            </div>
        </div>

        <div class="card-body">
            <tabs
                v-model="activeTab"
                content-class="mt-3"
                destroy-on-hide
            >
                <tab
                    id="all_playlists"
                    :label="$gettext('All Playlists')"
                >
                    <div class="card-body-flush">
                        <div class="card-body buttons d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <add-button
                                :text="$gettext('Add Playlist')"
                                @click="doCreate"
                            />

                            <div class="d-flex gap-2">
                                <a
                                    class="btn btn-secondary"
                                    :href="exportPlaylistsConfigUrl"
                                    target="_blank"
                                >
                                    <icon-bi-cloud-download />
                                    {{ $gettext('Export JSON') }}
                                </a>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="doImportPlaylistConfig"
                                >
                                    <icon-bi-cloud-upload />
                                    {{ $gettext('Import JSON') }}
                                </button>
                            </div>
                        </div>

                        <data-table
                            id="station_playlists"
                            paginated
                            :fields="fields"
                            :provider="listItemProvider"
                            detailed
                        >
                            <template #cell(name)="row">
                                <h5 class="m-0">
                                    {{ row.item.name }}
                                </h5>
                                <p v-if="row.item.description" class="text-muted mb-1">
                                    {{ row.item.description }}
                                </p>
                                <div class="badges">
                                    <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1">
                                        <playlist-source-icon :source="row.item.source" />
                                        <template v-if="row.item.source === 'songs'">
                                            {{ $gettext('Song-based') }}
                                        </template>
                                        <template v-else-if="row.item.source === 'playlists'">
                                            {{ $gettext('Playlist Group') }}
                                        </template>
                                        <template v-else-if="row.item.source === 'requests'">
                                            {{ $gettext('Request Queue') }}
                                        </template>
                                        <template v-else>
                                            {{ $gettext('Remote URL') }}
                                        </template>
                                    </span>
                                    <span
                                        v-if="row.item.is_jingle"
                                        class="badge text-bg-primary"
                                    >
                                        {{ $gettext('Jingle Mode') }}
                                    </span>
                                    <span
                                        v-if="row.item.source === 'songs' && row.item.is_smart_block"
                                        class="badge text-bg-success"
                                    >
                                        <router-link
                                            :to="{name: 'stations:smart-blocks:index'}"
                                            class="text-white text-decoration-none"
                                        >
                                            {{ $gettext('Smart Block') }}
                                        </router-link>
                                    </span>
                                    <span
                                        v-if="row.item.source === 'songs' && row.item.order === 'sequential'"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('Sequential') }}
                                    </span>
                                    <span
                                        v-if="row.item.include_in_on_demand"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('On-Demand') }}
                                    </span>
                                    <span
                                        v-if="row.item.schedule_items.length > 0"
                                        class="badge text-bg-info"
                                    >
                                        {{ $gettext('Scheduled') }}
                                    </span>
                                    <span
                                        v-if="!row.item.is_enabled"
                                        class="badge text-bg-danger"
                                    >
                                        {{ $gettext('Disabled') }}
                                    </span>
                                    <span
                                        v-for="group in row.item.member_of_groups ?? []"
                                        :key="group.id"
                                        class="badge text-bg-warning"
                                    >
                                        {{ $gettext('Member of: %{group}', {group: group.name}) }}
                                    </span>
                                </div>
                            </template>

                            <template #cell(scheduling)="{ item }">
                                <template v-if="!item.is_enabled">
                                    {{ $gettext('Disabled') }}
                                </template>
                                <template v-else-if="item.source === 'remote_url'">
                                    {{ $gettext('Remote URL') }}
                                </template>
                                <template v-else-if="item.type === 'default'">
                                    {{ $gettext('General Rotation') }}<br>
                                    {{ $gettext('Weight') }}: {{ item.weight }}
                                </template>
                                <template v-else-if="item.type === 'once_per_x_songs'">
                                    {{ $gettext('Once per %{songs} Songs', {songs: item.play_per_songs}) }}
                                </template>
                                <template v-else-if="item.type === 'once_per_x_minutes'">
                                    {{ $gettext('Once per %{minutes} Minutes', {minutes: item.play_per_minutes}) }}
                                </template>
                                <template v-else-if="item.type === 'once_per_hour'">
                                    {{ $gettext('Once per Hour (at %{minute})', {minute: item.play_per_hour_minute}) }}
                                </template>
                                <template v-else>
                                    {{ $gettext('Custom') }}
                                </template>
                            </template>

                            <template #cell(num_songs)="row">
                                <template v-if="row.item.source === 'songs'">
                                    <router-link
                                        :to="{
                                            name: 'stations:files:index',
                                            params: {path: 'playlist:'+row.item.short_name}
                                        }"
                                    >
                                        {{ row.item.num_songs }}
                                    </router-link>
                                    ({{ formatLength(row.item.total_length) }})
                                </template>
                                <template v-else-if="row.item.source === 'playlists'">
                                    {{ row.item.playlists?.length ?? 0 }} {{ $gettext('members') }}
                                </template>
                                <template v-else>
                                    &nbsp;
                                </template>
                            </template>

                            <template #cell(actions)="{ item, isActive, toggleDetails }">
                                <div class="btn-group btn-group-sm">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        @click="doEdit(item.links.self)"
                                    >
                                        {{ $gettext('Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-danger"
                                        @click="doDelete(item.links.self)"
                                    >
                                        {{ $gettext('Delete') }}
                                    </button>
                                    <button
                                        class="btn btn-sm btn-secondary"
                                        type="button"
                                        @click="toggleDetails()"
                                    >
                                        <icon-bi-contract v-if="isActive"/>
                                        <icon-bi-expand v-else/>
                                        {{ $gettext('More') }}
                                    </button>
                                </div>
                            </template>

                            <template #detail="{ item }">
                                <div class="buttons" style="line-height: 2.5;">
                                    <button
                                        v-if="item.links.order"
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        @click="doReorder(item.links.order)"
                                    >
                                        {{ $gettext('Reorder') }}
                                    </button>

                                    <button
                                        v-if="item.source === 'playlists'"
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        @click="activeTab = 'playlist_grouping'"
                                    >
                                        {{ $gettext('Playlist Grouping') }}
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        :class="item.is_enabled ? 'btn-warning' : 'btn-success'"
                                        @click="doModify(item.links.toggle)"
                                    >
                                        {{ item.is_enabled ? $gettext('Disable') : $gettext('Enable') }}
                                    </button>

                                    <button
                                        v-if="item.links.empty"
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        @click="doEmpty(item.links.empty)"
                                    >
                                        {{ $gettext('Empty') }}
                                    </button>

                                    <button
                                        v-if="item.links.reshuffle"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doModify(item.links.reshuffle)"
                                    >
                                        {{ $gettext('Reshuffle') }}
                                    </button>

                                    <button
                                        v-if="item.links.import"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doImport(item.links.import)"
                                    >
                                        {{ $gettext('Import from PLS/M3U') }}
                                    </button>

                                    <button
                                        v-if="item.links.queue"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doQueue(item.links.queue)"
                                    >
                                        {{ $gettext('Playback Queue') }}
                                    </button>

                                    <button
                                        v-if="item.links.applyto"
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doApplyTo(item.links.applyto)"
                                    >
                                        {{ $gettext('Apply to Folders') }}
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        @click="doClone(item.name, item.links.clone)"
                                    >
                                        {{ $gettext('Duplicate') }}
                                    </button>

                                    <a
                                        v-if="item.source !== 'playlists'"
                                        v-for="format in ['pls', 'm3u']"
                                        :key="format"
                                        class="btn btn-sm btn-secondary"
                                        :href="item.links.export[format]"
                                        target="_blank"
                                    >
                                        {{ $gettext('Export %{format}', {format: format.toUpperCase()}) }}
                                    </a>

                                    <a
                                        class="btn btn-sm btn-secondary"
                                        :href="getPlaylistExportConfigUrl(item.id)"
                                        target="_blank"
                                    >
                                        {{ $gettext('Export JSON') }}
                                    </a>
                                </div>
                            </template>
                        </data-table>
                    </div>
                </tab>

                <playlist-grouping-tab :list-url="listUrl" />
            </tabs>
        </div>
    </section>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="() => relist()"
        @needs-restart="() => mayNeedRestart()"
    />
    <reorder-modal ref="$reorderModal" />
    <queue-modal ref="$queueModal" />
    <import-modal ref="$importModal" @relist="() => relist()" />
    <import-playlist-config-modal
        ref="$importPlaylistConfigModal"
        :import-url="importPlaylistsConfigUrl"
        @relist="() => relist()"
    />
    <clone-modal
        ref="$cloneModal"
        @relist="() => relist()"
        @needs-restart="() => mayNeedRestart()"
    />
    <apply-to-modal ref="$applyToModal" @relist="() => relist()" />
</template>

<script setup lang="ts">
import {toRefs} from "@vueuse/core";
import {ref, useTemplateRef} from "vue";
import AddButton from "~/components/Common/AddButton.vue";
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import Tab from "~/components/Common/Tab.vue";
import Tabs from "~/components/Common/Tabs.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import PlaylistSourceIcon from "~/components/Stations/Common/PlaylistSourceIcon.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import ApplyToModal from "~/components/Stations/Playlists/ApplyToModal.vue";
import CloneModal from "~/components/Stations/Playlists/CloneModal.vue";
import EditModal from "~/components/Stations/Playlists/EditModal.vue";
import ImportModal from "~/components/Stations/Playlists/ImportModal.vue";
import ImportPlaylistConfigModal from "~/components/Stations/Playlists/ImportPlaylistConfigModal.vue";
import PlaylistGroupingTab from "~/components/Stations/Playlists/PlaylistGroupingTab.vue";
import QueueModal from "~/components/Stations/Playlists/QueueModal.vue";
import ReorderModal from "~/components/Stations/Playlists/ReorderModal.vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete";
import useHasEditModal from "~/functions/useHasEditModal";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useStationData} from "~/functions/useStationQuery.ts";
import {useAxios} from "~/vendor/axios";
import {useLuxon} from "~/vendor/luxon";
import {useTranslate} from "~/vendor/gettext";
import IconBiContract from "~icons/bi/chevron-contract";
import IconBiExpand from "~icons/bi/chevron-expand";
import IconBiCloudDownload from "~icons/bi/cloud-download";
import IconBiCloudUpload from "~icons/bi/cloud-upload";

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/playlists');
const exportPlaylistsConfigUrl = getStationApiUrl('/playlists/export-config');
const importPlaylistsConfigUrl = getStationApiUrl('/playlists/import-config');
const getPlaylistExportConfigUrl = (playlistId: number): string => {
    return getStationApiUrl(`/playlist/${playlistId}/export-config`).value;
};

const activeTab = ref<string>('all_playlists');
const {$gettext} = useTranslate();

const fields: DataTableField[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Playlist'), sortable: true},
    {key: 'scheduling', label: $gettext('Scheduling'), sortable: false},
    {key: 'num_songs', label: $gettext('# Songs'), sortable: false},
    {key: 'actions', label: $gettext('Actions'), sortable: false, class: 'shrink'}
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists]),
    undefined,
);

const {Duration} = useLuxon();
const formatLength = (length: number) => {
    if (0 === length) {
        return $gettext('None');
    }
    return Duration.fromMillis(length * 1000).rescale().toHuman();
};

const relist = () => {
    void listItemProvider.refresh();
};

const $editModal = useTemplateRef('$editModal');
const {doCreate, doEdit} = useHasEditModal($editModal);

const $reorderModal = useTemplateRef('$reorderModal');
const doReorder = (url: string) => {
    $reorderModal.value?.open(url);
};

const $queueModal = useTemplateRef('$queueModal');
const doQueue = (url: string) => {
    $queueModal.value?.open(url);
};

const $importModal = useTemplateRef('$importModal');
const doImport = (url: string) => {
    $importModal.value?.open(url);
};

const $importPlaylistConfigModal = useTemplateRef('$importPlaylistConfigModal');
const doImportPlaylistConfig = () => {
    $importPlaylistConfigModal.value?.open();
};

const $cloneModal = useTemplateRef('$cloneModal');
const doClone = (name: string, url: string) => {
    $cloneModal.value?.open(name, url);
};

const $applyToModal = useTemplateRef('$applyToModal');
const doApplyTo = (url: string) => {
    $applyToModal.value?.open(url);
};

const {mayNeedRestart: originalMayNeedRestart} = useMayNeedRestart();
const stationData = useStationData();
const {useManualAutoDj} = toRefs(stationData);

const mayNeedRestart = () => {
    if (!useManualAutoDj.value) {
        return;
    }
    originalMayNeedRestart();
};

const {notifySuccess} = useNotify();
const {axios} = useAxios();

const doModify = async (url: string) => {
    const {data} = await axios.put(url);
    mayNeedRestart();
    notifySuccess(data.message);
    relist();
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Playlist?'),
    () => {
        relist();
        mayNeedRestart();
    },
);

const {doDelete: doEmpty} = useConfirmAndDelete(
    $gettext('Clear all media from playlist?'),
    () => {
        relist();
        mayNeedRestart();
    },
);
</script>
