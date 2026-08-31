<template>
    <div class="linear-log-page">
        <section class="linear-log-card">
            <header class="linear-log-header">
                <div>
                    <h1>{{ pageTitle }}</h1>
                    <p>{{ $gettext('Upcoming scheduled programming built by AutoDJ') }}</p>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <label class="visually-hidden" for="linear_log_hours">{{ $gettext('Hours') }}</label>
                    <select
                        id="linear_log_hours"
                        v-model.number="hoursAhead"
                        class="form-select form-select-sm hours-select"
                        :disabled="isBuilding"
                    >
                        <option :value="6">6 {{ $gettext('hours') }}</option>
                        <option :value="12">12 {{ $gettext('hours') }}</option>
                        <option :value="24">24 {{ $gettext('hours') }}</option>
                        <option :value="48">48 {{ $gettext('hours') }}</option>
                    </select>

                    <button
                        type="button"
                        class="btn btn-light btn-sm fw-semibold"
                        :disabled="isBuilding"
                        @click="requestBuild"
                    >
                        <span
                            v-if="isBuilding"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        />
                        {{ isBuilding ? $gettext('BUILDING') : $gettext('BUILD AND REFRESH') }}
                    </button>
                </div>
            </header>

            <div v-if="buildError" class="alert alert-danger rounded-0 border-start-0 border-end-0 mb-0">
                <strong>{{ $gettext('Linear Log build failed.') }}</strong>
                {{ buildError }}
            </div>

            <div v-else-if="isBuilding" class="build-status">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                <span>
                    {{ status === 'queued'
                        ? $gettext('The Linear Log build is queued in the background.')
                        : $gettext('AutoDJ is calculating the isolated playout projection in the background.') }}
                </span>
                <span v-if="allItems.length" class="text-body-secondary">
                    {{ $gettext('The last completed snapshot remains visible below.') }}
                </span>
            </div>

            <div v-if="gapCount > 0" class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0">
                <div class="fw-semibold">
                    {{ gapCount }} {{ $gettext('projected gap(s) detected') }} — {{ totalGapDuration }}
                </div>
                <div class="small mt-1">
                    {{ $gettext('A gap means AutoDJ could not find an eligible item for that simulated time after applying schedules, rotation, duplicate prevention, DMCA and other playout rules. The preview advanced five minutes and kept calculating instead of silently truncating the day.') }}
                </div>
            </div>

            <div class="filter-bar">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="filter-label">{{ $gettext('Show') }}</span>
                    <button
                        v-for="filter in typeFilters"
                        :key="filter.key"
                        type="button"
                        class="btn btn-sm"
                        :class="activeTypes.includes(filter.key) ? filter.activeClass : 'btn-outline-secondary'"
                        @click="toggleType(filter.key)"
                    >
                        {{ filter.label }}
                    </button>

                    <div class="ms-md-auto d-flex gap-2 flex-wrap">
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="form-control form-control-sm search-box"
                            :placeholder="$gettext('Search title, artist or source')"
                        >

                        <div class="dropdown">
                            <button
                                class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                            >
                                {{ $gettext('Columns') }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li v-for="column in columnOptions" :key="column.key">
                                    <label class="dropdown-item d-flex align-items-center gap-2 mb-0">
                                        <input
                                            v-model="visibleColumns"
                                            class="form-check-input mt-0"
                                            type="checkbox"
                                            :value="column.key"
                                        >
                                        {{ column.label }}
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="allItems.length" class="stats-bar">
                <span><strong>{{ filteredItems.length }}</strong> {{ $gettext('tracks') }}</span>
                <span><strong>{{ totalDurationFormatted }}</strong> {{ $gettext('program runtime') }}</span>
                <span><strong>{{ snapshotHours }}</strong> {{ $gettext('hour snapshot') }}</span>
                <span v-if="builtAt">
                    {{ $gettext('Built') }} <strong>{{ formatDateTime(builtAt) }}</strong>
                </span>
                <span v-if="coverageEnd">
                    {{ $gettext('Coverage through') }} <strong>{{ formatDateTime(coverageEnd) }}</strong>
                </span>
                <span v-if="nextUpItem">
                    {{ $gettext('Next up') }}:
                    <strong>{{ displayTitle(nextUpItem) }}</strong>
                    {{ $gettext('at') }} <strong>{{ formatTime(nextUpItem.played_at) }}</strong>
                </span>
            </div>

            <div v-if="coverageWarning" class="coverage-warning">
                <strong>{{ $gettext('Projection ended before the requested horizon.') }}</strong>
                {{ coverageWarning }}
            </div>

            <div v-if="initialLoading" class="loading-state">
                <div class="spinner-border text-primary" role="status" />
                <div class="mt-3 fw-semibold">{{ $gettext('Loading Linear Log...') }}</div>
            </div>

            <div v-else-if="0 === allItems.length" class="empty-state">
                <h2>{{ $gettext('No Linear Log Snapshot Yet') }}</h2>
                <p>
                    {{ $gettext('Build the log to calculate an isolated AutoDJ projection. The preview does not write a 24-hour fake queue into live station playback.') }}
                </p>
                <button
                    type="button"
                    class="btn btn-primary mt-3"
                    :disabled="isBuilding"
                    @click="requestBuild"
                >
                    {{ $gettext('Build Linear Log') }}
                </button>
            </div>

            <template v-else>
                <section v-for="group in hourGroups" :key="group.epochHour" class="hour-group">
                    <div class="hour-header" :class="{'current-hour': group.isCurrent}">
                        <span v-if="group.isCurrent" class="badge text-bg-primary">{{ $gettext('NOW') }}</span>
                        <strong>{{ group.label }}</strong>
                        <span class="hour-summary">
                            {{ group.items.length }} {{ $gettext('tracks') }} / {{ group.totalDurationFormatted }}
                        </span>
                        <span v-if="group.hasId" class="badge text-bg-danger ms-auto">{{ $gettext('Station ID') }}</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 linear-table">
                            <tbody>
                                <tr
                                    v-for="item in group.items"
                                    :key="item.id"
                                    class="queue-row"
                                    :class="rowClasses(item)"
                                >
                                    <td v-if="visibleColumns.includes('time')" class="queue-time ps-3">
                                        {{ formatTime(item.played_at) }}
                                    </td>

                                    <td v-if="visibleColumns.includes('title')" class="py-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <span v-if="isNextUp(item)" class="next-marker">{{ $gettext('NEXT') }}</span>
                                            <span v-else-if="item.is_live_queue" class="live-marker">{{ $gettext('LIVE QUEUE') }}</span>
                                            <div>
                                                <div v-if="item.autodj_custom_uri" class="small text-body-secondary">
                                                    {{ item.autodj_custom_uri }}
                                                </div>
                                                <template v-else>
                                                    <strong class="track-title">{{ displayTitle(item) }}</strong>
                                                    <div v-if="item.artist" class="small track-artist">{{ item.artist }}</div>
                                                    <div v-if="item.album" class="small text-body-secondary">{{ item.album }}</div>
                                                </template>
                                            </div>
                                        </div>
                                    </td>

                                    <td v-if="visibleColumns.includes('source')" class="playlist-cell">
                                        <div>{{ sourceLabel(item) }}</div>
                                        <div v-if="item.playlist_chain?.length" class="small text-body-secondary">
                                            {{ item.playlist_chain.join(' → ') }}
                                        </div>
                                    </td>

                                    <td v-if="visibleColumns.includes('type')" class="type-cell">
                                        <span :class="typeBadgeClass(item)">{{ typeLabel(item) }}</span>
                                    </td>

                                    <td v-if="visibleColumns.includes('rules')" class="rules-cell">
                                        <span v-if="item.top_of_hour_legal_id" class="badge text-bg-danger me-1">TOH</span>
                                        <span v-if="item.clock_wheel_enforce_cap" class="badge text-bg-secondary me-1">CAP</span>
                                        <span v-if="item.clock_wheel_stretch_ratio" class="badge text-bg-info me-1">
                                            {{ formatStretch(item.clock_wheel_stretch_ratio) }}
                                        </span>
                                        <span v-if="item.hour_boundary_enforce_cap" class="badge text-bg-warning me-1">BOUNDARY</span>
                                        <span v-if="item.is_request" class="badge text-bg-primary">REQUEST</span>
                                    </td>

                                    <td v-if="visibleColumns.includes('duration')" class="duration-cell pe-3">
                                        {{ formatDuration(item.duration) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>

            <footer class="linear-log-footer">
                {{ $gettext('AI DJ speech remains live-generated and is intentionally not synthesized or enqueued by this preview. This prevents the report from changing DJ cooldowns, shifts or live TTS playback.') }}
            </footer>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

interface LinearLogItem {
    id: string;
    queue_id: number;
    song_id: string;
    played_at: number | null;
    cued_at: number;
    duration: number;
    title: string | null;
    artist: string | null;
    album: string | null;
    text: string | null;
    playlist: string | null;
    playlist_id: number | null;
    playlist_chain: string[] | null;
    clock_wheel: string | null;
    clock_wheel_id: number | null;
    media_type: string;
    source_type: string;
    is_request: boolean;
    is_live_queue: boolean;
    sent_to_autodj: boolean;
    top_of_hour_legal_id: boolean;
    autodj_custom_uri: string | null;
    clock_wheel_schedule_mode: string | null;
    clock_wheel_enforce_cap: boolean;
    clock_wheel_stretch_ratio: number | null;
    clock_wheel_legal_id_substitute: boolean;
    hour_boundary_enforce_cap: boolean;
    hour_boundary_max_play_seconds: number | null;
    top_of_hour_pre_id_fade: boolean;
}

interface LinearLogGap {
    started_at: number;
    duration: number;
    reason: string;
}

interface LinearLogResponse {
    status: "idle" | "queued" | "building" | "ready" | "failed";
    hours: number;
    configured_hours: number;
    built_at: number | null;
    coverage_start: number | null;
    coverage_end: number | null;
    entries: LinearLogItem[];
    gaps: LinearLogGap[];
    error: string | null;
}

interface HourGroup {
    epochHour: number;
    label: string;
    isCurrent: boolean;
    items: LinearLogItem[];
    totalDurationFormatted: string;
    hasId: boolean;
}

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();

const buildUrl = getStationApiUrl("/reports/linear-log/build");

const initialLoading = ref(true);
const buildError = ref("");
const status = ref<LinearLogResponse["status"]>("idle");
const hoursAhead = ref(24);
const snapshotHours = ref(24);
const builtAt = ref<number | null>(null);
const coverageStart = ref<number | null>(null);
const coverageEnd = ref<number | null>(null);
const searchQuery = ref("");
const allItems = ref<LinearLogItem[]>([]);
const gaps = ref<LinearLogGap[]>([]);
const nowTs = ref(Math.floor(Date.now() / 1000));
let initializedHours = false;
let pollTimer: number | null = null;

const isBuilding = computed(() => status.value === "queued" || status.value === "building");
const pageTitle = computed(() => `${snapshotHours.value || hoursAhead.value}-${$gettext("Hour Playout Log")}`);

const columnOptions = [
    {key: "time", label: $gettext("Time")},
    {key: "title", label: $gettext("Title / Artist")},
    {key: "source", label: $gettext("Playlist / Source")},
    {key: "type", label: $gettext("Type")},
    {key: "rules", label: $gettext("Rules")},
    {key: "duration", label: $gettext("Duration")},
];
const visibleColumns = ref(["time", "title", "source", "type", "rules", "duration"]);

const typeFilters = [
    {key: "music", label: $gettext("Music"), activeClass: "btn-success"},
    {key: "talk", label: $gettext("Talk"), activeClass: "btn-warning"},
    {key: "id", label: $gettext("Station ID"), activeClass: "btn-danger"},
    {key: "promo", label: $gettext("Promo"), activeClass: "btn-info"},
    {key: "jingle", label: $gettext("Jingle"), activeClass: "btn-secondary"},
    {key: "podcast", label: $gettext("Podcast"), activeClass: "btn-primary"},
    {key: "stream", label: $gettext("Stream"), activeClass: "btn-dark"},
    {key: "request", label: $gettext("Request"), activeClass: "btn-outline-primary"},
    {key: "clock_wheel", label: $gettext("Clock Wheel"), activeClass: "btn-primary"},
];
const activeTypes = ref(typeFilters.map((item) => item.key));

function toggleType(key: string) {
    activeTypes.value = activeTypes.value.includes(key)
        ? activeTypes.value.filter((item) => item !== key)
        : [...activeTypes.value, key];
}

function resolveType(item: LinearLogItem): string {
    if (item.is_request) return "request";
    if (item.clock_wheel) return "clock_wheel";
    if (item.top_of_hour_legal_id || item.media_type === "id") return "id";
    if (item.autodj_custom_uri) return "stream";
    return item.media_type || "music";
}

function typeLabel(item: LinearLogItem): string {
    const labels: Record<string, string> = {
        music: $gettext("Music"),
        talk: $gettext("Talk"),
        id: $gettext("ID"),
        promo: $gettext("Promo"),
        jingle: $gettext("Jingle"),
        podcast: $gettext("Podcast"),
        stream: $gettext("Stream"),
        request: $gettext("Request"),
        clock_wheel: $gettext("Clock"),
    };
    return labels[resolveType(item)] ?? $gettext("Music");
}

function typeBadgeClass(item: LinearLogItem): string {
    const classes: Record<string, string> = {
        music: "badge text-bg-success",
        talk: "badge text-bg-warning",
        id: "badge text-bg-danger",
        promo: "badge text-bg-info",
        jingle: "badge text-bg-secondary",
        podcast: "badge text-bg-primary",
        stream: "badge text-bg-dark",
        request: "badge text-bg-primary",
        clock_wheel: "badge text-bg-primary",
    };
    return classes[resolveType(item)] ?? "badge text-bg-success";
}

function sourceLabel(item: LinearLogItem): string {
    if (item.clock_wheel) return item.clock_wheel;
    if (item.playlist) return item.playlist;
    if (item.is_request) return $gettext("Listener Request");
    if (item.autodj_custom_uri) return $gettext("Remote Stream");
    return $gettext("General Rotation");
}

function displayTitle(item: LinearLogItem): string {
    return item.title || item.text || $gettext("Untitled");
}

function rowClasses(item: LinearLogItem): Record<string, boolean> {
    return {
        "next-up": isNextUp(item),
        "legal-id": item.top_of_hour_legal_id,
        "live-queue": item.is_live_queue,
    };
}

function isNextUp(item: LinearLogItem): boolean {
    return (item.played_at ?? 0) >= nowTs.value && item.is_live_queue;
}

function formatTime(timestamp: number | null): string {
    if (!timestamp) return "-";
    return new Date(timestamp * 1000).toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
    });
}

function formatDateTime(timestamp: number): string {
    return new Date(timestamp * 1000).toLocaleString([], {
        weekday: "short",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
    });
}

function formatDuration(seconds: number): string {
    const minutes = Math.floor((seconds ?? 0) / 60);
    const remain = Math.floor((seconds ?? 0) % 60);
    return `${minutes}:${String(remain).padStart(2, "0")}`;
}

function secondsToHms(total: number): string {
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = Math.floor(total % 60);
    return hours > 0 ? `${hours}h ${minutes}m ${seconds}s` : `${minutes}m ${seconds}s`;
}

function formatStretch(ratio: number): string {
    return `${(ratio * 100).toFixed(1)}%`;
}

const filteredItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return allItems.value.filter((item) => {
        if (!activeTypes.value.includes(resolveType(item))) return false;
        if (!query) return true;
        return [
            item.title,
            item.artist,
            item.album,
            item.text,
            item.playlist,
            item.clock_wheel,
            item.autodj_custom_uri,
            ...(item.playlist_chain ?? []),
        ].filter(Boolean).join(" ").toLowerCase().includes(query);
    });
});

