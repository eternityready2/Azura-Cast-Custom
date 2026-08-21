<template>
    <div class="aircheck-page">
        <header class="aircheck-hero mb-4">
            <div class="hero-icon"><icon-shield /></div>
            <div class="flex-fill">
                <h1>{{ $gettext('AirCheck System Recovery') }}</h1>
                <p>{{ $gettext('Automatic health monitoring and service recovery for your station') }}</p>
            </div>
            <div class="hero-status" :class="settings.enabled ? 'is-on' : 'is-off'">
                <span class="status-dot" />
                {{ settings.enabled ? $gettext('Monitoring Active') : $gettext('Monitoring Disabled') }}
            </div>
        </header>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="status-card status-healthy">
                    <div class="status-icon"><icon-check-circle /></div>
                    <div>
                        <div class="status-label">{{ $gettext('Station Health') }}</div>
                        <strong>{{ lastResult && !lastResult.healthy ? $gettext('Attention Needed') : $gettext('Healthy') }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="status-card status-monitor">
                    <div class="status-icon"><icon-timer /></div>
                    <div>
                        <div class="status-label">{{ $gettext('Check Interval') }}</div>
                        <strong>{{ settings.interval_minutes }} {{ $gettext('minutes') }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="status-card status-history">
                    <div class="status-icon"><icon-history /></div>
                    <div>
                        <div class="status-label">{{ $gettext('Interventions') }}</div>
                        <strong>{{ settings.interventions.length }}</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-start">
            <div class="col-xl-4">
                <section class="feature-card mb-4">
                    <div class="feature-card-header">
                        <div class="feature-icon">
                            <icon-settings />
                        </div>
                        <div>
                            <h2>{{ $gettext('Configuration') }}</h2>
                            <p>{{ $gettext('Configure automatic recovery') }}</p>
                        </div>
                    </div>
                    <div class="feature-card-body">
                        <div class="d-flex justify-content-between gap-3 align-items-start">
                            <div>
                                <div class="fw-semibold">{{ $gettext('Enable AirCheck') }}</div>
                                <div class="small text-body-secondary mt-1">
                                    {{ $gettext('Monitor services every 10 minutes and restart them when they stop.') }}
                                </div>
                            </div>
                            <div class="form-check form-switch m-0">
                                <input
                                    id="aircheck_enabled"
                                    v-model="settings.enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                    @change="save"
                                >
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="form-label" for="aircheck_interval">
                                {{ $gettext('Check Interval') }}
                            </label>
                            <div class="input-group">
                                <input
                                    id="aircheck_interval"
                                    v-model.number="settings.interval_minutes"
                                    class="form-control"
                                    type="number"
                                    min="1"
                                    max="60"
                                >
                                <span class="input-group-text">{{ $gettext('minutes') }}</span>
                            </div>
                            <div class="form-text">
                                {{ $gettext('The reference configuration uses a 10 minute interval.') }}
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="busy"
                                @click="save"
                            >
                                {{ $gettext('Save Changes') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                :disabled="busy"
                                @click="runNow"
                            >
                                {{ $gettext('Run Check Now') }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="feature-card">
                    <div class="feature-card-header">
                        <div class="feature-icon">
                            <icon-info />
                        </div>
                        <div>
                            <h2>{{ $gettext('What AirCheck Does') }}</h2>
                            <p>{{ $gettext('Automatic station service recovery') }}</p>
                        </div>
                    </div>
                    <div class="feature-card-body">
                        <ul class="feature-checks mb-0">
                            <li>{{ $gettext('Monitors Liquidsoap and Icecast services') }}</li>
                            <li>{{ $gettext('Checks every 10 minutes automatically') }}</li>
                            <li>{{ $gettext('Restarts failed services immediately') }}</li>
                            <li>{{ $gettext('Logs all interventions for review') }}</li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="col-xl-8">
                <section class="feature-card intervention-card">
                    <div class="feature-card-header">
                        <div class="feature-icon">
                            <icon-history />
                        </div>
                        <div class="flex-fill">
                            <h2>{{ $gettext('Intervention History') }}</h2>
                            <p>{{ $gettext('Service restarts and recovery attempts') }}</p>
                        </div>
                        <div
                            v-if="settings.last_check > 0"
                            class="small text-body-secondary text-end"
                        >
                            {{ $gettext('Last check') }}<br>
                            <strong class="text-body">{{ formatTimestamp(settings.last_check) }}</strong>
                        </div>
                    </div>

                    <div
                        v-if="0 === settings.interventions.length"
                        class="empty-state"
                    >
                        <icon-check-circle class="empty-state-icon" />
                        <h3>{{ $gettext('No Interventions Yet') }}</h3>
                        <p>
                            {{ $gettext('Your station services are running smoothly. Interventions will appear here if AirCheck needs to restart any services.') }}
                        </p>
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ $gettext('Time') }}</th>
                                    <th>{{ $gettext('Services') }}</th>
                                    <th>{{ $gettext('Trigger') }}</th>
                                    <th>{{ $gettext('Result') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in settings.interventions"
                                    :key="`${item.timestamp}-${item.manual}`"
                                >
                                    <td class="text-nowrap">{{ formatTimestamp(item.timestamp) }}</td>
                                    <td>{{ formatServices(item.services) }}</td>
                                    <td>
                                        <span class="badge text-bg-secondary">
                                            {{ item.manual ? $gettext('Manual') : $gettext('Automatic') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            v-if="0 === item.failures.length"
                                            class="badge text-bg-success"
                                        >
                                            {{ $gettext('Recovered') }}
                                        </span>
                                        <span v-else class="text-danger small">
                                            {{ item.failures.join('; ') }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div
            v-if="lastResult"
            class="alert mt-4"
            :class="lastResult.healthy ? 'alert-success' : 'alert-warning'"
        >
            {{ resultMessage }}
        </div>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, reactive, ref} from "vue";
import IconCheckCircle from "~icons/ic/baseline-check-circle";
import IconHistory from "~icons/ic/baseline-history";
import IconInfo from "~icons/ic/baseline-info";
import IconSettings from "~icons/ic/baseline-settings";
import IconShield from "~icons/ic/baseline-shield";
import IconTimer from "~icons/ic/baseline-timer";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

type AirCheckIntervention = {
    timestamp: number,
    services: string[],
    failures: string[],
    manual: boolean,
};

type AirCheckSettings = {
    enabled: boolean,
    interval_minutes: number,
    last_check: number,
    interventions: AirCheckIntervention[],
};

type AirCheckResult = {
    checked: boolean,
    healthy: boolean,
    restarted: string[],
    failures: string[],
    timestamp: number,
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();

const settingsUrl = getStationApiUrl("/features/aircheck");
const runUrl = getStationApiUrl("/features/aircheck/run");

const busy = ref(false);
const lastResult = ref<AirCheckResult | null>(null);
const settings = reactive<AirCheckSettings>({
    enabled: false,
    interval_minutes: 10,
    last_check: 0,
    interventions: [],
});

const resultMessage = computed(() => {
    if (!lastResult.value) {
        return "";
    }

    if (lastResult.value.healthy) {
        return $gettext("All monitored services are running normally.");
    }

    if (0 < lastResult.value.restarted.length) {
        return $gettext("AirCheck restarted one or more station services.");
    }

    return $gettext("The service check completed with one or more errors.");
});

const formatTimestamp = (timestamp: number) => new Date(timestamp * 1000).toLocaleString();

const formatServices = (services: string[]) => {
    if (0 === services.length) {
        return $gettext("No restart required");
    }

    return services.map((service) => "backend" === service ? "Liquidsoap" : "Icecast").join(", ");
};

const load = async () => {
    const response = await axios.get<AirCheckSettings>(settingsUrl.value);
    Object.assign(settings, response.data);
};

const save = async () => {
    busy.value = true;
    try {
        await axios.put(settingsUrl.value, {
            enabled: settings.enabled,
            interval_minutes: settings.interval_minutes,
        });
        await load();
    } finally {
        busy.value = false;
    }
};

const runNow = async () => {
    busy.value = true;
    try {
        const response = await axios.post<AirCheckResult>(runUrl.value);
        lastResult.value = response.data;
        await load();
    } finally {
        busy.value = false;
    }
};

onMounted(() => {
    void load();
});
</script>

<style scoped>
.aircheck-page { max-width:1180px; margin:0 auto; color:var(--bs-body-color); }
.aircheck-hero { display:flex; align-items:center; gap:1rem; padding:1.2rem 1.3rem; border-radius:1rem; color:#fff; background:linear-gradient(90deg,#0a6fc2 0%,#2196f3 100%); box-shadow:0 .55rem 1.4rem rgba(16,24,40,.2); }
.aircheck-hero h1 { margin:0; color:#fff; font-size:1.55rem; font-weight:750; }
.aircheck-hero p { margin:.25rem 0 0; color:rgba(255,255,255,.88); font-size:.9rem; }
.hero-icon { width:2.85rem; height:2.85rem; display:grid; place-items:center; flex:0 0 auto; border-radius:.75rem; background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.22); }
.hero-icon :deep(svg) { width:1.4rem; height:1.4rem; }
.hero-status { display:flex; align-items:center; gap:.45rem; padding:.45rem .7rem; border-radius:999px; background:rgba(0,0,0,.18); font-size:.78rem; font-weight:700; white-space:nowrap; }
.status-dot { width:.55rem; height:.55rem; border-radius:50%; background:#9aa5b1; }
.hero-status.is-on .status-dot { background:#51d88a; box-shadow:0 0 0 .2rem rgba(81,216,138,.18); }
.hero-status.is-off .status-dot { background:#f6c85f; }
.status-card { min-height:88px; display:flex; align-items:center; gap:.85rem; padding:1rem; border:1px solid var(--bs-border-color); border-radius:.85rem; background:color-mix(in srgb,var(--bs-body-bg) 92%,var(--bs-secondary-bg) 8%); box-shadow:0 .2rem .7rem rgba(0,0,0,.06); }
.status-card strong { display:block; margin-top:.12rem; color:var(--bs-body-color); font-size:1rem; }
.status-label { color:color-mix(in srgb,var(--bs-body-color) 78%,transparent); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; }
.status-icon { width:2.5rem; height:2.5rem; display:grid; place-items:center; flex:0 0 auto; border-radius:.65rem; color:#fff; }
.status-icon :deep(svg) { width:1.25rem; height:1.25rem; }
.status-healthy { border-left:4px solid var(--bs-success); }
.status-healthy .status-icon { background:linear-gradient(135deg,#20a86b,#41c889); }
.status-monitor { border-left:4px solid var(--bs-primary); }
.status-monitor .status-icon { background:linear-gradient(135deg,#0a6fc2,#2196f3); }
.status-history { border-left:4px solid var(--bs-info); }
.status-history .status-icon { background:linear-gradient(135deg,#0288d1,#1e88e5); }
.feature-card { overflow:hidden; border:1px solid var(--bs-border-color); border-radius:.9rem; background:color-mix(in srgb,var(--bs-body-bg) 95%,var(--bs-secondary-bg) 5%); box-shadow:0 .3rem 1rem rgba(0,0,0,.07); }
.feature-card-header { display:flex; align-items:center; gap:.85rem; padding:1rem 1.15rem; background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 12%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 5%,var(--bs-body-bg))); border-bottom:1px solid var(--bs-border-color); }
.feature-card-header h2 { margin:0; color:var(--bs-body-color); font-size:1rem; font-weight:750; }
.feature-card-header p { margin:.15rem 0 0; color:color-mix(in srgb,var(--bs-body-color) 76%,transparent); font-size:.82rem; }
.feature-icon { width:2.45rem; height:2.45rem; display:grid; place-items:center; border-radius:.6rem; background:linear-gradient(135deg,#0a6fc2,#2196f3); color:#fff; flex:0 0 auto; }
.feature-icon :deep(svg) { width:1.25rem; height:1.25rem; }
.feature-card-body { padding:1.35rem; color:var(--bs-body-color); }
.feature-card-body .form-text,.feature-card-body .text-body-secondary { color:color-mix(in srgb,var(--bs-body-color) 76%,transparent)!important; }
.feature-checks { list-style:none; padding:0; }
.feature-checks li { position:relative; padding:.58rem .7rem .58rem 2rem; margin:.5rem 0; border:1px solid var(--bs-border-color); border-radius:.55rem; background:color-mix(in srgb,var(--bs-success-bg-subtle) 16%,var(--bs-body-bg)); color:var(--bs-body-color); }
.feature-checks li::before { content:""; position:absolute; left:.72rem; top:.9rem; width:.5rem; height:.25rem; border-left:2px solid var(--bs-success); border-bottom:2px solid var(--bs-success); transform:rotate(-45deg); }
.intervention-card { min-height:360px; }
.intervention-card .table { --bs-table-color:var(--bs-body-color); --bs-table-bg:transparent; }
.intervention-card .table th { color:color-mix(in srgb,var(--bs-body-color) 86%,transparent); background:color-mix(in srgb,var(--bs-secondary-bg) 65%,var(--bs-body-bg)); }
.intervention-card .table td { color:var(--bs-body-color); border-color:var(--bs-border-color); }
.empty-state { min-height:285px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:2rem; }
.empty-state-icon { width:3.4rem; height:3.4rem; color:var(--bs-success); margin-bottom:.8rem; filter:drop-shadow(0 .25rem .5rem rgba(25,135,84,.18)); }
.empty-state h3 { color:var(--bs-body-color); font-size:1.08rem; margin-bottom:.45rem; }
.empty-state p { max-width:560px; margin:0; color:color-mix(in srgb,var(--bs-body-color) 78%,transparent); }
@media (max-width:767px){ .aircheck-hero{align-items:flex-start;flex-direction:column;} .hero-status{align-self:flex-start;} }
</style>
