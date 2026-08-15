<template>
    <card-page :title="$gettext('Upcoming Song Queue')">
        <template #actions>
            <select
                v-model="playlistFilter"
                class="form-select d-inline-block w-auto me-2"
                :aria-label="$gettext('Filter by Playlist')"
            >
                <option :value="null">
                    {{ $gettext('All Playlists') }}
                </option>
                <option value="__via_group__">
                    {{ $gettext('Played via Playlist Group') }}
                </option>
                <option
                    v-for="group in groupFilterOptions"
                    :key="`group-${group.id}`"
                    :value="`group:${group.name}`"
                >
                    {{ group.name }}
                </option>
                <option
                    v-for="playlist in playlistFilterOptions"
                    :key="`playlist-${playlist.id}`"
                    :value="`playlist:${playlist.id}`"
                >
                    {{ playlist.name }}
                </option>
            </select>

            <button
                type="button"
                class="btn btn-danger"
                @click="doClear()"
            >
                <icon-ic-remove/>

                <span>
                    {{ $gettext('Clear Upcoming Song Queue') }}
                </span>
            </button>
        </template>

        <data-table
            id="station_queue"
            :fields="fields"
            :provider="listItemProvider"
            :hide-on-loading="false"
        >
            <template #cell(actions)="row">
                <div class="btn-group btn-group-sm">
                    <button
                        v-if="row.item.log"
                        type="button"
                        class="btn btn-primary"
                        @click.prevent="doShowLogs(row.item.log)"
                    >
                        {{ $gettext('Logs') }}
                    </button>
                    <button
                        v-if="!row.item.sent_to_autodj"
                        type="button"
                        class="btn btn-danger"
                        @click.prevent="doDelete(row.item.links.self)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </template>
            <template #cell(song_title)="row">
                <div v-if="row.item.autodj_custom_uri">
                    {{ row.item.autodj_custom_uri }}
                </div>
                <div v-else-if="row.item.song.title">
                    <b>{{ row.item.song.title }}</b><br>
                    {{ row.item.song.artist }}
                </div>
                <div v-else>
                    {{ row.item.song.text }}
                </div>
            </template>
            <template #cell(played_at)="row">
                {{ formatTimestampAsTime(row.item.played_at) }}<br>
                <small>{{ formatTimestampAsRelative(row.item.played_at) }}</small>
            </template>
            <template #cell(source)="row">
                <div v-if="row.item.top_of_hour_legal_id">
                    <span class="badge text-bg-info">
                        {{ $gettext('Top of Hour ID') }}
                    </span>
                </div>
                <div v-else-if="row.item.is_request">
                    {{ $gettext('Listener Request') }}
                </div>
                <div v-else-if="row.item.playlist">
                    {{ $gettext('Playlist') }}:
                    <playlist-source-badge
                        :playlist-name="row.item.playlist"
                        :chain="row.item.playlist_chain"
                    />
                </div>
                <div v-else-if="row.item.clock_wheel">
                    {{ $gettext('Clock Wheel') }}: {{ row.item.clock_wheel }}
                </div>
            </template>
        </data-table>
    </card-page>

    <queue-logs-modal ref="$logsModal" />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from "~/components/Common/DataTable.vue";
import QueueLogsModal from "~/components/Stations/Queue/LogsModal.vue";
import PlaylistSourceBadge from "~/components/Stations/Common/PlaylistSourceBadge.vue";
import {useTranslate} from "~/vendor/gettext";
import {computed, ref, useTemplateRef} from "vue";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useAxios} from "~/vendor/axios";
import CardPage from "~/components/Common/CardPage.vue";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import {ApiNowPlayingStationQueue, ApiStationQueueDetailed, ApiStatus} from "~/entities/ApiInterfaces.ts";
import {useApiItemProvider} from "~/functions/dataTable/useApiItemProvider.ts";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import IconIcRemove from "~icons/ic/baseline-remove";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/queue');
const clearUrl = getStationApiUrl('/queue/clear');

const {$gettext} = useTranslate();

const playlistFilter = ref<string | null>(null);
const groupFilterOptions = ref<{ id: number, name: string }[]>([]);
const playlistFilterOptions = ref<{ id: number, name: string }[]>([]);

const loadFilterOptions = async () => {
    try {
        const {data} = await axios.get(getStationApiUrl('/playlists'), {
            params: {rowCount: -1},
        });

        const rows: Record<string, unknown>[] = (data.rows as Record<string, unknown>[]) ?? [];

        groupFilterOptions.value = rows
            .filter((p) => p.source === 'group')
            .map((p) => ({id: p.id as number, name: p.name as string}));

        playlistFilterOptions.value = rows
            .filter((p) => p.source !== 'group')
            .map((p) => ({id: p.id as number, name: p.name as string}));
    } catch {
        // Non-critical -- the queue still works without the filter options loaded.
    }
};

void loadFilterOptions();

const apiUrl = computed(() => {
    const apiUrl = new URL(listUrl.value, document.location.href);
    const apiUrlParams = apiUrl.searchParams;

    if (playlistFilter.value === '__via_group__') {
        apiUrlParams.set('filter_via_group', '1');
    } else if (playlistFilter.value?.startsWith('group:')) {
        apiUrlParams.set('filter_group', playlistFilter.value.slice('group:'.length));
    } else if (playlistFilter.value?.startsWith('playlist:')) {
        apiUrlParams.set('filter_playlist_id', playlistFilter.value.slice('playlist:'.length));
    }

    return apiUrl.toString();
});

type Row = Required<ApiNowPlayingStationQueue & ApiStationQueueDetailed>;

const fields: DataTableField<Row>[] = [
    {key: 'actions', label: $gettext('Actions'), sortable: false},
    {key: 'song_title', isRowHeader: true, label: $gettext('Song Title'), sortable: false},
    {key: 'played_at', label: $gettext('Expected to Play at'), sortable: false},
    {key: 'source', label: $gettext('Source'), sortable: false}
];

const listItemProvider = useApiItemProvider(
    apiUrl,
    queryKeyWithStation([QueryKeys.StationQueue, playlistFilter]),
    {
        refetchInterval: 30000
    }
);

const relist = () => {
    void listItemProvider.refresh();
};

const {
    formatTimestampAsTime,
    formatTimestampAsRelative
} = useStationDateTimeFormatter();

const $logsModal = useTemplateRef('$logsModal');

const doShowLogs = (logs: string[]) => {
    $logsModal.value?.show(logs);
};

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Queue Item?'),
    () => relist()
);

const {confirmDelete} = useDialog();
const {notifySuccess} = useNotify();
const {axios} = useAxios();

const doClear = async () => {
    const {value} = await confirmDelete({
        title: $gettext('Clear Upcoming Song Queue?'),
        confirmButtonText: $gettext('Clear'),
    });

    if (value) {
        const {data} = await axios.post<ApiStatus>(clearUrl.value);

        notifySuccess(data.message);
        relist();
    }
}
</script>
