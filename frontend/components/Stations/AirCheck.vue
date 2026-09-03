<template>
    <div class="aircheck-page">
        <header class="aircheck-hero mb-4">
            <div class="hero-icon"><icon-shield /></div>
            <div class="flex-fill">
                <h1>{{ $gettext('AirCheck System Recovery') }}</h1>
                <p>{{ $gettext('Station recovery with live infrastructure health monitoring') }}</p>
            </div>
            <div class="hero-status" :class="settings.enabled ? 'is-on' : 'is-off'">
                <span class="status-dot" />
                {{ settings.enabled ? $gettext('Monitoring Active') : $gettext('Monitoring Disabled') }}
            </div>
        </header>

        <div class="row g-3 mb-4">
            <div class="col-md-6 col-xl-3">
                <div class="status-card" :class="overallHealthy ? 'status-healthy' : 'status-warning'">
                    <div class="status-icon"><icon-check-circle /></div>
                    <div>
                        <div class="status-label">{{ $gettext('System Health') }}</div>
                        <strong>{{ overallHealthy ? $gettext('Healthy') : $gettext('Attention Needed') }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="status-card status-services">
                    <div class="status-icon"><icon-shield /></div>
                    <div>
                        <div class="status-label">{{ $gettext('Services Online') }}</div>
                        <strong v-if="health">{{ health.running }} / {{ health.total }}</strong>
                        <strong v-else>—</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="status-card status-monitor">
                    <div class="status-icon"><icon-timer /></div>
                    <div>
                        <div class="status-label">{{ $gettext('Check Interval') }}</div>
                        <strong>{{ settings.interval_minutes }} {{ $gettext('minutes') }}</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
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
                        <div class="feature-icon"><icon-settings /></div>
                        <div>
                            <h2>{{ $gettext('Configuration') }}</h2>
                            <p>{{ $gettext('Configure automatic station recovery') }}</p>
                        </div>
                    </div>
                    <div class="feature-card-body">
                        <div class="d-flex justify-content-between gap-3 align-items-start">
                            <div>
                                <div class="fw-semibold">{{ $gettext('Enable AirCheck') }}</div>
                                <div class="small text-body-secondary mt-1">
                                    {{ $gettext('Check station services on the selected interval and recover failed station processes.') }}
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

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="button" class="btn btn-primary" :disabled="busy" @click="save">
                                {{ $gettext('Save Changes') }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="runNow">
                                {{ $gettext('Run Check Now') }}
                            </button>
                        </div>
                    </div>
                </section>

                <section class="feature-card">
                    <div class="feature-card-header">
                        <div class="feature-icon"><icon-info /></div>
                        <div>
                            <h2>{{ $gettext('Recovery Policy') }}</h2>
                            <p>{{ $gettext('Safe station-level automation') }}</p>
                        </div>
                    </div>
                    <div class="feature-card-body">
                        <ul class="feature-checks mb-0">
                            <li>{{ $gettext('Automatically recovers Liquidsoap and the station broadcast frontend') }}</li>
                            <li>{{ $gettext('Monitors shared container services such as MariaDB, Redis, Nginx, PHP and workers') }}</li>
                            <li>{{ $gettext('Shared infrastructure is monitor-only and is never restarted by a station health check') }}</li>
                            <li>{{ $gettext('Recovery problems are written to Custom Feature Diagnostics') }}</li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="col-xl-8">
                <section class="feature-card mb-4">
                    <div class="feature-card-header">
                        <div class="feature-icon"><icon-shield /></div>
                        <div class="flex-fill">
                            <h2>{{ $gettext('Service Health') }}</h2>
                            <p>{{ $gettext('Station recovery targets and shared infrastructure dependencies') }}</p>
                        </div>
                        <div v-if="health" class="health-summary" :class="health.healthy ? 'is-healthy' : 'is-warning'">
                            <span class="health-dot" />
                            {{ health.running }} / {{ health.total }} {{ $gettext('online') }}
                        </div>
                    </div>

                    <div class="service-groups">
                        <div class="service-group">
                            <div class="service-group-heading">
                                <div>
                                    <strong>{{ $gettext('Station Services') }}</strong>
                                    <span>{{ $gettext('Automatic recovery enabled') }}</span>
                                </div>
                                <span class="policy-badge policy-auto">{{ $gettext('Auto Recovery') }}</span>
                            </div>
                            <div class="service-grid">
                                <div v-for="service in stationServices" :key="service.key" class="service-row">
                                    <span class="service-state" :class="serviceStateClass(service)" />
                                    <div class="service-copy">
                                        <strong>{{ service.name }}</strong>
                                        <span>{{ service.description }}</span>
                                    </div>
                                    <span class="service-status" :class="serviceStatusClass(service)">
                                        {{ serviceStatusText(service) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="service-group">
                            <div class="service-group-heading">
                                <div>
                                    <strong>{{ $gettext('System Dependencies') }}</strong>
                                    <span>{{ $gettext('Container-wide services shared by all stations') }}</span>
                                </div>
                                <span class="policy-badge policy-monitor">{{ $gettext('Monitor Only') }}</span>
                            </div>
                            <div class="service-grid service-grid-system">
                                <div v-for="service in systemServices" :key="service.key" class="service-row">
                                    <span class="service-state" :class="serviceStateClass(service)" />
                                    <div class="service-copy">
                                        <strong>{{ service.name }}</strong>
                                        <span>{{ service.description }}</span>
                                    </div>
                                    <span class="service-status" :class="serviceStatusClass(service)">
                                        {{ serviceStatusText(service) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="feature-card intervention-card">
                    <div class="feature-card-header">
                        <div class="feature-icon"><icon-history /></div>
                        <div class="flex-fill">
                            <h2>{{ $gettext('Intervention History') }}</h2>
                            <p>{{ $gettext('Station service restarts and recovery attempts') }}</p>
                        </div>
                        <div v-if="settings.last_check > 0" class="small text-body-secondary text-end">
                            {{ $gettext('Last check') }}<br>
                            <strong class="text-body">{{ formatTimestamp(settings.last_check) }}</strong>
                        </div>
                    </div>

                    <div v-if="0 === settings.interventions.length" class="empty-state">
                        <icon-check-circle class="empty-state-icon" />
                        <h3>{{ $gettext('No Interventions Yet') }}</h3>
                        <p>{{ $gettext('Station services are running smoothly. Recovery actions will appear here if AirCheck needs to intervene.') }}</p>
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
                                <tr v-for="item in settings.interventions" :key="`${item.timestamp}-${item.manual}`">
                                    <td class="text-nowrap">{{ formatTimestamp(item.timestamp) }}</td>
                                    <td>{{ formatServices(item.services) }}</td>
                                    <td>
                                        <span class="badge text-bg-secondary">
                                            {{ item.manual ? $gettext('Manual') : $gettext('Automatic') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="0 === item.failures.length" class="badge text-bg-success">
                                            {{ $gettext('Recovered') }}
                                        </span>
                                        <span v-else class="text-danger small">{{ item.failures.join('; ') }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div v-if="lastResult" class="alert mt-4" :class="lastResult.healthy ? 'alert-success' : 'alert-warning'">
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

type AirCheckIntervention = { timestamp: number, services: string[], failures: string[], manual: boolean };
type AirCheckSettings = { enabled: boolean, interval_minutes: number, last_check: number, interventions: AirCheckIntervention[] };
type AirCheckResult = { checked: boolean, healthy: boolean, restarted: string[], failures: string[], timestamp: number };
type AirCheckService = {
    key: string,
    name: string,
    description: string,
    running: boolean | null,
    configured: boolean,
    scope: 'station' | 'system',
    recovery: 'automatic' | 'monitor_only',
    error?: string | null,
};
type AirCheckHealth = {
    healthy: boolean,
    running: number,
    total: number,
    station_services: AirCheckService[],
    system_services: AirCheckService[],
    timestamp: number,
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const settingsUrl = getStationApiUrl('/features/aircheck');
const runUrl = getStationApiUrl('/features/aircheck/run');
const healthUrl = getStationApiUrl('/features/aircheck/health');

const busy = ref(false);
const lastResult = ref<AirCheckResult | null>(null);
const health = ref<AirCheckHealth | null>(null);
const settings = reactive<AirCheckSettings>({enabled: false, interval_minutes: 10, last_check: 0, interventions: []});

const stationServices = computed(() => health.value?.station_services ?? []);
const systemServices = computed(() => health.value?.system_services ?? []);
const overallHealthy = computed(() => health.value?.healthy ?? true);

const resultMessage = computed(() => {
    if (!lastResult.value) return '';
    if (lastResult.value.healthy) return $gettext('All station recovery targets are running normally.');
    if (0 < lastResult.value.restarted.length) return $gettext('AirCheck restarted one or more station services.');
    return $gettext('The station recovery check completed with one or more errors.');
});

const formatTimestamp = (timestamp: number) => new Date(timestamp * 1000).toLocaleString();
const formatServices = (services: string[]) => {
    if (0 === services.length) return $gettext('No restart required');
    return services.map((service) => {
        if ('backend' === service) return 'Liquidsoap';
        if ('frontend' === service) return $gettext('Broadcast Frontend');
        return service;
    }).join(', ');
};
const serviceStateClass = (service: AirCheckService) => {
    if (!service.configured || null === service.running) return 'is-idle';
    return service.running ? 'is-running' : 'is-down';
};
const serviceStatusClass = (service: AirCheckService) => {
    if (!service.configured || null === service.running) return 'text-bg-secondary';
    return service.running ? 'text-bg-success' : 'text-bg-danger';
};
const serviceStatusText = (service: AirCheckService) => {
    if (!service.configured || null === service.running) return $gettext('Not Configured');
    return service.running ? $gettext('Running') : $gettext('Stopped');
};

const load = async () => {
    const [settingsResponse, healthResponse] = await Promise.all([
        axios.get<AirCheckSettings>(settingsUrl.value),
        axios.get<AirCheckHealth>(healthUrl.value),
    ]);
    Object.assign(settings, settingsResponse.data);
    health.value = healthResponse.data;
};
const save = async () => {
    busy.value = true;
    try {
        await axios.put(settingsUrl.value, {enabled: settings.enabled, interval_minutes: settings.interval_minutes});
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
onMounted(() => void load());
</script>

<style scoped>
.aircheck-page{max-width:1180px;margin:0 auto;color:var(--bs-body-color)}
.aircheck-hero{display:flex;align-items:center;gap:1rem;padding:1.2rem 1.3rem;border-radius:1rem;color:#fff;background:linear-gradient(90deg,#0a6fc2 0%,#2196f3 100%);box-shadow:0 .55rem 1.4rem rgba(16,24,40,.2)}
.aircheck-hero h1{margin:0;color:#fff;font-size:1.55rem;font-weight:750}.aircheck-hero p{margin:.25rem 0 0;color:rgba(255,255,255,.88);font-size:.9rem}
.hero-icon{width:2.85rem;height:2.85rem;display:grid;place-items:center;flex:0 0 auto;border-radius:.75rem;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.22)}
.hero-icon :deep(svg),.status-icon :deep(svg),.feature-icon :deep(svg){width:1.3rem;height:1.3rem}
.hero-status{display:flex;align-items:center;gap:.45rem;padding:.45rem .7rem;border-radius:999px;background:rgba(0,0,0,.18);font-size:.78rem;font-weight:700;white-space:nowrap}
.status-dot,.health-dot,.service-state{display:inline-block;border-radius:50%}.status-dot{width:.55rem;height:.55rem;background:#9aa5b1}.hero-status.is-on .status-dot{background:#51d88a;box-shadow:0 0 0 .2rem rgba(81,216,138,.18)}.hero-status.is-off .status-dot{background:#f6c85f}
.status-card{min-height:88px;display:flex;align-items:center;gap:.85rem;padding:1rem;border:1px solid var(--bs-border-color);border-radius:.85rem;background:color-mix(in srgb,var(--bs-body-bg) 92%,var(--bs-secondary-bg) 8%);box-shadow:0 .2rem .7rem rgba(0,0,0,.06)}
.status-card strong{display:block;margin-top:.12rem;color:var(--bs-body-color);font-size:1rem}.status-label{color:color-mix(in srgb,var(--bs-body-color) 78%,transparent);font-size:.76rem;text-transform:uppercase;letter-spacing:.04em}.status-icon{width:2.5rem;height:2.5rem;display:grid;place-items:center;flex:0 0 auto;border-radius:.65rem;color:#fff}
.status-healthy{border-left:4px solid var(--bs-success)}.status-healthy .status-icon{background:linear-gradient(135deg,#20a86b,#41c889)}.status-warning{border-left:4px solid var(--bs-warning)}.status-warning .status-icon{background:linear-gradient(135deg,#d49116,#f0ad2e)}.status-services{border-left:4px solid #7b61ff}.status-services .status-icon{background:linear-gradient(135deg,#6048d8,#8b73ff)}.status-monitor{border-left:4px solid var(--bs-primary)}.status-monitor .status-icon{background:linear-gradient(135deg,#0a6fc2,#2196f3)}.status-history{border-left:4px solid var(--bs-info)}.status-history .status-icon{background:linear-gradient(135deg,#0288d1,#1e88e5)}
.feature-card{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.9rem;background:color-mix(in srgb,var(--bs-body-bg) 95%,var(--bs-secondary-bg) 5%);box-shadow:0 .3rem 1rem rgba(0,0,0,.07)}
.feature-card-header{display:flex;align-items:center;gap:.85rem;padding:1rem 1.15rem;background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 12%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 5%,var(--bs-body-bg)));border-bottom:1px solid var(--bs-border-color)}
.feature-card-header h2{margin:0;color:var(--bs-body-color);font-size:1rem;font-weight:750}.feature-card-header p{margin:.15rem 0 0;color:color-mix(in srgb,var(--bs-body-color) 76%,transparent);font-size:.82rem}.feature-icon{width:2.45rem;height:2.45rem;display:grid;place-items:center;border-radius:.6rem;background:linear-gradient(135deg,#0a6fc2,#2196f3);color:#fff;flex:0 0 auto}.feature-card-body{padding:1.35rem;color:var(--bs-body-color)}
.feature-checks{list-style:none;padding:0}.feature-checks li{position:relative;padding:.58rem .7rem .58rem 2rem;margin:.5rem 0;border:1px solid var(--bs-border-color);border-radius:.55rem;background:color-mix(in srgb,var(--bs-body-bg) 96%,var(--bs-secondary-bg) 4%);font-size:.83rem}.feature-checks li::before{content:'✓';position:absolute;left:.7rem;color:var(--bs-success);font-weight:800}
.health-summary{display:flex;align-items:center;gap:.4rem;padding:.38rem .6rem;border-radius:999px;background:var(--bs-secondary-bg);font-size:.73rem;font-weight:700;white-space:nowrap}.health-dot{width:.5rem;height:.5rem}.health-summary.is-healthy .health-dot{background:var(--bs-success);box-shadow:0 0 0 .18rem rgba(var(--bs-success-rgb),.13)}.health-summary.is-warning .health-dot{background:var(--bs-danger);box-shadow:0 0 0 .18rem rgba(var(--bs-danger-rgb),.12)}
.service-groups{padding:1rem;display:grid;gap:1rem}.service-group{overflow:hidden;border:1px solid var(--bs-border-color);border-radius:.75rem;background:color-mix(in srgb,var(--bs-body-bg) 97%,var(--bs-secondary-bg) 3%)}.service-group-heading{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.8rem .9rem;background:var(--bs-tertiary-bg);border-bottom:1px solid var(--bs-border-color)}.service-group-heading strong{display:block;font-size:.86rem}.service-group-heading span:not(.policy-badge){display:block;margin-top:.1rem;color:var(--bs-secondary-color);font-size:.72rem}
.policy-badge{flex:0 0 auto;padding:.28rem .5rem;border-radius:999px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.policy-auto{color:var(--bs-success-text-emphasis);background:rgba(var(--bs-success-rgb),.14)}.policy-monitor{color:var(--bs-info-text-emphasis);background:rgba(var(--bs-info-rgb),.14)}
.service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.service-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:.65rem;min-height:4rem;padding:.7rem .85rem;border-bottom:1px solid var(--bs-border-color-translucent)}.service-row:nth-child(odd){border-right:1px solid var(--bs-border-color-translucent)}.service-state{width:.6rem;height:.6rem;background:var(--bs-secondary-color)}.service-state.is-running{background:var(--bs-success);box-shadow:0 0 0 .2rem rgba(var(--bs-success-rgb),.12)}.service-state.is-down{background:var(--bs-danger);box-shadow:0 0 0 .2rem rgba(var(--bs-danger-rgb),.12)}.service-state.is-idle{opacity:.55}.service-copy{min-width:0}.service-copy strong,.service-copy span{display:block}.service-copy strong{font-size:.8rem}.service-copy span{overflow:hidden;margin-top:.12rem;color:var(--bs-secondary-color);font-size:.68rem;text-overflow:ellipsis;white-space:nowrap}.service-status{padding:.25rem .42rem;border-radius:.4rem;font-size:.64rem;font-weight:700;white-space:nowrap}
.empty-state{padding:2.8rem 1.5rem;text-align:center}.empty-state-icon{width:2.8rem;height:2.8rem;color:var(--bs-success)}.empty-state h3{margin:.8rem 0 .35rem;font-size:1rem}.empty-state p{max-width:34rem;margin:0 auto;color:var(--bs-secondary-color);font-size:.82rem}.intervention-card table{font-size:.82rem}
@media(max-width:767.98px){.aircheck-hero{align-items:flex-start;flex-wrap:wrap}.hero-status{margin-left:3.8rem}.service-grid{grid-template-columns:1fr}.service-row:nth-child(odd){border-right:0}.feature-card-header{align-items:flex-start;flex-wrap:wrap}.health-summary{margin-left:3.3rem}}
</style>
