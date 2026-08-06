<template>
    <card-page header-id="hdr_stats_listeners">
        <template #header="{id}">
            <div class="d-flex align-items-center">
                <h3
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Listeners') }}
                </h3>
                <router-link
                    v-if="userAllowedForStation(StationPermissions.Reports)"
                    class="btn btn-link text-white ms-1 px-1 py-0"
                    :to="{name: 'stations:reports:listeners'}"
                    :title="$gettext('Listener Report')"
                >
                    <icon-ic-assignment/>
                </router-link>
            </div>
        </template>

        <div class="card-body">
            <div class="stats-box__listeners-current">
                <div class="stats-box__number stats-box__number--with-icon">
                    <icon-ic-headphones class="stats-box__headphones"/>
                    <span>{{ np.listeners?.total ?? 0 }}</span>
                </div>
                <div class="stats-box__subvalue">
                    {{ currentListenersCaption }}
                </div>
            </div>

            <hr class="stats-box__hr">

            <div class="stats-box__listeners-24h">
                <div class="stats-box__number">
                    {{ stats?.unique_listeners_24h ?? 0 }}
                </div>
                <div class="stats-box__label">
                    {{ $gettext('Last 24 hours') }}
                </div>
            </div>
        </div>
    </card-page>

    <card-page header-id="hdr_stats_tlh">
        <template #header="{id}">
            <h3
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('Total Listening Hours') }}
            </h3>
        </template>

        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="d-flex align-items-baseline gap-2">
                    <div class="stats-box__number">
                        {{ currentTlhValue }}
                    </div>
                    <div class="stats-box__label my-0">
                        {{ $gettext('TLH') }}
                    </div>
                </div>
                <div class="btn-group btn-group-sm">
                    <button
                        type="button"
                        class="btn"
                        :class="tlhPeriod === '24h' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="tlhPeriod = '24h'"
                    >
                        {{ $gettext('24h') }}
                    </button>
                    <button
                        type="button"
                        class="btn"
                        :class="tlhPeriod === '7d' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="tlhPeriod = '7d'"
                    >
                        {{ $gettext('7d') }}
                    </button>
                    <button
                        type="button"
                        class="btn"
                        :class="tlhPeriod === '30d' ? 'btn-primary' : 'btn-outline-secondary'"
                        @click="tlhPeriod = '30d'"
                    >
                        {{ $gettext('30d') }}
                    </button>
                </div>
            </div>
            <div class="stats-box__description">
                {{ $gettext('Combined hours all listeners spent connected to your station in this period.') }}
            </div>
        </div>
    </card-page>

    <card-page header-id="hdr_stats_storage">
        <template #header="{id}">
            <h3
                :id="id"
                class="card-title my-0"
            >
                {{ $gettext('Media Storage') }}
            </h3>
        </template>

        <div class="card-body">
            <div class="stats-box__storage-split">
                <div class="stats-box__storage-stat">
                    <div class="stats-box__number stats-box__number--storage">
                        {{ storageUsedDisplay }}
                    </div>
                    <div class="stats-box__label">
                        {{ $gettext('USED') }}
                    </div>
                </div>
                <div class="stats-box__storage-stat">
                    <div class="stats-box__number stats-box__number--storage">
                        {{ storageTotalDisplay }}
                    </div>
                    <div class="stats-box__label">
                        {{ $gettext('TOTAL') }}
                    </div>
                </div>
            </div>

            <div
                class="progress stats-box__storage-progress"
                role="progressbar"
                :aria-label="storagePercent + '%'"
                :aria-valuenow="storagePercent"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="progress-bar"
                    :class="storageProgressVariant"
                    :style="{ width: storagePercent + '%' }"
                />
            </div>

            <div class="stats-box__description mt-2">
                {{ storageFreeLine }}
            </div>
            <div class="stats-box__description">
                {{ $gettext('This indicates how much storage your uploaded tracks take up') }}
            </div>
        </div>
    </card-page>
</template>

<script setup lang="ts">
import {computed, ref} from 'vue';
import {toRefs, useDocumentVisibility} from '@vueuse/core';
import {useQuery} from '@tanstack/vue-query';
import CardPage from '~/components/Common/CardPage.vue';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useTranslate} from '~/vendor/gettext';
import formatFileSize from '~/functions/formatFileSize.ts';
import useNowPlaying from '~/functions/useNowPlaying';
import {useStationProfileData} from '~/components/Stations/Profile/useProfileQuery.ts';
import {useStationData} from '~/functions/useStationQuery.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {StationPermissions} from '~/entities/ApiInterfaces.ts';
import {useUserAllowedForStation} from '~/functions/useUserallowedForStation.ts';
import IconIcHeadphones from '~icons/ic/baseline-headphones';
import IconIcAssignment from '~icons/ic/baseline-assignment';

const {$gettext} = useTranslate();
const {axiosSilent} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {userAllowedForStation} = useUserAllowedForStation();

const stationData = useStationData();
const {isEnabled} = toRefs(stationData);

const profileData = useStationProfileData();
const {nowPlayingProps} = toRefs(profileData);
const {np} = useNowPlaying(nowPlayingProps);

interface OverviewStats {
    unique_listeners_24h: number;
    total_listening_hours: {'24h': number; '7d': number; '30d': number};
    storage: {
        used: string;
        free: string;
        quota: string | null;
        percent: number;
    };
}

const apiUrl = getStationApiUrl('/overview-stats');

