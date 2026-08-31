<template>
    <div class="linear-log-page">
        <section class="linear-log-card">
            <div class="linear-log-header">
                <div>
                    <h1>{{ $gettext('24-Hour Playout Log') }}</h1>
                    <p>{{ $gettext('Upcoming scheduled programming built by AutoDJ') }}</p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="input-group input-group-sm hours-select">
                        <span class="input-group-text">{{ $gettext('Show next') }}</span>
                        <select v-model.number="hoursAhead" class="form-select" @change="refresh">
                            <option :value="6">{{ $gettext('6 hours') }}</option>
                            <option :value="12">{{ $gettext('12 hours') }}</option>
                            <option :value="24">{{ $gettext('24 hours') }}</option>
                            <option :value="48">{{ $gettext('48 hours') }}</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-sm btn-light" :disabled="isLoading" @click="refresh">
                        <span v-if="isLoading" class="spinner-border spinner-border-sm me-1" />
                        {{ isLoading ? $gettext('Building...') : $gettext('Build and Refresh') }}
                    </button>
                </div>
            </div>

            <div v-if="buildError" class="alert alert-warning m-3 mb-0">
                <strong>{{ $gettext('The log could not be extended to the requested horizon.') }}</strong>
                <div class="small mt-1">{{ buildError }}</div>
            </div>

            <div class="filter-bar">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="filter-label">{{ $gettext('Show') }}</span>
                    <button
                        v-for="type in typeFilters"
                        :key="type.key"
                        type="button"
                        class="btn btn-sm"
                        :class="activeTypes.includes(type.key) ? type.activeClass : 'btn-outline-secondary'"
                        @click="toggleType(type.key)"
                    >
                        {{ type.label }}
                    </button>
                    <div class="ms-auto d-flex gap-2 flex-wrap">
                        <input
                            v-model="searchQuery"
                            class="form-control form-control-sm search-box"
                            type="search"
                            :placeholder="$gettext('Search title or artist')"
                        >
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                {{ $gettext('Columns') }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li v-for="column in columnOptions" :key="column.key">
                                    <label class="dropdown-item d-flex align-items-center gap-2 mb-0">
                                        <input v-model="visibleColumns" class="form-check-input mt-0" type="checkbox" :value="column.key">
                                        {{ column.label }}
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="!isLoading && filteredItems.length" class="stats-bar">
                <span><strong>{{ filteredItems.length }}</strong> {{ $gettext('tracks') }}</span>
                <span><strong>{{ totalDurationFormatted }}</strong> {{ $gettext('total runtime') }}</span>
                <span><strong>{{ hoursAhead }}</strong> {{ $gettext('hour requested horizon') }}</span>
                <span v-if="coverageEnd">
                    {{ $gettext('Built through') }} <strong>{{ formatDateTime(coverageEnd) }}</strong>
                </span>
                <span v-if="nextUpItem">
                    {{ $gettext('Next up') }}: <strong>{{ nextUpItem.song.title || nextUpItem.song.text }}</strong>
                    {{ $gettext('at') }} <strong>{{ formatTime(nextUpItem.played_at) }}</strong>
                </span>
            </div>

            <div v-if="isLoading" class="loading-state">
                <div class="spinner-border text-primary" role="status" />
                <div class="mt-3 fw-semibold">{{ $gettext('Building the AutoDJ schedule...') }}</div>
                <div class="small text-body-secondary mt-1">
                    {{ $gettext('The requested lookahead is generated before the queue is displayed.') }}
                </div>
            </div>

            <div v-else-if="0 === filteredItems.length" class="empty-state">
                <h2>{{ $gettext('No Queue Entries Found') }}</h2>
                <p>{{ $gettext('AutoDJ did not return any playable queue entries for this period. Check that the station has enabled media and playlists, then build the log again.') }}</p>
            </div>

            <template v-else>
                <section v-for="group in hourGroups" :key="group.epochHour" class="hour-group">
                    <div class="hour-header" :class="{'current-hour': group.isCurrent}">
                        <span v-if="group.isCurrent" class="badge text-bg-primary">{{ $gettext('NOW') }}</span>
                        <strong>{{ group.label }}</strong>
                        <span class="hour-summary">{{ group.items.length }} {{ $gettext('tracks') }} / {{ group.totalDurationFormatted }}</span>
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
                                    <td v-if="visibleColumns.includes('time')" class="queue-time ps-3">{{ formatTime(item.played_at) }}</td>
                                    <td v-if="visibleColumns.includes('title')" class="py-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <span v-if="isNextUp(item)" class="next-marker">{{ $gettext('NEXT') }}</span>
                                            <div>
                                                <div v-if="item.autodj_custom_uri" class="small text-body-secondary">{{ item.autodj_custom_uri }}</div>
                                                <template v-else>
                                                    <strong class="track-title">{{ item.song.title || item.song.text }}</strong>
                                                    <div v-if="item.song.artist" class="small track-artist">{{ item.song.artist }}</div>
                                                </template>
                                            </div>
                                        </div>
                                    </td>
                                    <td v-if="visibleColumns.includes('playlist')" class="playlist-cell">
                                        <span v-if="item.clock_wheel">{{ item.clock_wheel }}</span>
                                        <span v-else>{{ item.playlist || $gettext('General Rotation') }}</span>
                                    </td>
                                    <td v-if="visibleColumns.includes('type')" class="type-cell">
                                        <span :class="typeBadgeClass(item)">{{ typeLabel(item) }}</span>
                                    </td>
                                    <td v-if="visibleColumns.includes('duration')" class="duration-cell pe-3">{{ formatDuration(item.duration) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from "vue";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

interface QueueSong { title: string; artist: string; text: string; }
interface QueueItem {
    id: number;
    played_at: number | null;
    duration: number;
    playlist: string;
    clock_wheel: string;
    is_request: boolean;
    sent_to_autodj: boolean;
    is_played: boolean;
    top_of_hour_legal_id: boolean;
    autodj_custom_uri: string | null;
    media_type: string;
    song: QueueSong;
}
interface HourGroup {
    epochHour: number;
    label: string;
    isCurrent: boolean;
    items: QueueItem[];
    totalDurationFormatted: string;
    hasId: boolean;
}

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const queueUrl = getStationApiUrl("/queue");
const buildUrl = getStationApiUrl("/reports/linear-log/build");

const isLoading = ref(true);
const buildError = ref("");
const hoursAhead = ref(24);
const searchQuery = ref("");
const allItems = ref<QueueItem[]>([]);
const nowTs = ref(Math.floor(Date.now() / 1000));

const columnOptions = [
    {key:"time",label:$gettext("Time")},
    {key:"title",label:$gettext("Title / Artist")},
    {key:"playlist",label:$gettext("Playlist / Clock")},
    {key:"type",label:$gettext("Type")},
    {key:"duration",label:$gettext("Duration")},
];
const visibleColumns = ref(["time","title","playlist","type","duration"]);
const typeFilters = [
    {key:"music",label:$gettext("Music"),activeClass:"btn-success"},
    {key:"talk",label:$gettext("Talk"),activeClass:"btn-warning"},
    {key:"id",label:$gettext("Station ID"),activeClass:"btn-danger"},
    {key:"promo",label:$gettext("Promo"),activeClass:"btn-info"},
    {key:"jingle",label:$gettext("Jingle"),activeClass:"btn-secondary"},
    {key:"podcast",label:$gettext("Podcast"),activeClass:"btn-primary"},
    {key:"stream",label:$gettext("Stream"),activeClass:"btn-dark"},
    {key:"request",label:$gettext("Request"),activeClass:"btn-outline-primary"},
    {key:"clock_wheel",label:$gettext("Clock Wheel"),activeClass:"btn-primary"},
];
const activeTypes = ref(typeFilters.map((item) => item.key));

function toggleType(key: string) {
    activeTypes.value = activeTypes.value.includes(key)
        ? activeTypes.value.filter((item) => item !== key)
        : [...activeTypes.value,key];
}

function resolveType(item: QueueItem): string {
    if (item.is_request) return "request";
    if (item.clock_wheel) return "clock_wheel";
    if (item.top_of_hour_legal_id || "id" === item.media_type) return "id";
    if (item.autodj_custom_uri) return "stream";
    return item.media_type || "music";
}

function typeLabel(item: QueueItem): string {
    const labels: Record<string,string> = {
        music:$gettext("Music"),talk:$gettext("Talk"),id:$gettext("ID"),promo:$gettext("Promo"),
        jingle:$gettext("Jingle"),podcast:$gettext("Podcast"),stream:$gettext("Stream"),
        request:$gettext("Request"),clock_wheel:$gettext("Clock"),
    };
    return labels[resolveType(item)] ?? $gettext("Music");
}

function typeBadgeClass(item: QueueItem): string {
    const classes: Record<string,string> = {
        music:"badge text-bg-success",talk:"badge text-bg-warning",id:"badge text-bg-danger",
        promo:"badge text-bg-info",jingle:"badge text-bg-secondary",podcast:"badge text-bg-primary",
        stream:"badge text-bg-dark",request:"badge text-bg-primary",clock_wheel:"badge text-bg-primary",
    };
    return classes[resolveType(item)] ?? "badge text-bg-success";
}

function rowClasses(item: QueueItem): Record<string,boolean> {
    return {
        "next-up":isNextUp(item),
        "legal-id":item.top_of_hour_legal_id,
        "already-played":item.is_played,
    };
}

function isNextUp(item: QueueItem): boolean {
    return !item.sent_to_autodj && !item.is_played && (item.played_at ?? 0) >= nowTs.value;
}

function formatTime(timestamp: number | null): string {
    if (!timestamp) return "-";
    return new Date(timestamp * 1000).toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true
    });
}
function formatDateTime(timestamp: number): string {
    return new Date(timestamp * 1000).toLocaleString([], {
        weekday: "short",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true
    });
}
function formatDuration(seconds: number): string {
    const minutes = Math.floor((seconds ?? 0) / 60);
    const remain = Math.floor((seconds ?? 0) % 60);
    return `${minutes}:${String(remain).padStart(2,"0")}`;
}
function secondsToHms(total: number): string {
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = Math.floor(total % 60);
    return hours > 0 ? `${hours}h ${minutes}m ${seconds}s` : `${minutes}m ${seconds}s`;
}

const filteredItems = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return allItems.value.filter((item) => {
        if (!activeTypes.value.includes(resolveType(item))) return false;
        if (!query) return true;
        return [item.song.title,item.song.artist,item.song.text,item.playlist,item.clock_wheel,item.autodj_custom_uri]
            .filter(Boolean).join(" ").toLowerCase().includes(query);
    });
});
const totalDurationFormatted = computed(() => secondsToHms(filteredItems.value.reduce((sum,item) => sum + (item.duration ?? 0),0)));
const nextUpItem = computed(() => filteredItems.value.find((item) => isNextUp(item)));
const coverageEnd = computed(() => allItems.value.reduce((latest,item) => Math.max(latest,item.played_at ?? 0),0));

