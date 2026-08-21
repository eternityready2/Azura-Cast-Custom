<template>
    <div class="simulator-page">
        <header class="feature-title mb-4">
            <h1>{{ $gettext('Playout Simulator') }}</h1>
            <p>{{ $gettext('Preview how scheduled programming resolves before it reaches the live AutoDJ queue') }}</p>
        </header>

        <section class="sim-panel mb-4">
            <form class="row g-3 align-items-end" @submit.prevent="simulate">
                <div class="col-md-4">
                    <label for="simulation_date" class="form-label">{{ $gettext('Date') }}</label>
                    <input id="simulation_date" v-model="date" class="form-control" type="date">
                </div>
                <div class="col-md-3">
                    <label for="simulation_time" class="form-label">{{ $gettext('Start Time') }}</label>
                    <input id="simulation_time" v-model="time" class="form-control" type="time">
                </div>
                <div class="col-md-3">
                    <label for="simulation_duration" class="form-label">{{ $gettext('Duration') }}</label>
                    <select id="simulation_duration" v-model.number="duration" class="form-select">
                        <option :value="30">{{ $gettext('30 minutes') }}</option>
                        <option :value="60">{{ $gettext('1 hour') }}</option>
                        <option :value="120">{{ $gettext('2 hours') }}</option>
                        <option :value="360">{{ $gettext('6 hours') }}</option>
                        <option :value="720">{{ $gettext('12 hours') }}</option>
                        <option :value="1440">{{ $gettext('24 hours') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                        <span v-if="loading" class="spinner-border spinner-border-sm me-1" />
                        {{ $gettext('Simulate') }}
                    </button>
                </div>
            </form>
        </section>

        <div v-if="result" class="d-grid gap-3">
            <section class="result-card">
                <div class="result-header">
                    <div>
                        <h2>{{ $gettext('Schedule Windows') }}</h2>
                        <p>{{ $gettext('Scheduled playlists and shows active during this period') }}</p>
                    </div>
                    <span class="badge text-bg-secondary">{{ result.timezone }}</span>
                </div>
                <div v-if="0 === result.schedule_windows.length" class="result-empty">
                    {{ $gettext('No scheduled windows overlap this period.') }}
                </div>
                <div v-else class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>{{ $gettext('Start') }}</th><th>{{ $gettext('End') }}</th><th>{{ $gettext('Source') }}</th><th>{{ $gettext('Type') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(item, index) in result.schedule_windows" :key="`${item.start}-${index}`">
                                <td class="font-monospace">{{ item.start }}</td>
                                <td class="font-monospace">{{ item.end }}</td>
                                <td class="fw-semibold">{{ item.name }}</td>
                                <td><span class="badge text-bg-primary">{{ item.type }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="result-card resolved-card">
                <div class="result-header">
                    <div>
                        <h2>{{ $gettext('Resolved Timeline') }}</h2>
                        <p>{{ $gettext('The final programming order after schedule priority is applied') }}</p>
                    </div>
                </div>
                <div class="timeline-list">
                    <div v-for="(item, index) in result.resolved_timeline" :key="`${item.start}-${index}`" class="timeline-row">
                        <div class="timeline-time">{{ item.start }} - {{ item.end }}</div>
                        <div>
                            <div class="fw-semibold">{{ item.name }}</div>
                            <div class="small text-body-secondary text-capitalize">{{ item.type }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="result-card">
                <div class="result-header">
                    <div>
                        <h2>{{ $gettext('Rotation Gaps') }}</h2>
                        <p>{{ $gettext('Periods where normal AutoDJ rotation fills the schedule') }}</p>
                    </div>
                </div>
                <div v-if="0 === result.rotation_gaps.length" class="result-empty">
                    {{ $gettext('No uncovered rotation gaps were found.') }}
                </div>
                <div v-else class="timeline-list">
                    <div v-for="(item, index) in result.rotation_gaps" :key="`${item.start}-${index}`" class="timeline-row">
                        <div class="timeline-time">{{ item.start }} - {{ item.end }}</div>
                        <div class="fw-semibold">{{ item.name }}</div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import {onMounted, ref} from "vue";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

type TimelineItem = {
    start: string,
    end: string,
    name: string,
    type: string,
    priority?: number,
};

type SimulationResult = {
    timezone: string,
    schedule_windows: TimelineItem[],
    resolved_timeline: TimelineItem[],
    rotation_gaps: TimelineItem[],
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl("/features/playout-simulator");

const now = new Date();
const date = ref(now.toISOString().slice(0, 10));
const time = ref(now.toTimeString().slice(0, 5));
const duration = ref(60);
const loading = ref(false);
const result = ref<SimulationResult | null>(null);

const simulate = async () => {
    loading.value = true;
    try {
        const response = await axios.get<SimulationResult>(apiUrl.value, {
            params: {
                date: date.value,
                time: time.value,
                duration: duration.value,
            },
        });
        result.value = response.data;
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    void simulate();
});
</script>

<style scoped>
.simulator-page { max-width: 1180px; margin: 0 auto; }
.feature-title h1 { margin: 0; font-size: 2rem; font-weight: 700; color:var(--bs-primary); }
.feature-title p { margin:.35rem 0 0; color:var(--bs-secondary-color); }
.sim-panel { padding:1.25rem; border:1px solid var(--bs-border-color); border-radius:.9rem; background:var(--bs-tertiary-bg); }
.result-card { overflow:hidden; border:1px solid var(--bs-border-color); border-radius:.85rem; background:var(--bs-body-bg); }
.result-header { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:1rem 1.15rem; background:var(--bs-secondary-bg); border-bottom:1px solid var(--bs-border-color); }
.result-header h2 { margin:0; font-size:1rem; font-weight:700; }
.result-header p { margin:.15rem 0 0; color:var(--bs-secondary-color); font-size:.82rem; }
.result-empty { padding:1.5rem; color:var(--bs-secondary-color); text-align:center; }
.timeline-list { padding:.7rem 1rem; }
.timeline-row { display:flex; align-items:center; gap:1rem; padding:.75rem .6rem; border-bottom:1px solid var(--bs-border-color); }
.timeline-row:last-child { border-bottom:0; }
.timeline-time { min-width:150px; font-family:var(--bs-font-monospace); font-size:.85rem; color:var(--bs-secondary-color); }
.resolved-card .timeline-row { background:color-mix(in srgb,var(--bs-success-bg-subtle) 35%,transparent); border-radius:.45rem; margin:.35rem 0; border:1px solid color-mix(in srgb,var(--bs-success) 18%,var(--bs-border-color)); }
</style>