// This data (24h unique listeners, total listening hours, storage) moves on the
// scale of minutes, not seconds. More importantly: Chrome freezes hidden tabs after
// several minutes of being backgrounded (its Page Lifecycle API), and forcibly
// terminates any network request that's in-flight at the moment of freeze. A fast
// interval left running in the background all but guarantees a request is caught
// mid-flight when that happens, producing a real, unavoidable network error on
// resume. Rather than just handling that error more quietly, this query is fully
// disabled while the tab is hidden, so nothing is ever in-flight for Chrome to kill.
// A single clean refetch fires when the tab becomes visible again.
const visibility = useDocumentVisibility();

const {data: stats} = useQuery<OverviewStats>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationProfile,
        'overview-stats',
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axiosSilent.get<OverviewStats>(apiUrl.value, {signal});
        return data;
    },
    refetchInterval: 60 * 1000,
    enabled: computed(() => isEnabled.value && visibility.value === 'visible'),
});

const tlhPeriod = ref<'24h' | '7d' | '30d'>('30d');

const currentTlhValue = computed(() => {
    return stats.value?.total_listening_hours?.[tlhPeriod.value] ?? 0;
});

const currentListenersCaption = computed(() => {
    return $gettext(
        'Current listeners · %{unique} unique',
        {unique: String(np.value.listeners?.unique ?? 0)}
    );
});

const SIZE_UNITS = ['B', 'kB', 'MB', 'GB', 'TB'] as const;

/**
 * Parse readable sizes like "11.4 GB" / "512 MB" into bytes (1024-based).
 */
const parseReadableSize = (size: string | null | undefined): number => {
    if (!size) {
        return 0;
    }

    const unit = size.replace(/[^bkmgtpezy]/gi, '');
    const numeric = Number.parseFloat(size.replace(/[^\d.]/g, ''));

    if (Number.isNaN(numeric)) {
        return 0;
    }

    if (!unit) {
        return numeric;
    }

    const power = 'bkmgtpezy'.indexOf(unit[0].toLowerCase());
    return Math.floor(numeric * Math.pow(1024, power > 0 ? power : 0));
};

const formatSize = (bytes: number): string => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    return formatFileSize(bytes);
};

const unitPowerForBytes = (bytes: number): number => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return 0;
    }

    return Math.min(
        SIZE_UNITS.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024))
    );
};

const formatInUnit = (bytes: number, power: number, decimals = 1): string => {
    const value = bytes / Math.pow(1024, power);
    return value.toFixed(decimals);
};

const storageTotals = computed(() => {
    const storage = stats.value?.storage;
    if (!storage) {
        return null;
    }

    const usedBytes = parseReadableSize(storage.used);
    const freeBytes = parseReadableSize(storage.free);
    const totalBytes = storage.quota
        ? parseReadableSize(storage.quota)
        : usedBytes + freeBytes;

    return {
        usedBytes,
        freeBytes,
        totalBytes,
        freeLabel: storage.free,
        percent: storage.percent,
    };
});

const storageUsedDisplay = computed(() => {
    const totals = storageTotals.value;
    if (!totals) {
        return '—';
    }

    const power = unitPowerForBytes(totals.totalBytes || totals.usedBytes);
    return `${formatInUnit(totals.usedBytes, power)} ${SIZE_UNITS[power]}`;
});

const storageTotalDisplay = computed(() => {
    const totals = storageTotals.value;
    if (!totals) {
        return '—';
    }

    const power = unitPowerForBytes(totals.totalBytes || totals.usedBytes);
    return `${formatInUnit(totals.totalBytes, power)} ${SIZE_UNITS[power]}`;
});

const storagePercent = computed(() => {
    const totals = storageTotals.value;
    if (!totals) {
        return 0;
    }

    if (typeof totals.percent === 'number' && Number.isFinite(totals.percent)) {
        return Math.min(100, Math.max(0, Math.round(totals.percent)));
    }

    if (totals.totalBytes <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round((totals.usedBytes / totals.totalBytes) * 100)));
});

const storageProgressVariant = computed(() => {
    if (storagePercent.value > 85) {
        return 'text-bg-danger';
    }
    if (storagePercent.value > 65) {
        return 'text-bg-warning';
    }
    return 'text-bg-primary';
});

/** e.g. "21.9 GB free" */
const storageFreeLine = computed(() => {
    const totals = storageTotals.value;
    if (!totals) {
        return '—';
    }

    const freeLabel = totals.freeLabel || formatSize(totals.freeBytes);

    return $gettext('%{space} free', {space: freeLabel});
});
</script>

<style scoped>
.stats-box__listeners-current {
    min-width: 0;
}

.stats-box__hr {
    margin: 0.85rem 0;
    border: 0;
    border-top: 1px solid var(--bs-border-color);
    opacity: 1;
}

.stats-box__listeners-24h {
    min-width: 0;
}

.stats-box__storage-split {
    display: flex;
    align-items: flex-start;
    gap: 2rem;
}

.stats-box__storage-stat {
    min-width: 0;
}

.stats-box__storage-progress {
    height: 0.5rem;
    margin-top: 0.75rem;
    border-radius: 999px;
    overflow: hidden;
    background-color: var(--bs-secondary-bg, rgba(0, 0, 0, 0.08));
}

.stats-box__storage-progress .progress-bar {
    border-radius: 999px;
}

.stats-box__number {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
}

.stats-box__number--storage {
    font-size: 1.75rem;
}

.stats-box__number--with-icon {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.stats-box__headphones {
    font-size: 1.35rem;
    flex-shrink: 0;
}

.stats-box__label {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    color: var(--bs-secondary-color);
    margin-top: 0.25rem;
}

.stats-box__subvalue {
    display: block;
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
    margin-top: 0.25rem;
}

.stats-box__description {
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
    margin-top: 0.5rem;
}
</style>