const totalDurationFormatted = computed(() => secondsToHms(
    filteredItems.value.reduce((sum, item) => sum + (item.duration ?? 0), 0),
));
const gapCount = computed(() => gaps.value.length);
const totalGapDuration = computed(() => secondsToHms(gaps.value.reduce((sum, gap) => sum + gap.duration, 0)));
const nextUpItem = computed(() => filteredItems.value.find((item) => isNextUp(item)) ?? null);

const coverageWarning = computed(() => {
    if (!coverageStart.value || !coverageEnd.value || !builtAt.value) return "";
    const requestedEnd = coverageStart.value + (snapshotHours.value * 3600);
    const shortBy = requestedEnd - coverageEnd.value;
    if (shortBy <= 300) return "";
    return `${$gettext("Missing approximately")} ${secondsToHms(shortBy)}.`;
});

const hourGroups = computed<HourGroup[]>(() => {
    const groups = new Map<number, LinearLogItem[]>();
    for (const item of filteredItems.value) {
        const timestamp = item.played_at ?? 0;
        const hour = Math.floor(timestamp / 3600) * 3600;
        const items = groups.get(hour) ?? [];
        items.push(item);
        groups.set(hour, items);
    }

    const currentHour = Math.floor(nowTs.value / 3600) * 3600;
    return [...groups.entries()].sort(([a], [b]) => a - b).map(([epochHour, items]) => {
        const sorted = [...items].sort((a, b) => (a.played_at ?? 0) - (b.played_at ?? 0));
        const total = sorted.reduce((sum, item) => sum + (item.duration ?? 0), 0);
        return {
            epochHour,
            label: formatDateTime(epochHour),
            isCurrent: epochHour === currentHour,
            items: sorted,
            totalDurationFormatted: secondsToHms(total),
            hasId: sorted.some((item) => item.top_of_hour_legal_id || item.media_type === "id"),
        };
    });
});

