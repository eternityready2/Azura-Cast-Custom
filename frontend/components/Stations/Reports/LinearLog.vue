<template>
    <div class="card">
        <!-- Header -->
        <div class="card-header text-bg-primary">
            <div class="d-lg-flex align-items-center">
                <div class="flex-fill">
                    <h2 class="card-title my-0">
                        {{ $gettext('24-Hour Playout Log') }}
                    </h2>
                    <p class="card-subtitle mt-1 mb-0 opacity-75 small">
                        {{ $gettext('Upcoming scheduled programming built by AutoDJ') }}
                    </p>
                </div>

                <div class="d-flex align-items-center gap-2 mt-2 mt-lg-0 flex-wrap">
                    <!-- Hours ahead selector -->
                    <div class="input-group input-group-sm" style="width:auto">
                        <span class="input-group-text">{{ $gettext('Show next') }}</span>
                        <select
                            v-model.number="hoursAhead"
                            class="form-select"
                            style="min-width:120px"
                            @change="refresh"
                        >
                            <option :value="6">{{ $gettext('6 hours') }}</option>
                            <option :value="12">{{ $gettext('12 hours') }}</option>
                            <option :value="24">{{ $gettext('24 hours') }}</option>
                            <option :value="48">{{ $gettext('48 hours') }}</option>
                        </select>
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-dark"
                        :disabled="isLoading"
                        @click="refresh"
                    >
                        <span
                            v-if="isLoading"
                            class="spinner-border spinner-border-sm me-1"
                        />
                        {{ isLoading ? $gettext('Loading…') : $gettext('Refresh') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="card-body border-bottom py-2">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <!-- Type filter badges -->
                <span class="text-muted small me-1">{{ $gettext('Show:') }}</span>

                <button
                    v-for="t in typeFilters"
                    :key="t.key"
                    type="button"
                    class="btn btn-sm"
                    :class="activeTypes.includes(t.key) ? t.activeClass : 'btn-outline-secondary'"
                    @click="toggleType(t.key)"
                >
                    {{ t.label }}
                </button>

                <div class="ms-auto d-flex gap-2">
                    <!-- Search -->
                    <input
                        v-model="searchQuery"
                        type="search"
                        class="form-control form-control-sm"
                        style="width:200px"
                        :placeholder="$gettext('Search title / artist…')"
                    >

                    <!-- Column visibility toggle -->
                    <div class="dropdown">
                        <button
                            class="btn btn-sm btn-outline-secondary dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                        >
                            {{ $gettext('Columns') }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li
                                v-for="col in columnOptions"
                                :key="col.key"
                            >
                                <label class="dropdown-item d-flex align-items-center gap-2 mb-0">
                                    <input
                                        v-model="visibleColumns"
                                        type="checkbox"
                                        :value="col.key"
                                        class="form-check-input mt-0"
                                    >
                                    {{ col.label }}
                                </label>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats bar -->
        <div
            v-if="!isLoading && filteredItems.length > 0"
            class="card-body border-bottom py-2 bg-light"
        >
            <div class="d-flex flex-wrap gap-3 small text-muted">
                <span>
                    <strong class="text-dark">{{ filteredItems.length }}</strong>
                    {{ $gettext('tracks') }}
                </span>
                <span>
                    <strong class="text-dark">{{ totalDurationFormatted }}</strong>
                    {{ $gettext('total runtime') }}
                </span>
                <span v-if="nextUpItem">
                    {{ $gettext('Next up:') }}
                    <strong class="text-dark">{{ nextUpItem.song.title || nextUpItem.song.text }}</strong>
                    {{ $gettext('at') }}
                    <strong class="text-dark">{{ formatTime(nextUpItem.played_at) }}</strong>
                </span>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-if="!isLoading && filteredItems.length === 0"
            class="card-body text-center py-5"
        >
            <div class="text-muted">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="48"
                    height="48"
                    fill="currentColor"
                    class="mb-3 opacity-50"
                    viewBox="0 0 16 16"
                >
                    <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4.5 5.5a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1z"/>
                </svg>
                <p class="mb-1 fw-semibold">
                    {{ $gettext('No queue entries found') }}
                </p>
                <p class="mb-0 small">
                    {{ $gettext('Enable "Build Linear Log In Advance" and set "AutoDJ Queue Time Lookahead" in Station Settings → AutoDJ to populate this log.') }}
                </p>
            </div>
        </div>

        <!-- Loading state -->
        <div
            v-if="isLoading"
            class="card-body text-center py-5"
        >
            <div
                class="spinner-border text-primary"
                role="status"
            >
                <span class="visually-hidden">{{ $gettext('Loading…') }}</span>
            </div>
        </div>

        <!-- Hour groups -->
        <div
            v-for="group in hourGroups"
            :key="group.epochHour"
        >
            <!-- Hour header -->
            <div
                class="d-flex align-items-center px-3 py-2 border-bottom"
                :class="group.isCurrent ? 'bg-primary bg-opacity-10' : 'bg-body-secondary'"
            >
                <span
                    v-if="group.isCurrent"
                    class="badge bg-primary me-2"
                >
                    {{ $gettext('NOW') }}
                </span>
                <strong class="me-2">{{ group.label }}</strong>
                <span class="text-muted small">
                    {{ group.items.length }} {{ $gettext('tracks') }} &bull; {{ group.totalDurationFormatted }}
                </span>
                <span
                    v-if="group.hasId"
                    class="ms-auto badge bg-danger"
                >
                    {{ $gettext('Station ID') }} ✓
                </span>
            </div>

            <!-- Tracks table -->
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <tbody>
                        <tr
                            v-for="item in group.items"
                            :key="item.id"
                            :class="rowClasses(item)"
                        >
                            <!-- Time -->
                            <td
                                v-if="visibleColumns.includes('time')"
                                class="text-nowrap font-monospace small ps-3"
                                style="width:80px"
                            >
                                {{ formatTime(item.played_at) }}
                            </td>

                            <!-- Next-up indicator + Title/Artist -->
                            <td
                                v-if="visibleColumns.includes('title')"
                                class="py-2"
                            >
                                <div class="d-flex align-items-start gap-2">
                                    <span
                                        v-if="isNextUp(item)"
                                        class="badge bg-success mt-1 flex-shrink-0"
                                    >
                                        ▶
                                    </span>
                                    <div>
                                        <div
                                            v-if="item.autodj_custom_uri"
                                            class="text-muted fst-italic"
                                        >
                                            {{ item.autodj_custom_uri }}
                                        </div>
                                        <div v-else>
                                            <strong>{{ item.song.title || item.song.text }}</strong>
                                            <div
                                                v-if="item.song.artist"
                                                class="small text-muted"
                                            >
                                                {{ item.song.artist }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Playlist -->
                            <td
                                v-if="visibleColumns.includes('playlist')"
                                class="small text-muted"
                                style="width:160px"
                            >
                                <span v-if="item.clock_wheel">
                                    🕐 {{ item.clock_wheel }}
                                </span>
                                <span v-else>
                                    {{ item.playlist || '—' }}
                                </span>
                            </td>

                            <!-- Type badge -->
                            <td
                                v-if="visibleColumns.includes('type')"
                                style="width:90px"
                            >
                                <span :class="typeBadgeClass(item)">
                                    {{ typeLabel(item) }}
                                </span>
                            </td>

                            <!-- Duration -->
                            <td
                                v-if="visibleColumns.includes('duration')"
                                class="font-monospace small text-end pe-3"
                                style="width:65px"
                            >
                                {{ formatDuration(item.duration) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';

/* ───── types ───── */
interface QueueSong {
    title: string;
    artist: string;
    text: string;
}

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

/* ───── setup ───── */
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const isLoading = ref(true);
const hoursAhead = ref(24);
const searchQuery = ref('');
const allItems = ref<QueueItem[]>([]);

/* ───── column config ───── */
const columnOptions = [
    {key: 'time', label: 'Time'},
    {key: 'title', label: 'Title / Artist'},
    {key: 'playlist', label: 'Playlist / Clock'},
    {key: 'type', label: 'Type'},
    {key: 'duration', label: 'Duration'},
];
const visibleColumns = ref(['time', 'title', 'playlist', 'type', 'duration']);

/* ───── type filter config ───── */
const typeFilters = [
    {key: 'music', label: '🎵 Music', activeClass: 'btn-success'},
    {key: 'talk', label: '🎙 Talk', activeClass: 'btn-warning'},
    {key: 'id', label: '📻 Station ID', activeClass: 'btn-danger'},
    {key: 'promo', label: '📣 Promo', activeClass: 'btn-info'},
    {key: 'jingle', label: '🔔 Jingle', activeClass: 'btn-secondary'},
    {key: 'podcast', label: '🎧 Podcast', activeClass: 'btn-primary'},
    {key: 'stream', label: '📡 Stream', activeClass: 'btn-dark'},
    {key: 'request', label: '🙋 Request', activeClass: 'btn-purple'},
    {key: 'clock_wheel', label: '🕐 Clock Wheel', activeClass: 'btn-primary'},
];
const activeTypes = ref(typeFilters.map(t => t.key));

function toggleType(key: string) {
    if (activeTypes.value.includes(key)) {
        activeTypes.value = activeTypes.value.filter(k => k !== key);
    } else {
        activeTypes.value = [...activeTypes.value, key];
    }
}

/* ───── helpers ───── */
function resolveType(item: QueueItem): string {
    if (item.is_request) return 'request';
    if (item.clock_wheel) return 'clock_wheel';
    if (item.top_of_hour_legal_id || item.media_type === 'id') return 'id';
    if (item.autodj_custom_uri) return 'stream';
    return item.media_type || 'music';
}

function typeLabel(item: QueueItem): string {
    const map: Record<string, string> = {
        music: 'Music', talk: 'Talk', id: 'ID', promo: 'Promo',
        jingle: 'Jingle', podcast: 'Podcast', stream: 'Stream',
        request: 'Request', clock_wheel: 'Clock',
    };
    return map[resolveType(item)] ?? 'Music';
}

function typeBadgeClass(item: QueueItem): string {
    const map: Record<string, string> = {
        music: 'badge bg-success',
        talk: 'badge bg-warning text-dark',
        id: 'badge bg-danger',
        promo: 'badge bg-info text-dark',
        jingle: 'badge bg-secondary',
        podcast: 'badge bg-primary',
        stream: 'badge bg-dark',
        request: 'badge bg-purple text-white',
        clock_wheel: 'badge bg-primary',
    };
    return map[resolveType(item)] ?? 'badge bg-success';
}

function rowClasses(item: QueueItem): Record<string, boolean> {
    return {
        'table-success table-active': isNextUp(item),
        'table-danger': item.top_of_hour_legal_id,
        'opacity-50': item.is_played,
    };
}

const nowTs = ref(Math.floor(Date.now() / 1000));

function isNextUp(item: QueueItem): boolean {
    return !item.sent_to_autodj && !item.is_played
        && (item.played_at ?? 0) >= nowTs.value;
}

function formatTime(ts: number | null): string {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleTimeString([], {
        hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true,
    });
}

function formatDuration(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

function secondsToHms(total: number): string {
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = Math.floor(total % 60);
    return h > 0
        ? `${h}h ${m}m ${s}s`
        : `${m}m ${s}s`;
}

/* ───── filtering ───── */
const filteredItems = computed(() => {
    const q = searchQuery.value.toLowerCase();
    return allItems.value.filter(item => {
        if (!activeTypes.value.includes(resolveType(item))) return false;
        if (q) {
            const haystack = [
                item.song.title, item.song.artist, item.song.text,
                item.playlist, item.clock_wheel,
            ].join(' ').toLowerCase();
            if (!haystack.includes(q)) return false;
        }
        return true;
    });
});

const totalDurationFormatted = computed(() =>
    secondsToHms(filteredItems.value.reduce((s, i) => s + (i.duration ?? 0), 0))
);

const nextUpItem = computed(() =>
    filteredItems.value.find(i => !i.sent_to_autodj && !i.is_played
        && (i.played_at ?? 0) >= nowTs.value)
);

/* ───── hour grouping ───── */
const hourGroups = computed<HourGroup[]>(() => {
    const map = new Map<number, QueueItem[]>();
    for (const item of filteredItems.value) {
        const ts = item.played_at ?? 0;
        const epochHour = Math.floor(ts / 3600) * 3600;
        if (!map.has(epochHour)) map.set(epochHour, []);
        map.get(epochHour)!.push(item);
    }

    const currentHour = Math.floor(nowTs.value / 3600) * 3600;

    return [...map.entries()]
        .sort(([a], [b]) => a - b)
        .map(([epochHour, items]) => {
            const sorted = [...items].sort((a, b) => (a.played_at ?? 0) - (b.played_at ?? 0));
            const totalSec = sorted.reduce((s, i) => s + (i.duration ?? 0), 0);
            const d = new Date(epochHour * 1000);
            const label = d.toLocaleString([], {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true,
            });
            return {
                epochHour,
                label,
                isCurrent: epochHour === currentHour,
                items: sorted,
                totalDurationFormatted: secondsToHms(totalSec),
                hasId: sorted.some(i => i.top_of_hour_legal_id || i.media_type === 'id'),
            };
        });
});

/* ───── data loading ───── */
const refresh = async () => {
    isLoading.value = true;
    nowTs.value = Math.floor(Date.now() / 1000);

    try {
        const url = getStationApiUrl('/queue');
        const {data} = await axios.get(url.value, {
            params: {per_page: 2000, page: 1},
        });

        const cutoff = nowTs.value + hoursAhead.value * 3600;
        const rows: QueueItem[] = (data?.rows ?? data ?? []).filter((r: QueueItem) => {
            const pt = r.played_at ?? 0;
            return pt >= nowTs.value - 300 && pt <= cutoff;
        });

        allItems.value = rows;
    } finally {
        isLoading.value = false;
    }
};

onMounted(refresh);
</script>

<style scoped>
.btn-purple {
    --bs-btn-color: #fff;
    --bs-btn-bg: #6f42c1;
    --bs-btn-border-color: #6f42c1;
}
.badge.bg-purple {
    background-color: #6f42c1 !important;
}
</style>
