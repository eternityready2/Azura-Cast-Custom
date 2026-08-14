<template>
    <card-page :title="$gettext('Web / Remote Streams')">
        <template #info>
            <p class="card-text">
                {{
                    $gettext('Manage external web radio streams. Each stream can be scheduled on the calendar just like a playlist — use the Schedule button on any row.')
                }}
            </p>
        </template>

        <template #actions>
            <add-button
                :text="$gettext('Add Stream')"
                @click="$editModal?.create()"
            />
        </template>

        <data-table
            id="station_web_streams"
            :fields="fields"
            :provider="provider"
            paginated
        >
            <template #cell(name)="row">
                <div>
                    <h5 class="m-0 fw-semibold">{{ row.item.name }}</h5>
                    <a
                        :href="row.item.remote_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-muted small text-truncate d-inline-block"
                        style="max-width: 380px;"
                        :title="row.item.remote_url"
                    >
                        {{ row.item.remote_url }}
                    </a>
                </div>
            </template>

            <template #cell(remote_type)="row">
                <span
                    class="badge rounded-pill"
                    :class="typeBadgeClass(row.item.remote_type)"
                >
                    {{ typeLabel(row.item.remote_type) }}
                </span>
            </template>

            <template #cell(remote_buffer)="row">
                {{ row.item.remote_buffer }}s
            </template>

            <template #cell(is_enabled)="row">
                <span
                    class="badge rounded-pill"
                    :class="row.item.is_enabled ? 'text-bg-success' : 'text-bg-secondary'"
                >
                    {{ row.item.is_enabled ? $gettext('Enabled') : $gettext('Disabled') }}
                </span>
            </template>

            <template #cell(actions)="row">
                <div class="btn-group btn-group-sm">
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="$editModal?.edit(row.item.links.self)"
                    >
                        {{ $gettext('Edit') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-primary"
                        @click="doSchedule(row.item)"
                    >
                        {{ $gettext('Schedule') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        @click="doDelete(row.item.links.self)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </template>
        </data-table>
    </card-page>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="refresh"
    />

    <create-event-modal
        ref="$scheduleModal"
        @relist="refresh"
    />

    <schedule-list-modal
        ref="$scheduleListModal"
        @edit="onScheduleListEdit"
        @add-new="onScheduleListAddNew"
        @relist="refresh"
    />
</template>

<script setup lang="ts">
import {computed, onMounted, ref, useTemplateRef} from "vue";
import {useTranslate} from "~/vendor/gettext";
import CardPage from "~/components/Common/CardPage.vue";
import AddButton from "~/components/Common/AddButton.vue";
import DataTable from "~/components/Common/DataTable.vue";
import EditModal from "~/components/Stations/WebStreams/EditModal.vue";
import CreateEventModal from "~/components/Stations/Common/CreateEventModal.vue";
import ScheduleListModal from "~/components/Stations/WebStreams/ScheduleListModal.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios.ts";
import useConfirmAndDelete from "~/functions/useConfirmAndDelete.ts";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();

const listUrl = getStationApiUrl('/playlists');

// ── Manual provider with client-side pagination ───────────────────────────────
const streams = ref<any[]>([]);
const isLoading = ref(false);
const pageContext = ref({page: 1, perPage: 10});

const pagedStreams = computed(() => {
    const {page, perPage} = pageContext.value;
    const start = (page - 1) * perPage;
    return streams.value.slice(start, start + perPage);
});

const loadStreams = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get(listUrl.value, {params: {rowCount: -1}});
        const all: any[] = Array.isArray(data) ? data : (data.rows ?? []);
        streams.value = all.filter((item: any) => item.source === 'remote_url');
    } catch {
        streams.value = [];
    } finally {
        isLoading.value = false;
    }
};

const provider = {
    rows: pagedStreams,
    total: computed(() => streams.value.length),
    loading: computed(() => isLoading.value),
    setContext: (ctx: any) => {
        pageContext.value = {
            page: ctx.currentPage ?? pageContext.value.page,
            perPage: ctx.perPage ?? pageContext.value.perPage,
        };
    },
    refresh: async () => { await loadStreams(); },
};

const refresh = () => loadStreams();

onMounted(loadStreams);

// ── Table fields ──────────────────────────────────────────────────────────────
const fields = [
    {key: 'name',          isRowHeader: true, label: $gettext('Name / URL'),   sortable: true},
    {key: 'remote_type',   label: $gettext('Type'),                            sortable: true,  class: 'shrink'},
    {key: 'remote_buffer', label: $gettext('Buffer'),                          sortable: false, class: 'shrink'},
    {key: 'is_enabled',    label: $gettext('Status'),                          sortable: true,  class: 'shrink'},
    {key: 'actions',       label: $gettext('Actions'),                         sortable: false, class: 'shrink'},
];

// ── Type helpers ──────────────────────────────────────────────────────────────
const typeLabel = (type: string) => {
    switch (type) {
        case 'stream':   return 'Icecast/Shoutcast';
        case 'playlist': return 'M3U/PLS';
        case 'other':    return 'HLS/Other';
        default:         return type ?? '—';
    }
};

const typeBadgeClass = (type: string) => {
    switch (type) {
        case 'stream':   return 'text-bg-info';
        case 'playlist': return 'text-bg-warning';
        case 'other':    return 'text-bg-secondary';
        default:         return 'text-bg-light';
    }
};

// ── Edit / Delete / Schedule ──────────────────────────────────────────────────
const $editModal = useTemplateRef('$editModal');

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete this web stream?'),
    refresh
);

const $scheduleModal = useTemplateRef('$scheduleModal');
const $scheduleListModal = useTemplateRef('$scheduleListModal');

// Previously this jumped straight to a blank "Create Event" form, which meant
// existing schedule entries (set from the main Schedule page, or a prior visit
// here) were invisible from this page -- looking like the two pages disagreed.
// Now it opens a list of what's actually scheduled for this stream first.
const doSchedule = (item: any) => {
    const id = item.id as number;
    if (!id) return;
    void $scheduleListModal.value?.open(id, item.name as string | undefined);
};

const onScheduleListEdit = (entityId: number, scheduleId: number) => {
    $scheduleModal.value?.openScopedForEdit('playlist', entityId, scheduleId);
};

const onScheduleListAddNew = (entityId: number) => {
    $scheduleModal.value?.openScopedForCreate('playlist', entityId);
};
</script>