function schedulePoll() {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer);
    }
    pollTimer = window.setTimeout(() => void loadSnapshot(false), 2000);
}

async function loadSnapshot(showLoader = true) {
    if (showLoader && allItems.value.length === 0) {
        initialLoading.value = true;
    }

    try {
        const {data} = await axios.post<LinearLogResponse>(buildUrl.value, {action: "status"});
        status.value = data.status;
        snapshotHours.value = data.hours || data.configured_hours || 24;
        builtAt.value = data.built_at;
        coverageStart.value = data.coverage_start;
        coverageEnd.value = data.coverage_end;
        allItems.value = data.entries ?? [];
        gaps.value = data.gaps ?? [];
        buildError.value = data.error ?? "";
        nowTs.value = Math.floor(Date.now() / 1000);

        if (!initializedHours) {
            hoursAhead.value = data.hours || data.configured_hours || 24;
            initializedHours = true;
        }

        if (data.status === "queued" || data.status === "building") {
            schedulePoll();
        }
    } catch (error: any) {
        buildError.value = error?.response?.data?.message ?? error?.message ?? $gettext("Unable to load the Linear Log.");
    } finally {
        initialLoading.value = false;
    }
}

async function requestBuild() {
    buildError.value = "";
    status.value = "queued";

    try {
        await axios.post(buildUrl.value, {action: "build", hours: hoursAhead.value});
        snapshotHours.value = hoursAhead.value;
        schedulePoll();
    } catch (error: any) {
        status.value = "failed";
        buildError.value = error?.response?.data?.message ?? error?.message ?? $gettext("Unable to queue the Linear Log build.");
    }
}

