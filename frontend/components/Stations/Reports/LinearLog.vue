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
                        :disabled="isBuilding || !featureEnabled"
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

            <div
                v-if="!initialLoading && !featureEnabled"
                class="alert alert-secondary rounded-0 border-start-0 border-end-0 mb-0"
            >
                <strong>{{ $gettext('24-Hour Playout Log is disabled for this station.') }}</strong>
                {{ $gettext('Enable it in Station Profile → AutoDJ to create and maintain advance playout snapshots.') }}
            </div>

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

            <linear-log-ai-dj-shifts :shifts="aiDjShifts" />

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
                    :disabled="isBuilding || !featureEnabled"
                    @click="requestBuild"
                >
                    {{ $gettext('Build Linear Log') }}
                </button>
            </div>

            <linear-log-schedule
                v-else
                :groups="hourGroups"
                :visible-columns="visibleColumns"
                :now-ts="nowTs"
            />

            <footer class="linear-log-footer">
                {{ $gettext('AI DJ work shifts are shown, but speech remains live-generated and is never synthesized or enqueued by this preview. The report does not change DJ cooldowns, shift state or live TTS playback.') }}
            </footer>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, ref} from "vue";
import LinearLogAiDjShifts from "~/components/Stations/Reports/LinearLogAiDjShifts.vue";
import LinearLogSchedule from "~/components/Stations/Reports/LinearLogSchedule.vue";
import type {LinearLogHourGroup, LinearLogItem} from "~/entities/LinearLog";
import {useLinearLog} from "~/functions/useLinearLog";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {
    initialLoading,
    buildError,
    status,
    featureEnabled,
    hoursAhead,
    snapshotHours,
    builtAt,
    coverageStart,
    coverageEnd,
    allItems,
    gaps,
    aiDjShifts,
    nowTs,
    isBuilding,
    requestBuild,
} = useLinearLog();

const pageTitle = computed(() => `${snapshotHours.value || hoursAhead.value}-${$gettext("Hour Playout Log")}`);
const searchQuery = ref("");

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

function toggleType(key: string): void {
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

function secondsToHms(total: number): string {
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = Math.floor(total % 60);
    return hours > 0 ? `${hours}h ${minutes}m ${seconds}s` : `${minutes}m ${seconds}s`;
}

const totalDurationFormatted = computed(() => secondsToHms(
    filteredItems.value.reduce((sum, item) => sum + (item.duration ?? 0), 0),
));
const gapCount = computed(() => gaps.value.length);
const totalGapDuration = computed(() => secondsToHms(gaps.value.reduce((sum, gap) => sum + gap.duration, 0)));
const nextUpItem = computed(
    () => filteredItems.value.find((item) => (item.played_at ?? 0) >= nowTs.value && item.is_live_queue) ?? null,
);

function displayTitle(item: LinearLogItem): string {
    return item.title || item.text || $gettext("Untitled");
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

const coverageWarning = computed(() => {
    if (!coverageStart.value || !coverageEnd.value || !builtAt.value) return "";

    const requestedEnd = coverageStart.value + (snapshotHours.value * 3600);
    const shortBy = requestedEnd - coverageEnd.value;
    if (shortBy <= 300) return "";

    return `${$gettext("Missing approximately")} ${secondsToHms(shortBy)}.`;
});

const hourGroups = computed<LinearLogHourGroup[]>(() => {
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
.linear-log-footer{padding:.7rem 1rem;border-top:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg);color:var(--bs-secondary-color);font-size:.76rem}
@media(max-width:767px){.linear-log-header{align-items:flex-start;flex-direction:column}.search-box{width:100%}}
</style>
