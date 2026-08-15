<template>
    <div
        class="card-body-flush"
        style="position: relative;"
        @click.self="overlayProps.visible = false"
    >
        <schedule
            ref="$schedule"
            :options="calendarOptions"
        />
    </div>

    <Teleport to="body">
        <div
            v-if="overlayProps.visible && overlayProps.event"
            ref="$overlay"
            class="schedule-event-overlay card position-absolute shadow-lg"
            role="tooltip"
            @mouseenter="cancelHide"
            @mouseleave="scheduleHide"
        >
            <!-- Header -->
            <div class="card-header d-flex align-items-center gap-2 p-2 border-bottom border-2"
                 :style="`background: ${overlayProps.eventColor}18; border-left: 4px solid ${overlayProps.eventColor} !important;`"
            >
                <playlist-source-icon
                    v-if="overlayProps.event.extendedProps.source"
                    :source="overlayProps.event.extendedProps.source"
                />
                <span class="fw-bold flex-grow-1 text-truncate">{{ overlayProps.event.title }}</span>
                <span
                    v-if="overlayProps.headerCount !== null"
                    class="badge text-bg-light rounded-pill shadow-none"
                >{{ overlayProps.headerCount }}</span>
            </div>

            <!-- Body -->
            <div class="card-body p-2 d-flex flex-column gap-2">
                <div
                    v-if="overlayProps.event.extendedProps.group_schedule_warning"
                    class="d-flex align-items-start gap-2 text-warning"
                >
                    <span class="flex-shrink-0 mt-1">⚠</span>
                    <span class="small">
                        {{ $gettext("This playlist only plays while its group is scheduled. Its current schedule falls outside the group's window, so it will not play during this time.") }}
                    </span>
                </div>

                <div
                    v-if="overlayProps.event.extendedProps.total_length"
                    class="text-muted small"
                >
                    {{ formatSeconds(overlayProps.event.extendedProps.total_length) }}
                </div>

                <div class="d-flex flex-wrap gap-1">
                    <span
                        v-if="overlayProps.event.extendedProps.order"
                        class="badge text-bg-secondary"
                    >
                        {{ getOrderLabel(overlayProps.event.extendedProps.order) }}
                    </span>
                    <span
                        v-if="overlayProps.rotationLabel"
                        class="badge text-bg-secondary"
                    >
                        {{ overlayProps.rotationLabel }}
                    </span>
                    <span
                        v-if="overlayProps.event.extendedProps.avoid_duplicates"
                        class="badge text-bg-info"
                    >
                        {{ $gettext('Avoid Duplicates') }}
                    </span>
                    <span
                        v-if="overlayProps.event.extendedProps.is_jingle"
                        class="badge text-bg-info"
                    >
                        {{ $gettext('Jingle Mode') }}
                    </span>
                    <!-- Source type badge -->
                    <span
                        class="badge"
                        :style="`background:${overlayProps.eventColor}; color:#fff;`"
                    >
                        {{ overlayProps.sourceLabel }}
                    </span>
                </div>
            </div>

            <!-- Member list -->
            <ul
                v-if="overlayProps.members && overlayProps.members.length > 0"
                class="list-group list-group-flush overflow-y-auto border-top"
                style="max-height: 16rem;"
            >
                <li
                    v-for="member in overlayProps.members"
                    :key="member.id"
                    class="list-group-item d-flex align-items-center gap-2 p-2"
                >
                    <playlist-source-icon :source="member.source ?? 'songs'" />
                    <span class="flex-grow-1 text-truncate">{{ member.name }}</span>
                    <span
                        v-if="member.consecutive_plays > 0 || member.play_full_cycle"
                        class="badge text-bg-secondary d-inline-flex align-items-center gap-1"
                    >
                        ↻
                        {{
                            member.play_full_cycle
                                ? $gettext('Plays fully')
                                : $gettext('Plays %{count}', {count: member.consecutive_plays})
                        }}
                    </span>
                </li>
            </ul>

            <!-- Quick action buttons -->
            <div
                v-if="!overlayProps.event.extendedProps.group_schedule_warning && overlayProps.event.extendedProps.edit_url"
                class="card-footer d-flex gap-2 p-2"
                style="background: transparent;"
            >
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary flex-grow-1"
                    @click="onOverlayEdit"
                >
                    ✏ {{ $gettext('Edit') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary flex-grow-1"
                    @click="onOverlayDuplicate"
                >
                    ⧉ {{ $gettext('Duplicate') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger flex-grow-1"
                    @click="onOverlayDelete"
                >
                    🗑 {{ $gettext('Delete') }}
                </button>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import Schedule from "~/components/Common/ScheduleView.vue";
import PlaylistSourceIcon from "~/components/Stations/Common/PlaylistSourceIcon.vue";
import {createPopper, Instance} from "@popperjs/core";
import {useTimeoutFn} from "@vueuse/core";
import {Calendar, EventClickArg, EventHoveringArg, EventMountArg} from "@fullcalendar/core";
import {Draggable} from "@fullcalendar/interaction";
import {EventImpl} from "@fullcalendar/core/internal";
import {computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useTemplateRef, toValue, watch} from "vue";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useTranslate} from "~/vendor/gettext";
import {useLuxon} from "~/vendor/luxon";
import {PlaylistOrders, PlaylistTypes} from "~/entities/ApiInterfaces.ts";

const props = withDefaults(defineProps<{
    scheduleUrl: string | string[],
    showCreateButton?: boolean,
    externalDragSelector?: string,
}>(), {
    showCreateButton: false,
    externalDragSelector: undefined,
});

const emit = defineEmits<{
    click: [event: EventImpl],
    create: [],
    dateClick: [date: Date],
    dropExternal: [payload: {entityId: number, entityType: 'playlist' | 'smart_block' | 'clock_wheel', start: Date, end: Date | null, mouseX: number, mouseY: number}],
    eventMove: [payload: {event: EventImpl, newStart: Date, newEnd: Date | null, revert: () => void}],
    deleteEvent: [event: EventImpl],
    duplicateEvent: [event: EventImpl],
}>();

const {$gettext} = useTranslate();
const {Duration} = useLuxon();

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const formatSeconds = (seconds: number): string => {
    if (!seconds) return '';
    return Duration.fromMillis(seconds * 1000).rescale().toHuman();
};

const getOrderLabel = (order: string): string => {
    switch (order) {
        case PlaylistOrders.Shuffle: return $gettext('Shuffle');
        case PlaylistOrders.Sequential: return $gettext('Sequential');
        case PlaylistOrders.Random: return $gettext('Random');
        default: return '';
    }
};

// ── Source type color + label ─────────────────────────────────────────────────
const sourceColor = (ep: Record<string, any>): string => {
    if (ep.is_clock_wheel) return '#f59e0b';
    if (ep.is_smart_block) return '#8b5cf6';
    return '#3b82f6';
};

const sourceLabel = (ep: Record<string, any>): string => {
    if (ep.is_clock_wheel) return $gettext('Clock Wheel');
    if (ep.is_smart_block) return $gettext('Smart Block');
    return $gettext('Playlist');
};

// ── Overlay ───────────────────────────────────────────────────────────────────
type OverlayMember = {id: number, name: string, source?: string, consecutive_plays: number, play_full_cycle: boolean};

const overlayProps = reactive<{
    visible: boolean,
    event: EventImpl | null,
    referenceEl: HTMLElement | null,
    headerCount: number | null,
    rotationLabel: string,
    members: OverlayMember[],
    eventColor: string,
    sourceLabel: string,
}>({
    visible: false,
    event: null,
    referenceEl: null,
    headerCount: null,
    rotationLabel: '',
    members: [],
    eventColor: '#3b82f6',
    sourceLabel: '',
});

const $overlay = useTemplateRef<HTMLElement>('$overlay');

let popper: Instance | null = null;

const destroyPopper = () => {
    popper?.destroy();
    popper = null;
};

const buildOverlay = async (event: EventImpl, el: HTMLElement) => {
    const ep = event.extendedProps;

    let headerCount: number | null = null;
    if (ep.source === 'songs') headerCount = ep.num_songs ?? 0;
    if (ep.source === 'group') headerCount = (ep.members ?? []).length;

    let rotationLabel = '';
    switch (ep.playlist_type) {
        case PlaylistTypes.Standard:
            rotationLabel = $gettext('General Rotation (%{weight})', {weight: ep.weight ?? 0});
            break;
        case PlaylistTypes.OncePerXSongs:
            rotationLabel = $gettext('Once per %{songs} Songs', {songs: ep.play_per_songs ?? 0});
            break;
        case PlaylistTypes.OncePerXMinutes:
            rotationLabel = $gettext('Once per %{minutes} Minutes', {minutes: ep.play_per_minutes ?? 0});
            break;
        case PlaylistTypes.OncePerHour:
            rotationLabel = $gettext('Once per Hour');
            break;
    }

    overlayProps.event = event;
    overlayProps.referenceEl = el;
    overlayProps.headerCount = headerCount;
    overlayProps.rotationLabel = rotationLabel;
    overlayProps.members = (ep.members ?? []) as OverlayMember[];
    overlayProps.eventColor = sourceColor(ep);
    overlayProps.sourceLabel = sourceLabel(ep);
    overlayProps.visible = true;

    await nextTick();

    if ($overlay.value) {
        destroyPopper();
        popper = createPopper(el, $overlay.value, {
            placement: 'auto',
            modifiers: [
                {name: 'offset', options: {offset: [0, 8]}},
                {name: 'preventOverflow', options: {padding: 8}},
                {name: 'flip', options: {fallbackPlacements: ['top', 'bottom', 'left', 'right']}},
            ],
        });
    }
};

const {start: scheduleHide, stop: cancelHide} = useTimeoutFn(() => {
    overlayProps.visible = false;
    destroyPopper();
}, 200, {immediate: false});

// ── Overlay quick actions ─────────────────────────────────────────────────────
const onOverlayEdit = () => {
    if (!overlayProps.event) return;
    overlayProps.visible = false;
    destroyPopper();
    emit('editEvent', overlayProps.event);
};

const onOverlayDelete = () => {
    if (!overlayProps.event) return;
    const ev = overlayProps.event;
    overlayProps.visible = false;
    destroyPopper();
    emit('deleteEvent', ev);
};

const onOverlayDuplicate = () => {
    if (!overlayProps.event) return;
    const ev = overlayProps.event;
    overlayProps.visible = false;
    destroyPopper();
    emit('duplicateEvent', ev);
};

// ── Calendar options ──────────────────────────────────────────────────────────
const calendarOptions = computed(() => {
    const rawUrls = props.scheduleUrl;
    const urls = Array.isArray(rawUrls)
        ? rawUrls.map(u => toValue(u))
        : [toValue(rawUrls)];
    return {
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: 'timeGridWeek,timeGridDay'
        },
        timeZone: timezone.value,
        eventSources: urls,
        eventMouseEnter: onMouseEnter,
        eventMouseLeave: onMouseLeave,
        eventClick: onClick,
        eventDidMount: onEventMount,
        dateClick: (arg: {date: Date}) => emit('dateClick', arg.date),
        droppable: true,
        drop: onExternalDrop,
        editable: true,
        eventDrop: onEventDrop,
        eventResize: onEventResize,
    };
});

const onMouseEnter = (arg: EventHoveringArg) => {
    cancelHide();
    void buildOverlay(arg.event, arg.el);
};

const onMouseLeave = (_arg: EventHoveringArg) => {
    scheduleHide();
};

const onClick = (arg: EventClickArg) => {
    if (arg.event.extendedProps.group_schedule_warning) {
        void buildOverlay(arg.event, arg.el);
        return;
    }
    overlayProps.visible = false;
    destroyPopper();
    emit('click', arg.event);
};

// ── Color coding on mount ─────────────────────────────────────────────────────
const onEventMount = (arg: EventMountArg) => {
    const ep = arg.event.extendedProps;

    // Color by source type
    const color = sourceColor(ep);
    arg.el.style.setProperty('--fc-event-bg-color', color);
    arg.el.style.setProperty('--fc-event-border-color', color);
    arg.el.style.backgroundColor = color;
    arg.el.style.borderColor = color;

    // Disabled playlist styling
    if (ep.is_enabled === false) {
        arg.el.style.opacity = '0.5';
        arg.el.style.backgroundImage = 'repeating-linear-gradient(45deg, transparent, transparent 6px, rgba(255,255,255,0.15) 6px, rgba(255,255,255,0.15) 12px)';
        arg.el.title = (arg.el.title ? arg.el.title + ' — ' : '') + 'This playlist is disabled and will not play.';
    }

    // Source icon
    if (ep.source) {
        const iconMap: Record<string, string> = {
            songs: '♫',
            playlists: '♫♫',
            requests: '👥',
            remote_url: '🌐',
        };
        const icon = iconMap[ep.source];
        if (icon) {
            const iconEl = document.createElement('span');
            iconEl.textContent = icon + ' ';
            iconEl.style.cssText = 'font-size: 0.75em; opacity: 0.85;';
            const titleEl = arg.el.querySelector('.fc-event-title');
            if (titleEl) titleEl.prepend(iconEl);
        }
    }

    // Group warning indicator
    if (ep.group_schedule_warning) {
        const warnEl = document.createElement('span');
        warnEl.textContent = ' ⚠';
        warnEl.style.cssText = 'color: #ffc107;';
        const titleEl = arg.el.querySelector('.fc-event-title');
        if (titleEl) titleEl.appendChild(warnEl);
        arg.el.style.opacity = '0.8';
    }
};

// ── Drag-to-move existing events ──────────────────────────────────────────────
const onEventDrop = (info: any) => {
    emit('eventMove', {
        event: info.event as EventImpl,
        newStart: info.event.start as Date,
        newEnd: info.event.end as Date | null,
        revert: () => info.revert(),
    });
};

// ── Drag-to-resize existing events ───────────────────────────────────────────
const onEventResize = (info: any) => {
    emit('eventMove', {
        event: info.event as EventImpl,
        newStart: info.event.start as Date,
        newEnd: info.event.end as Date | null,
        revert: () => info.revert(),
    });
};

// ── External drag source init ─────────────────────────────────────────────────
const $schedule = useTemplateRef('$schedule');

const getCalendarApi = (): Calendar | undefined => {
    return $schedule.value?.getCalendarApi();
};

const purgeUnsavedEvents = () => {
    const api = getCalendarApi();
    api?.getEvents().forEach((ev) => {
        const hasRealId = Boolean(ev.extendedProps?.schedule_id ?? ev.extendedProps?.edit_url);
        if (!hasRealId) {
            ev.remove();
        }
    });
};

const refresh = () => {
    getCalendarApi()?.refetchEvents();
    purgeUnsavedEvents();
};

const updateSize = async () => {
    await nextTick();
    getCalendarApi()?.updateSize();
};

let draggable: Draggable | null = null;

const initExternalDraggable = () => {
    if (!props.externalDragSelector) return;
    draggable?.destroy();
    draggable = new Draggable(document.body, {
        itemSelector: props.externalDragSelector,
    });
};

const onExternalDrop = (info: {draggedEl: HTMLElement, date: Date, jsEvent: MouseEvent}) => {
    const entityId = Number(info.draggedEl.dataset.entityId);
    if (!entityId) return;

    const rawType = info.draggedEl.dataset.entityType;
    const entityType: 'playlist' | 'smart_block' | 'clock_wheel' =
        rawType === 'clock_wheel' || rawType === 'smart_block' ? rawType : 'playlist';

    const start = info.date;
    const end = new Date(start.getTime() + 60 * 60 * 1000);

    requestAnimationFrame(purgeUnsavedEvents);
    emit('dropExternal', {entityId, entityType, start, end, mouseX: info.jsEvent.clientX, mouseY: info.jsEvent.clientY});
};

onMounted(() => {
    initExternalDraggable();
});

watch(() => props.externalDragSelector, () => {
    initExternalDraggable();
});

onBeforeUnmount(() => {
    draggable?.destroy();
});

defineExpose({
    getCalendarApi,
    refresh,
    updateSize
});
</script>

<style scoped>
.schedule-event-overlay {
    z-index: 1070;
    min-width: 16rem;
    max-width: 28rem;
}
</style>