const hourGroups = computed<HourGroup[]>(() => {
    const groups = new Map<number,QueueItem[]>();
    for (const item of filteredItems.value) {
        const timestamp = item.played_at ?? 0;
        const hour = Math.floor(timestamp / 3600) * 3600;
        const items = groups.get(hour) ?? [];
        items.push(item);
        groups.set(hour,items);
    }
    const currentHour = Math.floor(nowTs.value / 3600) * 3600;
    return [...groups.entries()].sort(([a],[b]) => a - b).map(([epochHour,items]) => {
        const sorted = [...items].sort((a,b) => (a.played_at ?? 0) - (b.played_at ?? 0));
        const total = sorted.reduce((sum,item) => sum + (item.duration ?? 0),0);
        return {
            epochHour,
            label: new Date(epochHour * 1000).toLocaleString([], {
                weekday: "short",
                month: "short",
                day: "numeric",
                hour: "numeric",
                minute: "2-digit",
                hour12: true
            }),
            isCurrent:epochHour === currentHour,
            items:sorted,
            totalDurationFormatted:secondsToHms(total),
            hasId:sorted.some((item) => item.top_of_hour_legal_id || "id" === item.media_type),
        };
    });
});

const refresh = async () => {
    isLoading.value = true;
    buildError.value = "";
    nowTs.value = Math.floor(Date.now() / 1000);
    let buildFailed = false;
    try {
        try {
            // No axios timeout override here on purpose: a full 24-48h build
            // can legitimately take a while server-side. The backend is what
            // needs the longer execution budget (see buildLinearLogAction),
            // not the client request.
            await axios.post(buildUrl.value, {hours:hoursAhead.value});
        } catch (error: any) {
            buildFailed = true;
            buildError.value = error?.response?.data?.message ?? error?.message ?? $gettext("AutoDJ could not build the requested schedule.");
        }

        const {data} = await axios.get(queueUrl.value, {params:{per_page:5000,page:1}});
        const cutoff = nowTs.value + (hoursAhead.value * 3600);
        const rows: QueueItem[] = data?.rows ?? data ?? [];
        allItems.value = rows.filter((item) => {
            const playedAt = item.played_at ?? 0;
            return playedAt >= nowTs.value - 300 && playedAt <= cutoff;
        });

        // The build call can fail (e.g. a request timeout) while still
        // leaving a shorter, previously-built queue in place. Without this
        // check that partial queue renders looking like a normal, complete
        // result -- the exact "silently truncated" symptom this is meant to
        // surface. Flag it clearly whenever the actual coverage falls well
        // short of what was requested.
        if (!buildFailed && allItems.value.length > 0) {
            const latestCovered = allItems.value.reduce((latest, item) => Math.max(latest, item.played_at ?? 0), 0);
            const shortfallSeconds = cutoff - latestCovered;
            if (shortfallSeconds > 3600) {
                const shortfallHours = Math.round(shortfallSeconds / 3600);
                buildError.value = $gettext(
                    "The log only reached %{ hours } hour(s) short of the requested horizon. The build may have been cut off before finishing -- try Build and Refresh again."
                ).replace("%{ hours }", String(shortfallHours));
            }
        }
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => { void refresh(); });
</script>

<style scoped>
.linear-log-page{max-width:1280px;margin:0 auto;color:var(--bs-body-color)}.linear-log-card{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.9rem;background:color-mix(in srgb,var(--bs-body-bg) 96%,var(--bs-secondary-bg) 4%);box-shadow:0 .3rem 1rem rgba(0,0,0,.07)}.linear-log-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1.15rem 1.25rem;color:#fff;background:linear-gradient(90deg,#0a6fc2 0%,#2196f3 100%)}.linear-log-header h1{margin:0;color:#fff;font-size:1.45rem;font-weight:750}.linear-log-header p{margin:.25rem 0 0;color:rgba(255,255,255,.9)}.hours-select{width:auto}.hours-select .form-select{min-width:110px}.filter-bar{padding:.85rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-body-bg) 94%,var(--bs-secondary-bg) 6%)}.filter-label{color:color-mix(in srgb,var(--bs-body-color) 84%,transparent);font-size:.85rem;font-weight:700}.search-box{width:210px}.stats-bar{display:flex;flex-wrap:wrap;gap:1.25rem;padding:.72rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 68%,var(--bs-body-bg));color:color-mix(in srgb,var(--bs-body-color) 80%,transparent);font-size:.82rem}.stats-bar strong{color:var(--bs-body-color)}.loading-state,.empty-state{padding:4rem 1.5rem;text-align:center;color:var(--bs-body-color)}.loading-state .text-body-secondary,.empty-state p{color:color-mix(in srgb,var(--bs-body-color) 78%,transparent)!important}.empty-state h2{font-size:1.1rem;color:var(--bs-body-color)}.empty-state p{max-width:720px;margin:.5rem auto 0}.hour-header{display:flex;align-items:center;gap:.6rem;padding:.68rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg));color:var(--bs-body-color)}.hour-header.current-hour{background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 18%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 8%,var(--bs-body-bg)));box-shadow:inset 3px 0 0 var(--bs-primary)}.hour-summary{color:color-mix(in srgb,var(--bs-body-color) 72%,transparent);font-size:.78rem}.linear-table{--bs-table-color:var(--bs-body-color);--bs-table-bg:var(--bs-body-bg);--bs-table-hover-color:var(--bs-body-color);--bs-table-hover-bg:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}.queue-row td{border-color:var(--bs-border-color);color:var(--bs-body-color)}.queue-row.next-up td{background:color-mix(in srgb,var(--bs-success-bg-subtle) 30%,var(--bs-body-bg))}.queue-row.legal-id td{background:color-mix(in srgb,var(--bs-danger-bg-subtle) 28%,var(--bs-body-bg))}.queue-row.already-played{opacity:.72}.queue-time,.duration-cell{font-family:var(--bs-font-monospace);font-size:.78rem;white-space:nowrap;color:color-mix(in srgb,var(--bs-body-color) 88%,transparent)!important}.queue-time{width:95px}.duration-cell{width:70px;text-align:right}.playlist-cell{width:180px;color:color-mix(in srgb,var(--bs-body-color) 80%,transparent)!important;font-size:.8rem}.type-cell{width:95px}.track-title{color:var(--bs-body-color)}.track-artist{color:color-mix(in srgb,var(--bs-body-color) 78%,transparent)!important}.next-marker{display:inline-block;padding:.18rem .35rem;border-radius:.3rem;background:var(--bs-success);color:#fff;font-size:.62rem;font-weight:750;letter-spacing:.04em}.alert-warning{color:var(--bs-warning-text-emphasis);background:color-mix(in srgb,var(--bs-warning-bg-subtle) 78%,var(--bs-body-bg));border-color:color-mix(in srgb,var(--bs-warning) 45%,var(--bs-border-color))}@media(max-width:767px){.linear-log-header{align-items:flex-start;flex-direction:column}.search-box{width:100%}}
</style>