onMounted(() => void loadSnapshot());
onUnmounted(() => {
    if (pollTimer !== null) {
        window.clearTimeout(pollTimer);
    }
});
</script>

<style scoped>
.linear-log-page{max-width:1400px;margin:0 auto;color:var(--bs-body-color)}
.linear-log-card{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.8rem;background:var(--bs-body-bg);box-shadow:0 .3rem 1rem rgba(0,0,0,.07)}
.linear-log-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1rem 1.15rem;color:#fff;background:linear-gradient(90deg,#0a6fc2 0%,#2196f3 100%)}
.linear-log-header h1{margin:0;color:#fff;font-size:1.35rem;font-weight:750}
.linear-log-header p{margin:.15rem 0 0;color:rgba(255,255,255,.9);font-size:.83rem}
.hours-select{width:auto;min-width:110px}
.build-status{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;padding:.65rem 1rem;border-bottom:1px solid var(--bs-border-color);background:var(--bs-primary-bg-subtle);font-size:.85rem}
.filter-bar{padding:.75rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-body-bg) 94%,var(--bs-secondary-bg) 6%)}
.filter-label{font-size:.8rem;font-weight:700}
.search-box{width:230px}
.stats-bar{display:flex;flex-wrap:wrap;gap:1.2rem;padding:.65rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 65%,var(--bs-body-bg));font-size:.8rem}
.coverage-warning{padding:.6rem 1rem;border-bottom:1px solid var(--bs-warning-border-subtle);background:var(--bs-warning-bg-subtle);color:var(--bs-warning-text-emphasis);font-size:.82rem}
.loading-state,.empty-state{padding:4rem 1.5rem;text-align:center}
.empty-state p{max-width:760px;margin:.5rem auto 0;color:var(--bs-secondary-color)}
.empty-state h2{font-size:1.1rem}
.hour-header{display:flex;align-items:center;gap:.6rem;padding:.62rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.hour-header.current-hour{background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 18%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 8%,var(--bs-body-bg)));box-shadow:inset 3px 0 0 var(--bs-primary)}
.hour-summary{color:var(--bs-secondary-color);font-size:.76rem}
.linear-table{--bs-table-color:var(--bs-body-color);--bs-table-bg:var(--bs-body-bg);--bs-table-hover-color:var(--bs-body-color);--bs-table-hover-bg:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.queue-row td{border-color:var(--bs-border-color)}
.queue-row.next-up td{background:color-mix(in srgb,var(--bs-success-bg-subtle) 34%,var(--bs-body-bg))}
.queue-row.legal-id td{background:color-mix(in srgb,var(--bs-danger-bg-subtle) 28%,var(--bs-body-bg))}
.queue-row.live-queue td{box-shadow:inset 2px 0 0 color-mix(in srgb,var(--bs-success) 55%,transparent)}
.queue-time,.duration-cell{font-family:var(--bs-font-monospace);font-size:.76rem;white-space:nowrap}
.queue-time{width:100px}
.duration-cell{width:72px;text-align:right}
.playlist-cell{width:210px;font-size:.79rem}
.type-cell{width:95px}
.rules-cell{width:185px}
.track-title{color:var(--bs-body-color)}
.track-artist{color:var(--bs-secondary-color)!important}
.next-marker,.live-marker{display:inline-block;padding:.16rem .32rem;border-radius:.28rem;color:#fff;font-size:.58rem;font-weight:750;letter-spacing:.035em;white-space:nowrap}
.next-marker{background:var(--bs-success)}
.live-marker{background:var(--bs-secondary)}
.linear-log-footer{padding:.7rem 1rem;border-top:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg);color:var(--bs-secondary-color);font-size:.76rem}
@media(max-width:767px){.linear-log-header{align-items:flex-start;flex-direction:column}.search-box{width:100%}.rules-cell{min-width:160px}}
</style>
