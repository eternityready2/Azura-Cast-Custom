<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_schedule"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_schedule"
                        class="card-title"
                    >
                        {{ $gettext('Schedule') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <time-zone />
                </div>
            </div>
        </div>

        <div class="card-body pb-0">
            <nav
                class="nav nav-tabs"
                role="tablist"
            >
                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'calendar'}"
                        role="tab"
                        :aria-selected="activeTab === 'calendar'"
                        @click="activeTab = 'calendar'"
                    >
                        {{ $gettext('Calendar') }}
                    </button>
                </div>

                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'live'}"
                        role="tab"
                        :aria-selected="activeTab === 'live'"
                        @click="activeTab = 'live'"
                    >
                        {{ $gettext('Live Clock Wheel') }}
                    </button>
                </div>

                <div
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        type="button"
                        class="nav-link"
                        :class="{active: activeTab === 'holidays'}"
                        role="tab"
                        :aria-selected="activeTab === 'holidays'"
                        @click="activeTab = 'holidays'"
                    >
                        {{ $gettext('Holidays') }}
                    </button>
                </div>
            </nav>
        </div>

        <div class="card-body">
            <div v-show="activeTab === 'calendar'">
                <div class="row g-0">
                    <div :class="sidebarVisible ? 'col-12 col-lg-9' : 'col-12'">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="mb-0">
                                {{ $gettext('Calendar') }}
                            </h5>
                            <button
                                type="button"
                                class="btn rounded-pill shadow-sm"
                                :class="sidebarVisible ? 'btn-secondary' : 'btn-primary'"
                                @click="sidebarVisible = !sidebarVisible"
                            >
                                <icon-ic-playlist-add class="me-1" />
                                {{ sidebarVisible ? $gettext('Hide Drag Sources') : $gettext('Show Drag Sources (Playlists, Smart Blocks, Clock Wheels)') }}
                            </button>
                        </div>

                        <schedule-calendar
                            ref="$scheduleTab"
                            :schedule-url="[scheduleUrl, clockWheelsScheduleUrl]"
                            :show-create-button="true"
                            external-drag-selector=".smart-block-drag-item, .clock-wheel-drag-item"
                            @click="doCalendarClick"
                            @create="doCreateEvent"
                            @drop-external="onEntityDrop"
                        />
                    </div>

                    <div
                        v-if="sidebarVisible"
                        class="col-12 col-lg-3 ps-lg-3 mt-3 mt-lg-0"
                    >
                        <div class="card">
                            <div
                                class="card-header text-bg-secondary d-flex justify-content-between align-items-center"
                                role="button"
                                @click="panelsOpen.playlists = !panelsOpen.playlists"
                            >
                                <span class="fw-semibold small">{{ $gettext('Playlists') }}</span>
                                <div class="d-flex align-items-center gap-2">
                                    <router-link
                                        :to="{name: 'stations:playlists:index'}"
                                        class="small"
                                        @click.stop
                                    >
                                        {{ $gettext('Manage') }}
                                    </router-link>
                                    <icon-ic-chevron-down
                                        :style="{transform: panelsOpen.playlists ? 'rotate(180deg)' : 'none'}"
                                        style="transition: transform .15s;"
                                    />
                                </div>
                            </div>
                            <template v-if="panelsOpen.playlists">
                                <ul class="list-group list-group-flush">
                                    <li
                                        v-if="playlistsForDrag.length === 0"
                                        class="list-group-item text-muted small"
                                    >
                                        {{ $gettext('No playlists yet.') }}
                                    </li>
                                    <li
                                        v-for="playlist in playlistsForDrag"
                                        :key="`pl-${playlist.id}`"
                                        class="smart-block-drag-item list-group-item d-flex align-items-center gap-2 small"
                                        style="cursor: grab;"
                                        :data-entity-id="playlist.id"
                                        :data-entity-type="'playlist'"
                                        :data-event="JSON.stringify({title: playlist.name})"
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <span class="text-truncate">{{ playlist.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag a playlist onto the calendar to schedule it.') }}
                                </div>
                            </template>
                        </div>

                        <div class="card mt-3">
                            <div
                                class="card-header text-bg-secondary d-flex justify-content-between align-items-center"
                                role="button"
                                @click="panelsOpen.smartBlocks = !panelsOpen.smartBlocks"
                            >
                                <span class="fw-semibold small">{{ $gettext('Smart Blocks') }}</span>
                                <div class="d-flex align-items-center gap-2">
                                    <router-link
                                        :to="{name: 'stations:smart-blocks:index'}"
                                        class="small"
                                        @click.stop
                                    >
                                        {{ $gettext('Manage') }}
                                    </router-link>
                                    <icon-ic-chevron-down
                                        :style="{transform: panelsOpen.smartBlocks ? 'rotate(180deg)' : 'none'}"
                                        style="transition: transform .15s;"
                                    />
                                </div>
                            </div>
                            <template v-if="panelsOpen.smartBlocks">
                                <ul class="list-group list-group-flush">
                                    <li
                                        v-if="smartBlocksForDrag.length === 0"
                                        class="list-group-item text-muted small"
                                    >
                                        {{ $gettext('No Smart Blocks yet.') }}
                                    </li>
                                    <li
                                        v-for="block in smartBlocksForDrag"
                                        :key="`sb-${block.id}`"
                                        class="smart-block-drag-item list-group-item d-flex align-items-center gap-2 small"
                                        style="cursor: grab;"
                                        :data-entity-id="block.id"
                                        :data-entity-type="'smart_block'"
                                        :data-event="JSON.stringify({title: block.name})"
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <span class="text-truncate">{{ block.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag a Smart Block onto the calendar to schedule it.') }}
                                </div>
                            </template>
                        </div>

                        <div class="card mt-3">
                            <div
                                class="card-header text-bg-secondary d-flex justify-content-between align-items-center"
                                role="button"
                                @click="panelsOpen.clockWheels = !panelsOpen.clockWheels"
                            >
                                <span class="fw-semibold small">{{ $gettext('Clock Wheels') }}</span>
                                <div class="d-flex align-items-center gap-2">
                                    <router-link
                                        :to="{name: 'stations:clock_wheels:index'}"
                                        class="small"
                                        @click.stop
                                    >
                                        {{ $gettext('Manage') }}
                                    </router-link>
                                    <icon-ic-chevron-down
                                        :style="{transform: panelsOpen.clockWheels ? 'rotate(180deg)' : 'none'}"
                                        style="transition: transform .15s;"
                                    />
                                </div>
                            </div>
                            <template v-if="panelsOpen.clockWheels">
                                <ul class="list-group list-group-flush">
                                    <li
                                        v-if="clockWheelsForDrag.length === 0"
                                        class="list-group-item text-muted small"
                                    >
                                        {{ $gettext('No Clock Wheel Templates yet.') }}
                                    </li>
                                    <li
                                        v-for="template in clockWheelsForDrag"
                                        :key="`cw-${template.id}`"
                                        class="clock-wheel-drag-item list-group-item d-flex align-items-center gap-2 small"
                                        style="cursor: grab;"
                                        :data-entity-id="template.id"
                                        :data-entity-type="'clock_wheel'"
                                        :data-event="JSON.stringify({title: template.name})"
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <span class="text-truncate">{{ template.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag a Clock Wheel onto the calendar to schedule it.') }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <clock-wheel-live-tab
                v-show="activeTab === 'live'"
                :active="activeTab === 'live'"
            />

            <holiday-overrides-tab
                v-show="activeTab === 'holidays'"
                :list-url="holidayOverridesUrl"
                :wheels-url="clockWheelsListUrl"
                :playlists-url="listUrl"
            />
        </div>

        <edit-modal
            ref="$editModal"
            :create-url="listUrl"
            @relist="relist"
        />

        <clock-wheel-edit-modal
            ref="$clockWheelEditModal"
            :create-url="clockWheelsListUrl"
            :templates-url="clockWheelTemplatesUrl"
            @relist="relist"
        />

        <create-event-modal
            ref="$createEventModal"
            @relist="relist"
        />
    </section>
</template>

<script setup lang="ts">
import ScheduleCalendar from "~/components/Stations/Common/ScheduleCalendar.vue";
import ClockWheelLiveTab from "~/components/Stations/Schedule/ClockWheelLiveTab.vue";
import HolidayOverridesTab from "~/components/Stations/Schedule/HolidayOverridesTab.vue";
import EditModal from "~/components/Stations/Playlists/EditModal.vue";
import ClockWheelEditModal from "~/components/Stations/ClockWheels/EditModal.vue";
import CreateEventModal from "~/components/Stations/Common/CreateEventModal.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {nextTick, onMounted, reactive, ref, useTemplateRef, watch} from "vue";
import {EventImpl} from "@fullcalendar/core/internal";
import useHasEditModal from "~/functions/useHasEditModal";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import IconIcDragIndicator from "~icons/ic/baseline-drag-indicator";
import IconIcPlaylistAdd from "~icons/ic/baseline-playlist-add";
import IconIcChevronDown from "~icons/ic/baseline-keyboard-arrow-down";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();

const activeTab = ref<'calendar' | 'live' | 'holidays'>('calendar');

// Sidebar (drag sources) visibility -- collapsing this frees up the full width of
// the calendar. Unmounts the whole column (v-if, not v-show) rather than shrinking
// it, so it never overlaps or interferes with the calendar's own event hover
// tooltips -- there's no floating/absolutely-positioned overlay involved at all.
// Sidebar (drag sources) visibility -- collapsing this frees up the full width of
// the calendar. Unmounts the whole column (v-if, not v-show) rather than shrinking
// it, so it never overlaps or interferes with the calendar's own event hover
// tooltips -- there's no floating/absolutely-positioned overlay involved at all.
// Starts HIDDEN so the calendar gets full width by default on page load.
const sidebarVisible = ref<boolean>(false);

watch(sidebarVisible, async () => {
    // The calendar's container width just changed (col-lg-9 <-> col-12) -- let
    // FullCalendar know, or it can keep rendering at its old size until something
    // else forces a reflow.
    await nextTick();
    $scheduleTab.value?.updateSize();
});

// Each of the three panels inside the sidebar also starts collapsed, so opening the
// sidebar doesn't immediately dump a long, uncollapsed list of every playlist/smart
// block/clock wheel you have -- expand only the one you actually need.
const panelsOpen = reactive({
    playlists: false,
    smartBlocks: false,
    clockWheels: false,
});

const listUrl = getStationApiUrl('/playlists');
const clockWheelsListUrl = getStationApiUrl('/clock-wheels');
const clockWheelTemplatesUrl = getStationApiUrl('/clock-wheel-templates');
const holidayOverridesUrl = getStationApiUrl('/holiday-overrides');
const scheduleUrl = getStationApiUrl('/playlists/schedule');
const clockWheelsScheduleUrl = getStationApiUrl('/clock-wheels/schedule');

const $editModal = useTemplateRef('$editModal');
const {doEdit} = useHasEditModal($editModal);

const $clockWheelEditModal = useTemplateRef('$clockWheelEditModal');
const {doEdit: doEditClockWheel} = useHasEditModal($clockWheelEditModal);

const $scheduleTab = useTemplateRef('$scheduleTab');
const $createEventModal = useTemplateRef('$createEventModal');

watch(activeTab, async (newTab) => {
    if (newTab === 'calendar') {
        await nextTick();
        $scheduleTab.value?.updateSize();
    }
});

const doCalendarClick = (event: EventImpl) => {
    $createEventModal.value?.openForEdit(event);
};

const doCreateEvent = () => {
    $createEventModal.value?.open();
};

// Drag panels (Calendar tab) -- lets you drag a saved Smart Block or Clock Wheel
// Template straight onto the calendar to schedule it, per Airtime Pro's
// manual-scheduling workflow. Fully Automated scheduling (the Rotation/weight
// equivalent) doesn't need this at all -- it's for "I want this specific thing in
// this specific slot."
const {axios} = useAxios();

type SmartBlockDragItem = {id: number, name: string};
type ClockWheelTemplateDragItem = {id: number, name: string};
type PlaylistDragItem = {id: number, name: string};

const smartBlocksForDrag = ref<SmartBlockDragItem[]>([]);
const clockWheelsForDrag = ref<ClockWheelTemplateDragItem[]>([]);
const playlistsForDrag = ref<PlaylistDragItem[]>([]);

const loadSmartBlocksForDrag = async () => {
    try {
        const {data} = await axios.get(listUrl.value, {
            params: {rowCount: -1, is_smart_block: '1'},
        });
        const rows = (data.rows ?? data ?? []) as Array<Record<string, unknown>>;
        smartBlocksForDrag.value = rows.map((row) => ({
            id: row.id as number,
            name: row.name as string,
        }));
    } catch {
        // Non-critical -- the drag panel just stays empty. Errors already surface
        // globally via the axios response interceptor.
    }
};

const loadPlaylistsForDrag = async () => {
    try {
        const {data} = await axios.get(listUrl.value, {
            params: {rowCount: -1, is_smart_block: '0'},
        });
        const rows = (data.rows ?? data ?? []) as Array<Record<string, unknown>>;
        playlistsForDrag.value = rows.map((row) => ({
            id: row.id as number,
            name: row.name as string,
        }));
    } catch {
        // Non-critical -- the drag panel just stays empty.
    }
};

const loadClockWheelTemplatesForDrag = async () => {
    try {
        // Deliberately sourced from /clock-wheels (configured wheel instances), not
        // /clock-wheel-templates -- CreateEventModal's Clock Wheel picker (and thus
        // the schedule entry this creates) references wheel instances by ID, so the
        // dragged item's ID has to match that same collection.
        const {data} = await axios.get(clockWheelsListUrl.value);
        const rows = (data.rows ?? data ?? []) as Array<Record<string, unknown>>;
        clockWheelsForDrag.value = rows.map((row) => ({
            id: row.id as number,
            name: row.name as string,
        }));
    } catch {
        // Non-critical -- the drag panel just stays empty.
    }
};

const onEntityDrop = (payload: {
    entityId: number,
    entityType: 'playlist' | 'smart_block' | 'clock_wheel',
    start: Date,
    end: Date | null,
}) => {
    $createEventModal.value?.openForDrop(
        payload.entityId,
        payload.start,
        payload.end ?? undefined,
        payload.entityType,
    );
};

onMounted(() => {
    void loadSmartBlocksForDrag();
    void loadClockWheelTemplatesForDrag();
    void loadPlaylistsForDrag();
});

const relist = () => {
    $scheduleTab.value?.refresh();
    void loadSmartBlocksForDrag();
    void loadClockWheelTemplatesForDrag();
    void loadPlaylistsForDrag();
};
</script>
