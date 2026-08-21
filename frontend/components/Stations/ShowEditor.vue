<template>
    <div class="container py-4 show-editor-page">
        <section class="show-editor-hero">
            <div class="show-editor-hero-left">
                <span class="feature-show-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-8 14H7v-4h4v4Zm6 0h-4V7h4v10Zm-6-6H7V7h4v4Z"/>
                    </svg>
                </span>
                <div>
                    <h1>{{ form.id ? $gettext('Edit Show') : $gettext('New Show') }}</h1>
                    <p>{{ $gettext('Build a programme from playlists, folders, tracks, and remote audio.') }}</p>
                </div>
            </div>

            <router-link
                class="btn btn-outline-light show-editor-back"
                :to="{name: 'stations:shows:index'}"
            >
                {{ $gettext('Back to Shows') }}
            </router-link>
        </section>

        <div class="row g-4 align-items-start">
            <div class="col-xl-6">
                <section class="show-editor-card">
                    <header class="show-editor-card-header">
                        <span class="show-editor-card-icon details-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="m3 17.25 9.06-9.06 3.75 3.75L6.75 21H3v-3.75ZM18.71 9.04l-3.75-3.75 1.83-1.83a1 1 0 0 1 1.42 0l2.33 2.33a1 1 0 0 1 0 1.42l-1.83 1.83Z"/>
                            </svg>
                        </span>
                        <div>
                            <h2>{{ $gettext('Details') }}</h2>
                            <p>{{ $gettext('Name and identify this programme') }}</p>
                        </div>
                    </header>

                    <div class="show-editor-card-body">
                        <label class="form-label">{{ $gettext('Show Name') }}</label>
                        <input
                            v-model="form.name"
                            class="form-control mb-3"
                        >

                        <div class="show-editor-switch mb-3">
                            <div>
                                <strong>{{ $gettext('Enable Show') }}</strong>
                                <small>{{ $gettext('Disabled shows stay saved but will not be scheduled.') }}</small>
                            </div>
                            <label class="switch-control">
                                <input
                                    v-model="form.enabled"
                                    type="checkbox"
                                >
                                <span></span>
                            </label>
                        </div>

                        <label class="form-label">{{ $gettext('Description') }}</label>
                        <textarea
                            v-model="form.description"
                            class="form-control mb-3"
                            rows="3"
                        />

                        <label class="form-label">{{ $gettext('Colour') }}</label>
                        <div class="show-colour-row mb-3">
                            <input
                                v-model="form.color"
                                type="color"
                                class="form-control form-control-color"
                            >
                            <input
                                v-model="form.color"
                                class="form-control"
                            >
                        </div>

                        <div class="playout-priority mb-3">
                            <div class="form-label mb-2">{{ $gettext('Playout Priority') }}</div>

                            <label
                                class="priority-choice"
                                :class="{'is-selected': form.priority === 'programme'}"
                            >
                                <input
                                    v-model="form.priority"
                                    type="radio"
                                    value="programme"
                                >
                                <span class="priority-radio"></span>
                                <span>
                                    <strong>{{ $gettext('Show') }}</strong>
                                    <small>{{ $gettext('Starts at the exact scheduled time, interrupting rotation. Use for regular shows.') }}</small>
                                </span>
                            </label>

                            <label
                                class="priority-choice"
                                :class="{'is-selected': form.priority === 'priority'}"
                            >
                                <input
                                    v-model="form.priority"
                                    type="radio"
                                    value="priority"
                                >
                                <span class="priority-radio"></span>
                                <span>
                                    <strong>{{ $gettext('Priority Show') }}</strong>
                                    <small>{{ $gettext('Starts at the exact scheduled time, interrupting everything including other shows.') }}</small>
                                </span>
                            </label>

                            <p class="priority-help">
                                {{ $gettext('Controls how this show interacts with other scheduled content.') }}
                            </p>

                            <div class="priority-order">
                                <strong>{{ $gettext('Priority order: Priority Shows > Shows > Playlists') }}</strong>
                                <small>{{ $gettext('Higher-priority content always interrupts lower-priority content at its scheduled time. Avoid scheduling same-priority items in overlapping time slots.') }}</small>
                            </div>
                        </div>

                        <div class="show-editor-switch">
                            <div>
                                <strong>{{ $gettext('Allow Overrun') }}</strong>
                                <small>{{ $gettext('Let the last segment finish playing instead of cutting it off at the scheduled end.') }}</small>
                            </div>
                            <label class="switch-control">
                                <input
                                    v-model="form.allow_overrun"
                                    type="checkbox"
                                >
                                <span></span>
                            </label>
                        </div>
                    </div>
                </section>

        <section class="show-editor-card mt-4 schedule-card">
            <header class="show-editor-card-header">
                <span class="show-editor-card-icon schedule-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2V2Zm12 8H5v9h14v-9ZM5 8h14V6H5v2Z"/>
                    </svg>
                </span>
                <div>
                    <h2>{{ $gettext('Schedule') }}</h2>
                    <p>{{ $gettext('When this show should play') }}</p>
                </div>
            </header>

            <div class="show-editor-card-body">
                <div
                    v-for="(schedule, index) in form.schedules"
                    :key="index"
                    class="show-schedule-row"
                >
                    <div class="show-schedule-row-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="schedule-number">{{ index + 1 }}</span>
                            <strong>{{ $gettext('Schedule') }}</strong>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger schedule-remove"
                            @click="form.schedules.splice(index, 1)"
                        >
                            {{ $gettext('Remove') }}
                        </button>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label">{{ $gettext('Start Time') }}</label>
                            <input
                                v-model="schedule.start_time"
                                type="time"
                                class="form-control"
                            >
                            <small class="schedule-help">{{ $gettext('To play once per day, set start and end to the same value.') }}</small>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">{{ $gettext('End Time') }}</label>
                            <input
                                v-model="schedule.end_time"
                                type="time"
                                class="form-control"
                            >
                            <small class="schedule-help">{{ $gettext('If the end time is before the start time, the show continues overnight.') }}</small>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label">{{ $gettext('Start Date') }}</label>
                            <input
                                v-model="schedule.start_date"
                                type="date"
                                class="form-control"
                            >
                            <small class="schedule-help">{{ $gettext('Optional. Limit this schedule to run from a specific date.') }}</small>
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label">{{ $gettext('End Date') }}</label>
                            <input
                                v-model="schedule.end_date"
                                type="date"
                                class="form-control"
                            >
                            <small class="schedule-help">{{ $gettext('Optional. Limit this schedule to stop on a specific date.') }}</small>
                        </div>

                        <div class="col-12">
                            <div class="schedule-loop-toggle">
                                <div>
                                    <strong>{{ $gettext('Loop Once') }}</strong>
                                    <small>{{ $gettext('Only loop through the show sequence once during this scheduled window.') }}</small>
                                </div>
                                <label class="switch-control">
                                    <input
                                        v-model="schedule.loop_once"
                                        type="checkbox"
                                    >
                                    <span></span>
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">{{ $gettext('Scheduled Play Days') }}</label>
                            <div class="show-day-buttons">
                                <button
                                    v-for="day in days"
                                    :key="day.n"
                                    type="button"
                                    :class="{'is-active': schedule.days.includes(day.n)}"
                                    @click="toggleDay(schedule, day.n)"
                                >
                                    {{ day.t }}
                                </button>
                            </div>
                            <div class="small text-muted mt-2">
                                {{ $gettext('Leave blank to play every day of the week.') }}
                            </div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-outline-primary"
                    @click="addSchedule"
                >
                    + {{ $gettext('Add Schedule Item') }}
                </button>
            </div>
        </section>
            </div>

            <div class="col-xl-6">
                <section class="show-editor-card segments-card">
                    <header class="show-editor-card-header segments-header">
                        <span class="show-editor-card-icon segments-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M4 5h12v2H4V5Zm0 6h12v2H4v-2Zm0 6h8v2H4v-2Zm14-7V4h2v6h-2Zm0 10v-6h2v6h-2Z"/>
                            </svg>
                        </span>
                        <div>
                            <h2>{{ $gettext('Segments') }}</h2>
                            <p>{{ $gettext('Define the running order of content') }}</p>
                        </div>
                    </header>

                    <div class="show-editor-card-body">
                        <p class="segment-help">
                            {{ $gettext('Define the sequence of content for this show. Segments play in order and repeat to fill the scheduled slot. Use “Repeat from Here” to play everything above once, then loop everything below.') }}
                        </p>

                        <div class="segment-add-buttons">
                            <button
                                type="button"
                                class="segment-add segment-playlist"
                                @click="addSegment('playlist')"
                            >
                                + {{ $gettext('Add from Playlist') }}
                            </button>
                            <button
                                type="button"
                                class="segment-add segment-folder"
                                @click="addSegment('folder')"
                            >
                                + {{ $gettext('Add from Folder') }}
                            </button>
                            <button
                                type="button"
                                class="segment-add segment-track"
                                @click="addSegment('track')"
                            >
                                + {{ $gettext('Add Specific Track') }}
                            </button>
                            <button
                                type="button"
                                class="segment-add segment-remote"
                                @click="addSegment('remote')"
                            >
                                + {{ $gettext('Add Remote URL') }}
                            </button>
                            <button
                                type="button"
                                class="segment-add segment-repeat"
                                :disabled="hasRepeatMarker"
                                @click="addSegment('repeat')"
                            >
                                + {{ $gettext('Repeat from Here') }}
                            </button>
                        </div>

                        <div
                            v-if="!form.segments.length"
                            class="segment-empty"
                        >
                            {{ $gettext('No segments yet. Add content above to build the show running order.') }}
                        </div>

                        <div
                            v-for="(seg, index) in form.segments"
                            :key="seg._key"
                            class="segment-row"
                            :class="{'segment-repeat-row': seg.type === 'repeat'}"
                            draggable="true"
                            @dragstart="dragStart(index)"
                            @dragover.prevent
                            @drop="dropSegment(index)"
                        >
                            <div class="segment-drag" :title="$gettext('Drag to reorder')">⋮⋮</div>

                            <template v-if="seg.type === 'repeat'">
                                <div class="segment-repeat-copy">
                                    <strong>{{ $gettext('Repeat from Here') }}</strong>
                                    <small>
                                        {{ $gettext('Everything below this marker loops until the time slot ends. Everything above plays once.') }}
                                    </small>
                                </div>
                                <button
                                    type="button"
                                    class="segment-remove-rail"
                                    :aria-label="$gettext('Remove Segment')"
                                    @click="removeSegment(index)"
                                >
                                    <span>{{ $gettext('Remove') }}</span>
                                </button>
                            </template>

                            <template v-else>
                                <div class="segment-main">
                                    <div class="segment-topline">
                                        <span class="segment-number">{{ index + 1 }}</span>
                                        <input
                                            v-model="seg.label"
                                            class="form-control segment-label"
                                            :placeholder="$gettext('Segment label (optional)')"
                                        >
                                        <button
                                            type="button"
                                            class="segment-remove-rail"
                                            :aria-label="$gettext('Remove Segment')"
                                            @click="removeSegment(index)"
                                        >
                                            <span>{{ $gettext('Remove') }}</span>
                                        </button>
                                    </div>

                                    <div class="segment-fields">
                                        <select
                                            v-model="seg.source"
                                            class="form-select"
                                            @change="onSourceChange(seg)"
                                        >
                                            <option value="playlist_sequential">{{ $gettext('From Playlist (Sequential)') }}</option>
                                            <option value="playlist_random">{{ $gettext('From Playlist (Random)') }}</option>
                                            <option value="folder_random">{{ $gettext('Random from Folder') }}</option>
                                            <option value="track">{{ $gettext('Specific Track') }}</option>
                                            <option value="remote_file">{{ $gettext('Remote File (URL)') }}</option>
                                            <option value="remote_stream">{{ $gettext('Remote Stream (URL)') }}</option>
                                        </select>

                                        <input
                                            v-if="seg.type === 'playlist'"
                                            v-model="seg.value"
                                            class="form-control"
                                            :placeholder="$gettext('Playlist ID or name')"
                                        >

                                        <input
                                            v-else-if="seg.type === 'folder'"
                                            v-model="seg.value"
                                            class="form-control"
                                            :placeholder="$gettext('Folder path or name')"
                                        >

                                        <input
                                            v-else-if="seg.type === 'track'"
                                            v-model="seg.value"
                                            class="form-control"
                                            :placeholder="$gettext('Search by title, artist, or filename…')"
                                        >

                                        <input
                                            v-else
                                            v-model="seg.value"
                                            class="form-control"
                                            type="url"
                                            placeholder="https://example.com/audio.mp3"
                                        >

                                        <label
                                            v-if="seg.type === 'playlist' || seg.type === 'folder'"
                                            class="segment-tracks"
                                        >
                                            <span>{{ $gettext('Tracks') }}</span>
                                            <input
                                                v-model.number="seg.tracks"
                                                class="form-control"
                                                type="number"
                                                min="0"
                                            >
                                            <small>{{ $gettext('0 = all') }}</small>
                                        </label>
                                    </div>

                                    <div
                                        v-if="seg.type === 'remote'"
                                        class="segment-remote-extra"
                                    >
                                        <label>
                                            {{ $gettext('Duration') }}
                                            <input
                                                v-model="seg.duration"
                                                class="form-control"
                                                placeholder="mm:ss"
                                            >
                                        </label>
                                        <small>
                                            {{ $gettext('M3U playlist URLs are expanded into tracks in playlist order.') }}
                                        </small>
                                    </div>

                                    <div class="segment-options">
                                        <label>
                                            <input
                                                v-model="seg.hide_now_playing"
                                                type="checkbox"
                                            >
                                            {{ $gettext('Hide from Now Playing') }}
                                        </label>
                                        <label>
                                            <input
                                                v-model="seg.disable_crossfade"
                                                type="checkbox"
                                            >
                                            {{ $gettext('Disable Crossfade') }}
                                        </label>
                                    </div>

                                    <small class="segment-start">
                                        {{ $gettext('Starts at') }} ~{{ segmentStart(index) }}
                                    </small>
                                </div>
                            </template>
                        </div>

                        <div class="segment-total">
                            <span>{{ $gettext('Estimated total:') }}</span>
                            <strong>~{{ estimatedTotal }}</strong>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 show-editor-actions">
            <router-link
                class="btn btn-secondary"
                :to="{name: 'stations:shows:index'}"
            >
                {{ $gettext('Cancel') }}
            </router-link>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="saving || !form.name.trim()"
                @click="save"
            >
                {{ saving ? $gettext('Saving…') : $gettext('Save Show') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, reactive, ref} from "vue";
import {useRoute, useRouter} from "vue-router";
import {useAxios} from "~/vendor/axios";
import {useApiRouter} from "~/functions/useApiRouter";
import {useTranslate} from "~/vendor/gettext";

type ShowSegmentSource =
    "playlist_sequential" |
    "playlist_random" |
    "folder_random" |
    "track" |
    "remote_file" |
    "remote_stream" |
    "repeat";

type ShowSegment = {
    _key: string,
    type: "playlist" | "folder" | "track" | "remote" | "repeat",
    source: ShowSegmentSource,
    mode: "sequential" | "random" | "file" | "stream" | "",
    value: string,
    label: string,
    duration: string,
    tracks: number,
    hide_now_playing: boolean,
    disable_crossfade: boolean,
};

type ShowSchedule = {
    start_time: string,
    end_time: string,
    start_date: string,
    end_date: string,
    loop_once: boolean,
    days: number[],
};

type ShowForm = {
    id: string | number | null,
    name: string,
    description: string,
    enabled: boolean,
    color: string,
    priority: string,
    allow_overrun: boolean,
    segments: ShowSegment[],
    schedules: ShowSchedule[],
};

const {$gettext} = useTranslate();
const route = useRoute();
const router = useRouter();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const url = getStationApiUrl("/features/shows");
const saving = ref(false);
const draggedIndex = ref<number | null>(null);

const days = [
    {n: 1, t: "MON"},
    {n: 2, t: "TUE"},
    {n: 3, t: "WED"},
    {n: 4, t: "THU"},
    {n: 5, t: "FRI"},
    {n: 6, t: "SAT"},
    {n: 7, t: "SUN"},
];

const newKey = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;

function sourceForSegment(segment: Partial<ShowSegment>): ShowSegmentSource {
    if ("repeat" === segment.type) return "repeat";
    if ("folder" === segment.type) return "folder_random";
    if ("track" === segment.type) return "track";
    if ("remote" === segment.type) return "stream" === segment.mode ? "remote_stream" : "remote_file";
    if ("playlist" === segment.type) return "random" === segment.mode ? "playlist_random" : "playlist_sequential";
    return "playlist_sequential";
}

function applySource(segment: ShowSegment, resetValue = true): void {
    switch (segment.source) {
        case "playlist_random":
            segment.type = "playlist";
            segment.mode = "random";
            break;
        case "folder_random":
            segment.type = "folder";
            segment.mode = "random";
            break;
        case "track":
            segment.type = "track";
            segment.mode = "";
            break;
        case "remote_file":
            segment.type = "remote";
            segment.mode = "file";
            break;
        case "remote_stream":
            segment.type = "remote";
            segment.mode = "stream";
            break;
        case "repeat":
            segment.type = "repeat";
            segment.mode = "";
            break;
        case "playlist_sequential":
        default:
            segment.type = "playlist";
            segment.mode = "sequential";
            break;
    }

    if (resetValue) {
        segment.value = "";
        segment.duration = "";
    }

    segment.tracks = "playlist" === segment.type || "folder" === segment.type
        ? Math.max(1, Number(segment.tracks || 1))
        : 0;
}

const normalizeSegment = (segment: Partial<ShowSegment>): ShowSegment => {
    const normalized: ShowSegment = {
        _key: segment._key ?? newKey(),
        type: segment.type ?? "playlist",
        source: segment.source ?? sourceForSegment(segment),
        mode: segment.mode ?? "",
        value: segment.value ?? "",
        label: segment.label ?? "",
        duration: segment.duration ?? "",
        tracks: Number(segment.tracks ?? 1),
        hide_now_playing: Boolean(segment.hide_now_playing),
        disable_crossfade: Boolean(segment.disable_crossfade),
    };

    applySource(normalized, false);
    return normalized;
};

const form = reactive<ShowForm>({
    id: null,
    name: "",
    description: "",
    enabled: true,
    color: "#667eea",
    priority: "programme",
    allow_overrun: false,
    segments: [],
    schedules: [{start_time: "06:00", end_time: "10:00", start_date: "", end_date: "", loop_once: false, days: []}],
});

const addSegment = (type: ShowSegment["type"]) => {
    if ("repeat" === type && hasRepeatMarker.value) {
        return;
    }

    const source: ShowSegmentSource =
        "playlist" === type ? "playlist_sequential" :
        "folder" === type ? "folder_random" :
        "track" === type ? "track" :
        "remote" === type ? "remote_file" :
        "repeat";

    form.segments.push(normalizeSegment({
        type,
        source,
        tracks: "playlist" === type || "folder" === type ? 1 : 0,
    }));
};

const removeSegment = (index: number) => {
    form.segments.splice(index, 1);
};

const hasRepeatMarker = computed(() => form.segments.some((segment) => "repeat" === segment.type));

const onSourceChange = (segment: ShowSegment) => {
    applySource(segment);
};

const dragStart = (index: number) => {
    draggedIndex.value = index;
};

const dropSegment = (index: number) => {
    if (null === draggedIndex.value || draggedIndex.value === index) {
        draggedIndex.value = null;
        return;
    }

    const [segment] = form.segments.splice(draggedIndex.value, 1);
    form.segments.splice(index, 0, segment);
    draggedIndex.value = null;
};

const toggleDay = (schedule: ShowSchedule, day: number) => {
    const index = schedule.days.indexOf(day);
    if (-1 === index) {
        schedule.days.push(day);
        schedule.days.sort((a, b) => a - b);
    } else {
        schedule.days.splice(index, 1);
    }
};

const addSchedule = () => {
    form.schedules.push({
        start_time: "06:00",
        end_time: "10:00",
        start_date: "",
        end_date: "",
        loop_once: false,
        days: [],
    });
};

const parseDuration = (value: string): number => {
    const parts = value.split(":").map((part) => Number.parseInt(part, 10));
    if (2 !== parts.length || parts.some((part) => Number.isNaN(part))) {
        return 0;
    }
    return Math.max(0, parts[0] * 60 + parts[1]);
};

const formatSeconds = (seconds: number): string => {
    const safe = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(safe / 60);
    const remainder = safe % 60;
    return `${minutes}:${String(remainder).padStart(2, "0")}`;
};

const segmentSeconds = (segment: ShowSegment): number => {
    return "remote" === segment.type ? parseDuration(segment.duration) : 0;
};

const segmentStart = (index: number): string => {
    const total = form.segments
        .slice(0, index)
        .reduce((seconds, segment) => seconds + segmentSeconds(segment), 0);
    return formatSeconds(total);
};

const estimatedTotal = computed(() => {
    const total = form.segments.reduce(
        (seconds, segment) => seconds + segmentSeconds(segment),
        0
    );
    return formatSeconds(total);
});

const serializableSegments = () => {
    return form.segments.map(({_key, source, ...segment}) => segment);
};

const save = async () => {
    if (!form.name.trim()) {
        return;
    }

    saving.value = true;
    try {
        const payload = {
            ...form,
            segments: serializableSegments(),
        };
        await axios.post(url.value, payload);
        await router.push({name: "stations:shows:index"});
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    const id = route.params.show_id as string | undefined;
    if (!id) {
        return;
    }

    const rows = (await axios.get(url.value)).data as ShowForm[];
    const found = rows.find((row) => String(row.id) === String(id));
    if (!found) {
        return;
    }

    Object.assign(form, {
        ...JSON.parse(JSON.stringify(found)),
        segments: (found.segments ?? []).map((segment) => normalizeSegment(segment)),
        schedules: found.schedules?.length
            ? JSON.parse(JSON.stringify(found.schedules)).map((schedule: Partial<ShowSchedule>) => ({
                start_time: schedule.start_time ?? "06:00",
                end_time: schedule.end_time ?? "10:00",
                start_date: schedule.start_date ?? "",
                end_date: schedule.end_date ?? "",
                loop_once: Boolean(schedule.loop_once),
                days: Array.isArray(schedule.days) ? schedule.days : [],
            }))
            : [{start_time: "06:00", end_time: "10:00", start_date: "", end_date: "", loop_once: false, days: []}],
    });
});
</script>

<style scoped>
.show-editor-page {
    max-width: 1180px;
    margin: 0 auto;
    padding-top: 1.5rem !important;
    padding-bottom: 2rem !important;
    color: #e8edf7;
}

/* Clean hero matching the preferred reference. */
.show-editor-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    margin-bottom: 1.2rem;
    padding: 1.15rem 1.3rem;
    border: 1px solid rgba(107, 92, 201, .42);
    border-radius: .9rem;
    background: linear-gradient(90deg, #0a6fc2 0%, #2196f3 100%);
    box-shadow: 0 .6rem 1.5rem rgba(0, 0, 0, .18);
}

.show-editor-hero-left {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-width: 0;
}

.show-editor-hero h1 {
    margin: 0 0 .18rem;
    color: #fff;
    font-size: 1.35rem;
    font-weight: 750;
    letter-spacing: -.02em;
}

.show-editor-hero p {
    margin: 0;
    color: #d4d6ee;
    font-size: .78rem;
}

.show-editor-back {
    flex: 0 0 auto;
    min-height: 38px;
    padding-inline: .9rem;
    border-color: rgba(205, 215, 255, .4);
    background: rgba(29, 44, 72, .52);
    color: #fff;
}

.feature-show-icon,
.show-editor-card-icon {
    display: inline-grid;
    place-items: center;
    flex: 0 0 auto;
    color: #fff;
}

.feature-show-icon {
    width: 48px;
    height: 48px;
    border-radius: .75rem;
    background: rgba(255, 255, 255, .14);
}

.feature-show-icon svg {
    width: 24px;
    height: 24px;
    fill: currentColor;
}

/* Main cards. */
.show-editor-card {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .8rem;
    background: var(--bs-secondary-bg);
    color: #e8edf7;
    box-shadow: 0 .35rem 1rem rgba(0, 0, 0, .16);
}

.show-editor-card-header {
    min-height: 70px;
    display: flex;
    align-items: center;
    gap: .8rem;
    padding: .95rem 1.05rem;
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.show-editor-card-icon {
    width: 38px;
    height: 38px;
    border-radius: .62rem;
}

.show-editor-card-icon svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
}

.details-icon {
    background: linear-gradient(135deg, #1e88e5, #1976d2);
}

.segments-icon {
    background: linear-gradient(135deg, #1565c0, #1e88e5);
}

.schedule-icon {
    background: linear-gradient(135deg, #1e88e5, #2196f3);
}

.show-editor-card-header h2 {
    margin: 0;
    color: #fff;
    font-size: .98rem;
    font-weight: 760;
}

.show-editor-card-header p {
    margin: .14rem 0 0;
    color: #8495ae;
    font-size: .7rem;
}

.show-editor-card-body {
    padding: 1rem;
}

.show-editor-card .form-label {
    display: block;
    margin-bottom: .4rem;
    color: #dce4f0;
    font-size: .76rem;
    font-weight: 700;
}

.show-editor-card .form-control,
.show-editor-card .form-select {
    min-height: 40px;
    border-color: var(--bs-border-color);
    background-color: var(--bs-body-bg);
    color: #f1f4f9;
}

.show-editor-card textarea.form-control {
    min-height: 92px;
}

.show-editor-card .form-control:focus,
.show-editor-card .form-select:focus {
    border-color: #7d6fd3;
    box-shadow: 0 0 0 .18rem rgba(107, 92, 201, .16);
}

.show-editor-switch {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: .75rem .85rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .55rem;
    background: var(--bs-tertiary-bg);
}

.show-editor-switch strong,
.show-editor-switch small {
    display: block;
}

.show-editor-switch small {
    margin-top: .12rem;
    color: #8697af;
}

.switch-control {
    position: relative;
    width: 38px;
    height: 22px;
    flex: 0 0 auto;
}

.switch-control input {
    position: absolute;
    opacity: 0;
}

.switch-control span {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #536174;
    cursor: pointer;
    transition: .15s ease;
}

.switch-control span::after {
    content: "";
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #e1e7ef;
    transition: .15s ease;
}

.switch-control input:checked + span {
    background: linear-gradient(90deg, #1e88e5, #1976d2);
}

.switch-control input:checked + span::after {
    transform: translateX(16px);
    background: #fff;
}

.show-colour-row {
    display: grid;
    grid-template-columns: 54px 1fr;
    gap: .6rem;
}

/* Segments */
.segment-help {
    margin: 0 0 .9rem;
    color: #8495ae;
    line-height: 1.5;
}

.segment-add-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-bottom: .9rem;
}

.segment-add {
    min-height: 34px;
    padding: .4rem .72rem;
    border: 1px dashed var(--bs-border-color);
    border-radius: .42rem;
    background: var(--bs-tertiary-bg);
    font-size: .76rem;
    font-weight: 600;
}

.segment-add:disabled {
    opacity: .38;
    cursor: not-allowed;
}

.segment-playlist { color: #8aa0ff; }
.segment-folder { color: #ffb624; }
.segment-track { color: #18c28b; }
.segment-remote { color: #28b9ee; }
.segment-repeat { color: #b08dff; }

.segment-empty {
    padding: 1.75rem 1rem;
    border: 1px dashed var(--bs-border-color);
    border-radius: .55rem;
    background: var(--bs-body-bg);
    color: #8394ac;
    text-align: center;
}

.segment-row {
    position: relative;
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    margin-bottom: .75rem;
    padding-right: 34px;
    border: 1px solid var(--bs-border-color);
    border-radius: .55rem;
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}

.segment-drag {
    display: grid;
    place-items: center;
    background: var(--bs-secondary-bg);
    color: #d7dfea;
    cursor: grab;
    user-select: none;
}

.segment-main {
    min-width: 0;
    padding: .75rem;
}

.segment-topline {
    display: grid;
    grid-template-columns: 30px minmax(0, 1fr);
    align-items: center;
    gap: .5rem;
    margin-bottom: .55rem;
}

.segment-number {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: .45rem;
    background: linear-gradient(135deg, #1e88e5, #1976d2);
    color: #fff;
    font-size: .78rem;
    font-weight: 800;
}

.segment-label {
    min-width: 0;
}

.segment-fields {
    display: grid;
    grid-template-columns: minmax(150px, 1.1fr) minmax(150px, 1fr) auto;
    gap: .5rem;
    align-items: center;
}

.segment-tracks {
    display: flex;
    align-items: center;
    gap: .35rem;
    color: #9ba9bb;
    font-size: .68rem;
    white-space: nowrap;
}

.segment-tracks input {
    width: 54px;
    min-height: 38px !important;
}

.segment-options {
    display: flex;
    flex-wrap: wrap;
    gap: .8rem;
    margin-top: .55rem;
    color: #d9e1ec;
    font-size: .68rem;
}

.segment-options label {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
}

.segment-start {
    display: block;
    margin-top: .5rem;
    color: #9ba9bb;
}

.segment-remote-extra {
    margin-top: .55rem;
}

.segment-repeat-row {
    grid-template-columns: 34px minmax(0, 1fr);
    border-style: dashed;
    border-color: #8a63d4;
    background: color-mix(in srgb, var(--bs-primary) 10%, var(--bs-tertiary-bg));
}

.segment-repeat-copy {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: .12rem;
    padding: .7rem .85rem;
}

.segment-repeat-copy strong {
    color: #bca2ff;
    font-size: .78rem;
    text-transform: uppercase;
}

.segment-repeat-copy small {
    color: #8f86af;
}

/* Full-height side remove rail. */
.segment-remove-rail {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    padding: 0;
    border: 0;
    border-left: 1px solid rgba(var(--bs-danger-rgb), .55);
    background: linear-gradient(180deg, #5a2630 0%, #3f1f27 100%);
    color: #f5c2c7;
    cursor: pointer;
}

.segment-remove-rail span {
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    font-size: .58rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.segment-remove-rail:hover {
    background: linear-gradient(180deg, #7b2e3c 0%, #5d2632 100%);
    color: #fff;
}

.segment-total {
    display: flex;
    justify-content: flex-end;
    gap: .35rem;
    padding-top: .65rem;
    border-top: 1px solid var(--bs-border-color);
    color: #8fa0b7;
    font-size: .78rem;
}

.segment-total strong {
    color: #fff;
}

/* Schedule lives under Details, compact and visually consistent. */
.schedule-card {
    margin-top: 1rem !important;
}

.show-schedule-row {
    padding: .8rem;
    margin-bottom: .75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .55rem;
    background: var(--bs-tertiary-bg);
}

.show-schedule-row-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .6rem;
    margin-bottom: .75rem;
    padding-bottom: .6rem;
    border-bottom: 1px solid var(--bs-border-color);
}

.schedule-number {
    display: inline-grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: .4rem;
    background: linear-gradient(135deg, #1e88e5, #1976d2);
    color: #fff;
    font-size: .74rem;
    font-weight: 800;
}

.schedule-remove {
    margin: 0 !important;
}

.show-day-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
}

.show-day-buttons button {
    min-width: 38px;
    height: 31px;
    padding: 0 .45rem;
    border: 1px solid #69768a;
    border-radius: .38rem;
    background: #4b5767;
    color: #d8dee7;
    font-size: .62rem;
    font-weight: 700;
}

.show-day-buttons button.is-active {
    border-color: #42a5f5;
    background: linear-gradient(135deg, #1e88e5, #1976d2);
    color: #fff;
}

/* Footer actions */
.show-editor-actions {
    position: static;
    padding-top: .15rem;
}

.show-editor-actions .btn {
    min-height: 38px;
}

/* Responsive cleanup */
@media (max-width: 1199.98px) {
    .show-editor-page {
        max-width: 900px;
    }

    .segments-card {
        margin-top: 0;
    }
}

@media (max-width: 767.98px) {
    .show-editor-page {
        padding-inline: .75rem;
    }

    .segment-fields {
        grid-template-columns: 1fr;
    }

    .segment-tracks {
        justify-content: flex-start;
    }

    .show-editor-hero {
        padding: .9rem !important;
    }
}

.playout-priority {
    padding: .85rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .6rem;
    background: var(--bs-tertiary-bg);
}

.priority-choice {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr);
    gap: .55rem;
    align-items: start;
    padding: .55rem .6rem;
    margin-bottom: .45rem;
    border: 1px solid transparent;
    border-radius: .45rem;
    cursor: pointer;
}

.priority-choice input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.priority-radio {
    width: 16px;
    height: 16px;
    margin-top: .12rem;
    border: 1px solid #728097;
    border-radius: 50%;
    background: var(--bs-secondary-bg);
    box-shadow: inset 0 0 0 3px #2a394e;
}

.priority-choice.is-selected {
    border-color: #1e88e5;
    background: rgba(var(--bs-primary-rgb), .10);
}

.priority-choice.is-selected .priority-radio {
    border-color: #42a5f5;
    background: #1e88e5;
}

.priority-choice strong,
.priority-choice small {
    display: block;
}

.priority-choice strong {
    color: #f1f4fa;
    font-size: .78rem;
}

.priority-choice small {
    margin-top: .12rem;
    color: #96a5b9;
    line-height: 1.35;
}

.priority-help {
    margin: .3rem 0 .65rem;
    color: #aab7c8;
    font-size: .7rem;
}

.priority-order {
    padding: .65rem .7rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .48rem;
    background: color-mix(in srgb, var(--bs-primary) 8%, var(--bs-tertiary-bg));
}

.priority-order strong,
.priority-order small {
    display: block;
}

.priority-order strong {
    color: #90caf9;
    font-size: .7rem;
}

.priority-order small {
    margin-top: .16rem;
    color: #8fa1b9;
    font-size: .64rem;
    line-height: 1.35;
}

.schedule-help {
    display: block;
    margin-top: .28rem;
    color: #8393a9;
    font-size: .62rem;
    line-height: 1.35;
}

.schedule-loop-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: .65rem .75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .48rem;
    background: var(--bs-secondary-bg);
}

.schedule-loop-toggle strong,
.schedule-loop-toggle small {
    display: block;
}

.schedule-loop-toggle strong {
    color: #eef2f8;
    font-size: .75rem;
}

.schedule-loop-toggle small {
    margin-top: .12rem;
    color: #94a2b5;
    font-size: .62rem;
}

@media (max-width: 767.98px) {
    .show-editor-hero {
        align-items: flex-start;
        flex-direction: column;
    }
}

</style>
