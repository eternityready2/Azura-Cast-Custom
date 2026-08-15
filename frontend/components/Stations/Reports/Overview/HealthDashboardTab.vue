<template>
    <loading :loading="loading">
        <div
            v-if="report"
            class="mb-3"
        >
            <span
                class="badge fs-6"
                :class="statusBadgeClass"
            >
                {{ statusLabel }}
            </span>
        </div>

        <div
            v-if="report"
            class="row g-3"
        >
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.listeners_now }}</div>
                    <div class="small text-muted">{{ $gettext('Listeners now') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.media_tracks }}</div>
                    <div class="small text-muted">{{ $gettext('Media tracks') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.do_not_play_count }}</div>
                    <div class="small text-muted">{{ $gettext('Do-not-play') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.empty_playlists }}</div>
                    <div class="small text-muted">{{ $gettext('Empty playlists') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.clock_wheel_fallbacks_7d }}</div>
                    <div class="small text-muted">{{ $gettext('Wheel fallbacks (7d)') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">
                        {{ report.legal_id_compliance_percent ?? '—' }}<span
                            v-if="report.legal_id_compliance_percent != null"
                            class="fs-6"
                        >%</span>
                    </div>
                    <div class="small text-muted">{{ $gettext('Top of Hour compliance (7d)') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">{{ report.upcoming_holidays }}</div>
                    <div class="small text-muted">{{ $gettext('Upcoming holidays') }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">
                        {{ report.autodj_enabled ? $gettext('On') : $gettext('Off') }}
                    </div>
                    <div class="small text-muted">{{ $gettext('AutoDJ') }}</div>
                </div>
            </div>
        </div>

        <h3
            v-if="report?.stream_mounts?.length"
            class="h6 mt-4 mb-2"
        >
            {{ $gettext('Stream quality (mounts)') }}
        </h3>
        <table
            v-if="report?.stream_mounts?.length"
            class="table table-sm table-bordered"
        >
            <thead>
                <tr>
                    <th>{{ $gettext('Mount') }}</th>
                    <th>{{ $gettext('Format') }}</th>
                    <th>{{ $gettext('Bitrate') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(mount, idx) in report.stream_mounts"
                    :key="idx"
                >
                    <td>
                        {{ mount.name }}
                        <span
                            v-if="mount.is_default"
                            class="badge text-bg-secondary ms-1"
                        >{{ $gettext('Default') }}</span>
                    </td>
                    <td>{{ mount.format }}</td>
                    <td>{{ mount.bitrate }} kbps</td>
                </tr>
            </tbody>
        </table>
    </loading>
</template>

<script setup lang="ts">
import {computed, ref, watch} from 'vue';
import Loading from '~/components/Common/Loading.vue';
import {useAxios} from '~/vendor/axios.ts';
import {useTranslate} from '~/vendor/gettext';

interface HealthReport {
    is_online: boolean;
    autodj_enabled: boolean;
    listeners_now: number;
    media_tracks: number;
    do_not_play_count: number;
    empty_playlists: number;
    clock_wheel_fallbacks_7d: number;
    legal_id_compliance_percent: number | null;
    upcoming_holidays: number;
    overall_status: string | null;
    stream_mounts: {name: string; format: string; bitrate: number; is_default: boolean}[];
}

const props = defineProps<{
    apiUrl: string;
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const loading = ref(false);
const report = ref<HealthReport | null>(null);

const statusBadgeClass = computed(() => {
    switch (report.value?.overall_status) {
        case 'ok':
            return 'text-bg-success';
        case 'caution':
            return 'text-bg-info';
        case 'warning':
            return 'text-bg-warning';
        case 'critical':
            return 'text-bg-danger';
        default:
            return 'text-bg-secondary';
    }
});

const statusLabel = computed(() => {
    switch (report.value?.overall_status) {
        case 'ok':
            return $gettext('Healthy');
        case 'caution':
            return $gettext('Minor issues');
        case 'warning':
            return $gettext('Needs attention');
        case 'critical':
            return $gettext('Critical');
        default:
            return $gettext('Unknown');
    }
});

const load = async () => {
    loading.value = true;
    try {
        const {data} = await axios.get<HealthReport>(props.apiUrl);
        report.value = data;
    } finally {
        loading.value = false;
    }
};

watch(() => props.apiUrl, () => void load(), {immediate: true});
</script>
