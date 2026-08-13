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
                <div class="nav-item" role="presentation">
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
                <div class="nav-item" role="presentation">
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
                <div class="nav-item" role="presentation">
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
                <!-- ── Toolbar row ── -->
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <!-- Left cluster -->
                    <div class="d-flex flex-wrap gap-2 flex-grow-1">
                        <!-- Create Event moved out of calendar overlay -->
                        <button
                            type="button"
                            class="btn btn-primary btn-sm rounded-pill shadow-sm"
                            @click="doCreateEvent"
                        >
                            <icon-ic-add class="me-1" />
                            {{ $gettext('Create Event') }}
                        </button>

                        <!-- Show/Hide drag sources -->
                        <button
                            type="button"
                            class="btn btn-sm rounded-pill shadow-sm"
                            :class="sidebarVisible ? 'btn-secondary' : 'btn-outline-secondary'"
                            @click="sidebarVisible = !sidebarVisible"
                        >
                            <icon-ic-playlist-add class="me-1" />
                            <span class="d-none d-sm-inline">
                                {{ sidebarVisible ? $gettext('Hide Sources') : $gettext('Show Sources') }}
                            </span>
                            <span class="d-sm-none">
                                {{ sidebarVisible ? $gettext('Hide') : $gettext('Sources') }}
                            </span>
                        </button>

                        <!-- Conflict indicator -->
                        <span
                            v-if="hasConflicts"
                            class="badge bg-danger d-flex align-items-center gap-1 px-2 py-1 rounded-pill"
                            style="font-size: 0.78rem;"
                        >
                            ⚠ {{ $gettext('Schedule conflicts detected') }}
                        </span>
                    </div>

                    <!-- Undo toast -->
                    <transition name="fade">
                        <div
                            v-if="undoToast.visible"
                            class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-dark text-white shadow-sm"
                            style="font-size: 0.85rem; white-space: nowrap;"
                        >
                            <span>{{ undoToast.message }}</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-light rounded-pill py-0 px-2"
                                style="font-size: 0.8rem;"
                                @click="doUndo"
                            >
                                {{ $gettext('Undo') }}
                            </button>
                        </div>
                    </transition>
                </div>

                <!-- ── Calendar + optional sidebar ── -->
                <div class="row g-0">
                    <!-- Calendar column -->
                    <div :class="sidebarVisible ? 'col-12 col-lg-9' : 'col-12'">
                        <schedule-calendar
                            ref="$scheduleTab"
                            :schedule-url="[scheduleUrl, clockWheelsScheduleUrl]"
                            :show-create-button="false"
                            external-drag-selector=".smart-block-drag-item, .clock-wheel-drag-item"
                            @click="doCalendarClick"
                            @create="doCreateEvent"
                            @date-click="doDateClick"
                            @drop-external="onEntityDrop"
                            @event-move="onEventMove"
                            @delete-event="onDeleteEvent"
                            @duplicate-event="onDuplicateEvent"
                        />
                    </div>

                    <!-- Drag-sources sidebar -->
                    <div
                        v-if="sidebarVisible"
                        class="col-12 col-lg-3 ps-lg-3 mt-3 mt-lg-0"
                    >
                        <!-- Playlists panel -->
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
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <!-- color dot -->
                                        <span
                                            class="flex-shrink-0 rounded-circle"
                                            style="width:10px;height:10px;background:var(--az-playlist-color,#3b82f6);"
                                        />
                                        <span class="text-truncate">{{ playlist.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag onto the calendar to schedule.') }}
                                </div>
                            </template>
                        </div>

                        <!-- Smart Blocks panel -->
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
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <span
                                            class="flex-shrink-0 rounded-circle"
                                            style="width:10px;height:10px;background:#8b5cf6;"
                                        />
                                        <span class="text-truncate">{{ block.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag onto the calendar to schedule.') }}
                                </div>
                            </template>
                        </div>

                        <!-- Clock Wheels panel -->
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
                                        {{ $gettext('No Clock Wheels yet.') }}
                                    </li>
                                    <li
                                        v-for="wheel in clockWheelsForDrag"
                                        :key="`cw-${wheel.id}`"
                                        class="clock-wheel-drag-item list-group-item d-flex align-items-center gap-2 small"
                                        style="cursor: grab;"
                                        :data-entity-id="wheel.id"
                                        :data-entity-type="'clock_wheel'"
                                    >
                                        <icon-ic-drag-indicator class="flex-shrink-0 text-muted" />
                                        <span
                                            class="flex-shrink-0 rounded-circle"
                                            style="width:10px;height:10px;background:#f59e0b;"
                                        />
                                        <span class="text-truncate">{{ wheel.name }}</span>
                                    </li>
                                </ul>
                                <div class="card-footer text-muted small">
                                    {{ $gettext('Drag onto the calendar to schedule.') }}
                                </div>
                            </template>
                        </div>

                        <!-- Legend -->
                        <div class="card mt-3">
                            <div class="card-body p-2">
                                <div class="small fw-semibold mb-2 text-muted">{{ $gettext('Color Legend') }}</div>
                                <div class="d-flex flex-column gap-1">
                                    <div class="d-flex align-items-center gap-2 small">
                                        <span class="rounded-circle flex-shrink-0" style="width:10px;height:10px;background:#3b82f6;"></span>
                                        {{ $gettext('Playlist') }}
                                    </div>
                                    <div class="d-flex align-items-center gap-2 small">
                                        <span class="rounded-circle flex-shrink-0" style="width:10px;height:10px;background:#8b5cf6;"></span>
                                        {{ $gettext('Smart Block') }}
                                    </div>
                                    <div class="d-flex align-items-center gap-2 small">
                                        <span class="rounded-circle flex-shrink-0" style="width:10px;height:10px;background:#f59e0b;"></span>
                                        {{ $gettext('Clock Wheel') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Quick-drop panel (appears near cursor after drag, no big modal) ── -->
        <Teleport to="body">
            <transition name="qp-fade">
                <div
                    v-if="quickPanel.visible"
                    class="quick-drop-panel card shadow-lg"
                    :style="{
                        position: 'fixed',
                        top: quickPanel.y + 'px',
                        left: quickPanel.x + 'px',
                        zIndex: 1090,
                        minWidth: '270px',
                        maxWidth: '310px',
                    }"
                >
                    <div class="card-header p-2 d-flex align-items-center gap-2">
                        <span
                            class="rounded-circle flex-shrink-0"
                            :style="`width:10px;height:10px;background:${quickPanel.color};`"
                        />
                        <span class="fw-semibold small text-truncate flex-grow-1">{{ quickPanel.entityName }}</span>
                        <button
                            type="button"
                            class="btn-close btn-close-sm"
                            @click="cancelQuickDrop"
                        />
                    </div>
                    <div class="card-body p-2 d-flex flex-column gap-2">
                        <!-- Duration -->
                        <div>
                            <label class="form-label small fw-semibold mb-1">
                                {{ $gettext('Duration') }}
                            </label>
                            <select
                                v-model="quickPanel.durationMinutes"
                                class="form-select form-select-sm"
                            >
                                <option :value="30">{{ $gettext('30 minutes') }}</option>
                                <option :value="60">{{ $gettext('1 hour') }}</option>
                                <option :value="90">{{ $gettext('1.5 hours') }}</option>
                                <option :value="120">{{ $gettext('2 hours') }}</option>
                                <option :value="180">{{ $gettext('3 hours') }}</option>
                                <option :value="240">{{ $gettext('4 hours') }}</option>
                                <option :value="360">{{ $gettext('6 hours') }}</option>
                                <option :value="480">{{ $gettext('8 hours') }}</option>
                            </select>
                        </div>

                        <!-- Repeat -->
                        <div>
                            <label class="form-label small fw-semibold mb-1">
                                {{ $gettext('Repeat') }}
                            </label>
                            <select
                                v-model="quickPanel.recurrence"
                                class="form-select form-select-sm"
                            >
                                <option value="once">{{ $gettext('One-time only') }}</option>
                                <option value="weekly">{{ $gettext('Every week on %{day}', {day: quickPanel.dayName}) }}</option>
                                <option value="daily">{{ $gettext('Every day') }}</option>
                                <option value="biweekly">{{ $gettext('Every 2 weeks on %{day}', {day: quickPanel.dayName}) }}</option>
                                <option value="monthly">{{ $gettext('Monthly') }}</option>
                            </select>
                        </div>

                        <!-- Drop time display -->
                        <div class="text-muted small">
                            {{ quickPanel.timeLabel }}
                        </div>

                        <!-- Actions -->
                        <div class="d-flex gap-2 mt-1">
                            <button
                                type="button"
                                class="btn btn-primary btn-sm flex-grow-1"
                                :disabled="quickPanel.saving"
                                @click="confirmQuickDrop"
                            >
                                <span
                                    v-if="quickPanel.saving"
                                    class="spinner-border spinner-border-sm me-1"
                                />
                                {{ $gettext('Schedule It') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                @click="cancelQuickDrop"
                            >
                                {{ $gettext('Cancel') }}
                            </button>
                        </div>

                        <div
                            v-if="quickPanel.error"
                            class="text-danger small"
                        >
                            {{ quickPanel.error }}
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

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
import {useLuxon} from "~/vendor/luxon";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import IconIcDragIndicator from "~icons/ic/baseline-drag-indicator";
import IconIcPlaylistAdd from "~icons/ic/baseline-playlist-add";
import IconIcChevronDown from "~icons/ic/baseline-keyboard-arrow-down";
import IconIcAdd from "~icons/ic/baseline-add";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();

const activeTab = ref<'calendar' | 'live' | 'holidays'>('calendar');
const sidebarVisible = ref<boolean>(false);
const hasConflicts = ref<boolean>(false);

watch(sidebarVisible, async () => {
    await nextTick();
    $scheduleTab.value?.updateSize();
});

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

const doDateClick = (date: Date) => {
    $createEventModal.value?.openAtTime(date);
};

// ── Undo toast ──────────────────────────────────────────────────────────────
type UndoAction = { type: 'delete', entityType: string, entityId: number, scheduleId: number };

const undoToast = reactive({
    visible: false,
    message: '',
    action: null as UndoAction | null,
    timer: null as ReturnType<typeof setTimeout> | null,
});

const showUndoToast = (message: string, action: UndoAction) => {
    if (undoToast.timer) clearTimeout(undoToast.timer);
    undoToast.message = message;
    undoToast.action = action;
    undoToast.visible = true;
    undoToast.timer = setTimeout(() => {
        undoToast.visible = false;
        undoToast.action = null;
    }, 6000);
};

const {axios} = useAxios();
const {confirmDelete} = useDialog();

const doUndo = async () => {
    if (!undoToast.action) return;
    undoToast.visible = false;
    if (undoToast.timer) clearTimeout(undoToast.timer);
    // Undo is currently supported for deletes — re-open the edit modal for that event
    // so the user can recreate it (full restore would require saving the payload).
    relist();
};

// ── Delete from hover popover ────────────────────────────────────────────────
const onDeleteEvent = async (event: EventImpl) => {
    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleId = Number(event.extendedProps.schedule_id);
    const eventName = event.title ?? $gettext('this event');

    if (!editUrl || !scheduleId) return;

    const m = editUrl.match(/\/(playlist|clock-wheel)\/(\d+)/);
    if (!m) return;
    const entityId = Number(m[2]);
    const apiType = m[1];
    const baseUrl = editUrl.replace(/\/schedule\/\d+$/, '');

    // Fetch entity first so we can read recurrence_type from the actual
    // schedule item record — the calendar event extendedProps don't include it.
    let existing: any[] = [];
    let isRecurring = false;
    try {
        const {data: entityData} = await axios.get(baseUrl);
        existing = (entityData.schedule_items as any[]) ?? [];
        const targetItem = existing.find((r: any) => Number(r?.id) === scheduleId);
        isRecurring = !!(targetItem?.recurrence_type);
    } catch {
        return;
    }

    const confirmTitle = isRecurring
        ? $gettext('Delete ALL occurrences of "%{name}"? This removes every occurrence, not just this one.', {name: eventName})
        : $gettext('Delete "%{name}"?', {name: eventName});

    const {value} = await confirmDelete({title: confirmTitle});
    if (!value) return;

    try {
        const updated = existing.filter((r: any) => Number(r?.id) !== scheduleId);
        await axios.put(baseUrl, {schedule_items: updated});

        showUndoToast(
            $gettext('Event deleted.'),
            {type: 'delete', entityType: apiType, entityId, scheduleId}
        );
        relist();
    } catch {
        // Error surfaces via global interceptor
    }
};

// ── Move/resize existing calendar events (drag-to-move / drag-to-resize) ─────
const onEventMove = async (payload: {event: EventImpl, newStart: Date, newEnd: Date | null, revert: () => void}) => {
    const {event, newStart, newEnd, revert} = payload;
    const eventName = event.title ?? $gettext('this event');

    // autoSaveMove fetches the entity to build the updated payload — we ask it
    // to check recurrence first and hand back that flag so we can confirm before saving.
    const check = await $createEventModal.value?.checkIsRecurring(event);
    if (check?.isRecurring) {
        const {value} = await confirmDelete({
            title: $gettext('Move ALL occurrences of "%{name}"? This changes the time for every occurrence.', {name: eventName}),
            confirmButtonText: $gettext('Move All'),
            confirmButtonClass: 'btn-primary',
        });
        if (!value) {
            revert();
            return;
        }
    }

    const result = await $createEventModal.value?.autoSaveMove(event, newStart, newEnd);
    if (!result?.success) {
        revert();
    }
};

// ── Duplicate from hover popover ─────────────────────────────────────────────
const onDuplicateEvent = (event: EventImpl) => {
    // Open Create Event modal pre-filled with the same entity as the source event
    const editUrl = event.extendedProps.edit_url as string | undefined;
    if (!editUrl) return;

    const m = editUrl.match(/\/(playlist|clock-wheel)\/(\d+)/);
    if (!m) return;

    const entityId = Number(m[2]);
    const source = m[1] === 'clock-wheel' ? 'clock_wheel' : 'playlist';

    $createEventModal.value?.openScopedForCreate(source as 'playlist' | 'clock_wheel', entityId);
};

// ── Drag sources ─────────────────────────────────────────────────────────────
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
    } catch { /* non-critical */ }
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
    } catch { /* non-critical */ }
};

const loadClockWheelTemplatesForDrag = async () => {
    try {
        const {data} = await axios.get(clockWheelsListUrl.value);
        const rows = (data.rows ?? data ?? []) as Array<Record<string, unknown>>;
        clockWheelsForDrag.value = rows.map((row) => ({
            id: row.id as number,
            name: row.name as string,
        }));
    } catch { /* non-critical */ }
};

// ── Quick-drop panel ─────────────────────────────────────────────────────────
const {DateTime} = useLuxon();
const stationData = useStationData();
const {timezone: stationTimezone} = toRefs(stationData);

const SOURCE_COLORS: Record<string, string> = {
    playlist: '#3b82f6',
    smart_block: '#8b5cf6',
    clock_wheel: '#f59e0b',
};

const quickPanel = reactive({
    visible: false,
    saving: false,
    error: '',
    entityId: 0,
    entityType: 'playlist' as 'playlist' | 'smart_block' | 'clock_wheel',
    entityName: '',
    color: '#3b82f6',
    start: null as Date | null,
    dayName: '',
    timeLabel: '',
    durationMinutes: 60,
    recurrence: 'weekly' as string,
    x: 0,
    y: 0,
});

const openQuickPanel = (payload: {
    entityId: number,
    entityType: 'playlist' | 'smart_block' | 'clock_wheel',
    start: Date,
    mouseX: number,
    mouseY: number,
}) => {
    const dt = DateTime.fromJSDate(payload.start, {zone: stationTimezone.value});
    const entityName = [
        ...playlistsForDrag.value,
        ...smartBlocksForDrag.value,
        ...clockWheelsForDrag.value,
    ].find(e => e.id === payload.entityId)?.name ?? '';

    quickPanel.entityId = payload.entityId;
    quickPanel.entityType = payload.entityType;
    quickPanel.entityName = entityName;
    quickPanel.color = SOURCE_COLORS[payload.entityType] ?? '#3b82f6';
    quickPanel.start = payload.start;
    quickPanel.dayName = dt.toFormat('cccc');
    quickPanel.timeLabel = dt.toFormat('cccc, LLLL d') + ' at ' + dt.toFormat('h:mm a');
    quickPanel.durationMinutes = payload.entityType === 'clock_wheel' ? 60 : 60;
    quickPanel.recurrence = 'weekly';
    quickPanel.error = '';
    quickPanel.saving = false;

    // Position panel near cursor, clamped to viewport
    const panelW = 310;
    const panelH = 280;
    quickPanel.x = Math.min(payload.mouseX + 12, window.innerWidth - panelW - 12);
    quickPanel.y = Math.min(payload.mouseY - 20, window.innerHeight - panelH - 12);
    quickPanel.visible = true;
};

const cancelQuickDrop = () => {
    quickPanel.visible = false;
};

const confirmQuickDrop = async () => {
    if (!quickPanel.start) return;
    quickPanel.saving = true;
    quickPanel.error = '';

    const dt = DateTime.fromJSDate(quickPanel.start, {zone: stationTimezone.value});
    let recurrenceType: string | null = null;
    let days: number[] = [];

    switch (quickPanel.recurrence) {
        case 'once':
            recurrenceType = null;
            days = [];
            break;
        case 'weekly':
            recurrenceType = 'weekly';
            days = [dt.weekday]; // ISO: 1=Mon…7=Sun
            break;
        case 'biweekly':
            recurrenceType = 'biweekly';
            days = [dt.weekday];
            break;
        case 'daily':
            recurrenceType = 'weekly';
            days = [1, 2, 3, 4, 5, 6, 7];
            break;
        case 'monthly':
            recurrenceType = 'monthly';
            days = [dt.weekday];
            break;
    }

    const result = await $createEventModal.value?.autoSaveFromDrop(
        quickPanel.entityId,
        quickPanel.start,
        quickPanel.durationMinutes,
        recurrenceType,
        days,
        quickPanel.entityType,
    );

    quickPanel.saving = false;

    if (result?.success) {
        quickPanel.visible = false;
        showUndoToast(
            $gettext('Scheduled: %{name}', {name: quickPanel.entityName}),
            {type: 'delete', entityType: quickPanel.entityType === 'clock_wheel' ? 'clock-wheel' : 'playlist', entityId: quickPanel.entityId, scheduleId: 0}
        );
    } else {
        quickPanel.error = $gettext('Could not save — time conflict or error. Try different settings or use Create Event for full options.');
    }
};

const onEntityDrop = (payload: {
    entityId: number,
    entityType: 'playlist' | 'smart_block' | 'clock_wheel',
    start: Date,
    end: Date | null,
    mouseX: number,
    mouseY: number,
}) => {
    openQuickPanel({
        entityId: payload.entityId,
        entityType: payload.entityType,
        start: payload.start,
        mouseX: payload.mouseX,
        mouseY: payload.mouseY,
    });
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

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.qp-fade-enter-active,
.qp-fade-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.qp-fade-enter-from,
.qp-fade-leave-to {
    opacity: 0;
    transform: scale(0.95) translateY(-4px);
}
</style>
