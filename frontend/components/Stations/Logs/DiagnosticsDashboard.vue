<template>
    <section
        class="diagnostics-console mb-4"
        role="region"
        aria-labelledby="station_diagnostics_heading"
    >
        <div class="diagnostics-hero">
            <div class="diagnostics-heading">
                <div class="diagnostics-mark" aria-hidden="true">
                    <span />
                    <span />
                    <span />
                </div>
                <div>
                    <div class="diagnostics-eyebrow">
                        {{ $gettext('Broadcast Operations Center') }}
                    </div>
                    <h2 id="station_diagnostics_heading">
                        {{ $gettext('Station Diagnostics') }}
                    </h2>
                    <p>
                        {{ $gettext('Live health intelligence for playout, scheduling, imports, remote sources and station services.') }}
                    </p>
                </div>
            </div>

            <div class="diagnostics-actions">
                <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    :disabled="isFetching"
                    @click="refetch()"
                >
                    <icon-ic-refresh />
                    <span>{{ isFetching ? $gettext('Refreshing…') : $gettext('Refresh') }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    @click="viewDetailedDiagnostics"
                >
                    <icon-ic-visibility />
                    <span>{{ $gettext('Detailed Diagnostics') }}</span>
                </button>
                <a
                    class="btn btn-sm btn-outline-light diagnostics-download"
                    :href="diagnosticsDownloadUrl"
                >
                    <icon-ic-download />
                    <span>{{ $gettext('Download') }}</span>
                </a>
            </div>
        </div>

        <loading :loading="isLoading" lazy>
            <div
                v-if="isError"
                class="diagnostics-load-error"
            >
                <strong>{{ $gettext('Diagnostics could not be loaded.') }}</strong>
                <span>{{ $gettext('The raw station logs below are still available. Refresh to try the live health scan again.') }}</span>
            </div>

            <template v-else-if="summary">
                <div class="diagnostics-command-row">
                    <div
                        class="health-score-card"
                        :class="statusClass(summary.overall_status)"
                    >
                        <div
                            class="health-orbit"
                            :style="{'--health-angle': `${summary.health_score * 3.6}deg`}"
                        >
                            <div class="health-orbit-inner">
                                <strong>{{ summary.health_score }}</strong>
                                <span>/ 100</span>
                            </div>
                        </div>
                        <div class="health-score-copy">
                            <span class="health-score-label">{{ $gettext('Station Health') }}</span>
                            <strong>{{ statusLabel(summary.overall_status) }}</strong>
                            <small>
                                {{ $gettext('Live services + configuration + last 24h execution signals') }}
                            </small>
                        </div>
                    </div>

                    <div class="diagnostics-kpis">
                        <div class="diagnostic-kpi">
                            <span>{{ $gettext('Active Issues') }}</span>
                            <strong>{{ summary.counts.active_issues }}</strong>
                            <small>{{ $gettext('Need attention') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--danger">
                            <span>{{ $gettext('Critical') }}</span>
                            <strong>{{ summary.counts.critical }}</strong>
                            <small>{{ $gettext('Feature areas') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--warning">
                            <span>{{ $gettext('Warnings') }}</span>
                            <strong>{{ summary.counts.warning }}</strong>
                            <small>{{ $gettext('Feature areas') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--success">
                            <span>{{ $gettext('Services Online') }}</span>
                            <strong>{{ summary.counts.services_running }}/{{ summary.counts.services_total }}</strong>
                            <small>{{ $gettext('Checked live') }}</small>
                        </div>
                        <div class="diagnostic-kpi">
                            <span>{{ $gettext('Signals') }}</span>
                            <strong>{{ summary.counts.recent_events }}</strong>
                            <small>{{ $gettext('Last 24 hours') }}</small>
                        </div>
                    </div>
                </div>

                <div class="diagnostics-intelligence-grid">
                    <article class="diagnostics-panel diagnostics-panel--activity">
                        <div class="panel-heading">
                            <div>
                                <span class="panel-kicker">{{ $gettext('Execution Timeline') }}</span>
                                <h3>{{ $gettext('Diagnostic Activity · 24 Hours') }}</h3>
                            </div>
                            <span class="live-indicator">
                                <span />
                                {{ $gettext('Live snapshot') }}
                            </span>
                        </div>
                        <diagnostics-activity-chart :points="summary.timeline" />
                    </article>

                    <article class="diagnostics-panel diagnostics-panel--distribution">
                        <div class="panel-heading">
                            <div>
                                <span class="panel-kicker">{{ $gettext('System Readiness') }}</span>
                                <h3>{{ $gettext('Feature Health') }}</h3>
                            </div>
                        </div>
                        <div class="distribution-chart-wrap">
                            <doughnut-chart
                                :data="distributionDatasets"
                                :labels="distributionLabels"
                                :aspect-ratio="1.25"
                            />
                            <div class="distribution-summary">
                                <div>
                                    <span class="status-dot status-dot--healthy" />
                                    <strong>{{ summary.distribution.healthy }}</strong>
                                    <span>{{ $gettext('Healthy') }}</span>
                                </div>
                                <div>
                                    <span class="status-dot status-dot--warning" />
                                    <strong>{{ summary.distribution.warning }}</strong>
                                    <span>{{ $gettext('Warning') }}</span>
                                </div>
                                <div>
                                    <span class="status-dot status-dot--critical" />
                                    <strong>{{ summary.distribution.critical }}</strong>
                                    <span>{{ $gettext('Critical') }}</span>
                                </div>
                                <div>
                                    <span class="status-dot status-dot--inactive" />
                                    <strong>{{ summary.distribution.inactive }}</strong>
                                    <span>{{ $gettext('Inactive') }}</span>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="diagnostics-panel diagnostics-panel--issues">
                        <div class="panel-heading">
                            <div>
                                <span class="panel-kicker">{{ $gettext('Operator Priority') }}</span>
                                <h3>{{ $gettext('Needs Attention Now') }}</h3>
                            </div>
                            <span class="issue-count">{{ summary.recent_issues.length }}</span>
                        </div>

                        <div
                            v-if="summary.recent_issues.length === 0"
                            class="issues-clear"
                        >
                            <span class="issues-clear-mark">✓</span>
                            <strong>{{ $gettext('No active diagnostic issues') }}</strong>
                            <small>{{ $gettext('No recent failures or configuration blockers were detected.') }}</small>
                        </div>

                        <div v-else class="issues-feed">
                            <div
                                v-for="(issue, index) in summary.recent_issues.slice(0, 7)"
                                :key="`${issue.feature_key}-${issue.timestamp}-${index}`"
                                class="issue-row"
                                :class="`issue-row--${issue.severity}`"
                            >
                                <span class="issue-rail" />
                                <div class="issue-copy">
                                    <div class="issue-meta">
                                        <strong>{{ issue.feature }}</strong>
                                        <span>{{ formatIssueTime(issue.timestamp) }}</span>
                                    </div>
                                    <div class="issue-title">{{ issue.title }}</div>
                                    <div v-if="issue.detail" class="issue-detail">{{ issue.detail }}</div>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="feature-section-heading">
                    <div>
                        <span class="panel-kicker">{{ $gettext('Feature-Level Diagnostics') }}</span>
                        <h3>{{ $gettext('What Is Working — and What Is Not') }}</h3>
                    </div>
                    <small>
                        {{ $gettext('Each module combines live state, configuration checks and runtime execution signals where available.') }}
                    </small>
                </div>

                <div class="feature-health-grid">
                    <article
                        v-for="feature in summary.features"
                        :key="feature.key"
                        class="feature-health-card"
                        :class="statusClass(feature.status)"
                    >
                        <span class="feature-status-rail" />
                        <div class="feature-card-topline">
                            <span class="feature-name">{{ feature.label }}</span>
                            <span class="feature-status-pill">{{ statusLabel(feature.status) }}</span>
                        </div>
                        <strong class="feature-metric">{{ feature.metric }}</strong>
                        <div class="feature-headline">{{ feature.headline }}</div>
                        <p>{{ feature.detail }}</p>
                        <div class="feature-card-footer">
                            <span>{{ basisLabel(feature.basis) }}</span>
                            <span v-if="feature.issues > 0" class="feature-issue-count">
                                {{ feature.issues }} {{ feature.issues === 1 ? $gettext('issue') : $gettext('issues') }}
                            </span>
                            <span v-else class="feature-clear">{{ $gettext('Clear') }}</span>
                        </div>
                    </article>
                </div>

                <div class="service-matrix">
                    <div class="service-matrix-heading">
                        <div>
                            <span class="panel-kicker">{{ $gettext('Runtime Services') }}</span>
                            <h3>{{ $gettext('Broadcast & Infrastructure') }}</h3>
                        </div>
                        <span>{{ $gettext('Checked when this page loads') }}</span>
                    </div>
                    <div class="service-grid">
                        <div
                            v-for="service in summary.services"
                            :key="`${service.scope}-${service.key}`"
                            class="service-chip"
                            :class="statusClass(service.status)"
                            :title="service.description"
                        >
                            <span class="service-light" />
                            <div>
                                <strong>{{ service.name }}</strong>
                                <small>{{ service.scope === 'station' ? $gettext('Station') : $gettext('System') }}</small>
                            </div>
                            <span>{{ statusLabel(service.status) }}</span>
                        </div>
                    </div>
                </div>

                <div class="diagnostics-footer-note">
                    <span>{{ $gettext('Last analyzed') }}: {{ formatGenerated(summary.generated_at) }}</span>
                    <span>{{ $gettext('Detailed raw service logs remain available below this dashboard.') }}</span>
                </div>
            </template>
        </loading>

        <streaming-log-modal ref="$diagnosticsModal" />
    </section>
</template>

<script setup lang="ts">
import {computed, useTemplateRef} from "vue";
import {useQuery} from "@tanstack/vue-query";
import DoughnutChart from "~/components/Common/Charts/DoughnutChart.vue";
import Loading from "~/components/Common/Loading.vue";
import StreamingLogModal from "~/components/Common/StreamingLogModal.vue";
import DiagnosticsActivityChart from "~/components/Stations/Logs/DiagnosticsActivityChart.vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useTranslate} from "~/vendor/gettext";
import IconIcDownload from "~icons/ic/baseline-download";
import IconIcRefresh from "~icons/ic/baseline-refresh";
import IconIcVisibility from "~icons/ic/baseline-visibility";

interface DiagnosticsTimelinePoint {
    timestamp: number,
    critical: number,
    warning: number,
    info: number,
}

interface DiagnosticsFeature {
    key: string,
    label: string,
    status: string,
    headline: string,
    detail: string,
    metric: string,
    basis: string,
    issues: number,
}

interface DiagnosticsService {
    key: string,
    name: string,
    description: string,
    scope: string,
    recovery: string,
    status: string,
    running: boolean | null,
}

interface DiagnosticsIssue {
    severity: 'critical' | 'warning',
    feature_key: string,
    feature: string,
    title: string,
    detail: string,
    timestamp: number,
    source: string,
}

interface DiagnosticsSummary {
    generated_at: number,
    window_hours: number,
    overall_status: string,
    health_score: number,
    counts: {
        critical: number,
        warning: number,
        healthy: number,
        inactive: number,
        recent_events: number,
        active_issues: number,
        services_running: number,
        services_total: number,
    },
    distribution: {
        healthy: number,
        warning: number,
        critical: number,
        inactive: number,
    },
    timeline: DiagnosticsTimelinePoint[],
    features: DiagnosticsFeature[],
    services: DiagnosticsService[],
    recent_issues: DiagnosticsIssue[],
}

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();

const summaryUrl = getStationApiUrl('/diagnostics/summary');
const diagnosticsUrl = getStationApiUrl('/diagnostics');
const diagnosticsDownloadUrl = getStationApiUrl('/diagnostics/download');

const {
    data: summary,
    isLoading,
    isFetching,
    isError,
    refetch,
} = useQuery<DiagnosticsSummary>({
    queryKey: queryKeyWithStation([QueryKeys.StationDiagnostics]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<DiagnosticsSummary>(summaryUrl.value, {signal});
        return data;
    },
    refetchInterval: 60_000,
});

const $diagnosticsModal = useTemplateRef('$diagnosticsModal');

const viewDetailedDiagnostics = () => {
    $diagnosticsModal.value?.show(diagnosticsUrl.value, true);
};

const distributionLabels = computed(() => [
    $gettext('Healthy'),
    $gettext('Warning'),
    $gettext('Critical'),
    $gettext('Inactive'),
]);

const distributionDatasets = computed(() => [{
    data: summary.value
        ? [
            summary.value.distribution.healthy,
            summary.value.distribution.warning,
            summary.value.distribution.critical,
            summary.value.distribution.inactive,
        ]
        : [0, 0, 0, 0],
    backgroundColor: [
        'rgba(25, 135, 84, 0.86)',
        'rgba(255, 193, 7, 0.86)',
        'rgba(220, 53, 69, 0.88)',
        'rgba(108, 117, 125, 0.52)',
    ],
    borderWidth: 0,
    hoverOffset: 4,
}] as any[]);

const statusClass = (status: string): string => `diag-status--${status}`;

const statusLabel = (status: string): string => {
    switch (status) {
        case 'healthy':
            return $gettext('Healthy');
        case 'warning':
            return $gettext('Warning');
        case 'critical':
            return $gettext('Critical');
        case 'inactive':
            return $gettext('Inactive');
        default:
            return $gettext('Unknown');
    }
};

const basisLabel = (basis: string): string => {
    if (basis.includes('live')) {
        return $gettext('Live runtime check');
    }
    if (basis.includes('logs') || basis.includes('events')) {
        return $gettext('State + execution signals');
    }
    return $gettext('Configuration check');
};

const formatGenerated = (timestamp: number): string => new Date(timestamp * 1000).toLocaleString();

const formatIssueTime = (timestamp: number): string => {
    const then = new Date(timestamp * 1000);
    const now = Date.now();
    const elapsedMinutes = Math.max(0, Math.floor((now - then.getTime()) / 60_000));

    if (elapsedMinutes < 1) {
        return $gettext('now');
    }
    if (elapsedMinutes < 60) {
        return `${elapsedMinutes}m`;
    }
    if (elapsedMinutes < 1440) {
        return `${Math.floor(elapsedMinutes / 60)}h`;
    }

    return then.toLocaleDateString();
};
</script>

<style lang="scss" scoped>
.diagnostics-console {
    --diag-status: var(--bs-success);
    overflow: hidden;
    border-radius: 1.35rem;
    background:
        radial-gradient(circle at 8% -10%, rgba(var(--bs-primary-rgb), 0.22), transparent 34%),
        radial-gradient(circle at 98% 8%, rgba(var(--bs-info-rgb), 0.11), transparent 28%),
        linear-gradient(145deg, color-mix(in srgb, var(--bs-secondary-bg) 94%, var(--bs-primary) 6%), var(--bs-secondary-bg));
    box-shadow:
        0 1.25rem 3.25rem rgba(0, 0, 0, 0.17),
        inset 0 1px 0 rgba(255, 255, 255, 0.045);
}

.diagnostics-hero {
    display: flex;
    min-height: 6.35rem;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
    padding: 1.15rem 1.25rem 1.1rem;
    background:
        linear-gradient(105deg, rgba(var(--bs-primary-rgb), 0.3), rgba(var(--bs-primary-rgb), 0.075) 52%, transparent 82%);
}

.diagnostics-heading {
    display: flex;
    min-width: 0;
    align-items: center;
    gap: 0.95rem;
}

.diagnostics-mark {
    display: flex;
    width: 3.15rem;
    height: 3.15rem;
    flex: 0 0 auto;
    align-items: flex-end;
    justify-content: center;
    gap: 0.24rem;
    padding: 0.72rem;
    border-radius: 0.9rem;
    background: color-mix(in srgb, var(--bs-primary) 22%, var(--bs-body-bg));
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.09),
        0 0.65rem 1.5rem rgba(var(--bs-primary-rgb), 0.18);
}

.diagnostics-mark span {
    width: 0.3rem;
    border-radius: 999px;
    background: var(--bs-primary);
    box-shadow: 0 0 0.75rem rgba(var(--bs-primary-rgb), 0.5);
}

.diagnostics-mark span:nth-child(1) { height: 52%; }
.diagnostics-mark span:nth-child(2) { height: 100%; }
.diagnostics-mark span:nth-child(3) { height: 72%; }

.diagnostics-eyebrow,
.panel-kicker {
    color: var(--bs-primary-text-emphasis);
    font-size: 0.67rem;
    font-weight: 800;
    letter-spacing: 0.105em;
    text-transform: uppercase;
}

.diagnostics-heading h2 {
    margin: 0.12rem 0 0.2rem;
    font-size: clamp(1.25rem, 2vw, 1.7rem);
    font-weight: 760;
    letter-spacing: -0.025em;
}

.diagnostics-heading p {
    max-width: 51rem;
    margin: 0;
    color: var(--bs-secondary-color);
    font-size: 0.83rem;
}

.diagnostics-actions {
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.45rem;
}

.diagnostics-actions .btn {
    min-height: 2.2rem;
    border-radius: 0.62rem;
    font-weight: 650;
    box-shadow: 0 0.25rem 0.65rem rgba(0, 0, 0, 0.12);
}

.diagnostics-download {
    border-color: color-mix(in srgb, var(--bs-body-color) 22%, transparent);
    color: var(--bs-body-color);
}

.diagnostics-command-row {
    display: grid;
    grid-template-columns: minmax(16rem, 0.82fr) minmax(0, 2.35fr);
    gap: 0.7rem;
    padding: 0 0.85rem 0.8rem;
}

.health-score-card,
.diagnostic-kpi,
.diagnostics-panel,
.feature-health-card,
.service-matrix {
    background: color-mix(in srgb, var(--bs-body-bg) 93%, var(--bs-primary) 7%);
    box-shadow:
        0 0.38rem 1rem rgba(0, 0, 0, 0.085),
        inset 0 1px 0 rgba(255, 255, 255, 0.035);
}

.health-score-card {
    display: flex;
    min-height: 8.1rem;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-radius: 1rem;
}

.health-orbit {
    display: grid;
    width: 6.1rem;
    height: 6.1rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 50%;
    background: conic-gradient(var(--diag-status) var(--health-angle), color-mix(in srgb, var(--bs-secondary-bg) 78%, transparent) 0);
    box-shadow: 0 0 1.35rem color-mix(in srgb, var(--diag-status) 20%, transparent);
}

.health-orbit-inner {
    display: grid;
    width: 4.85rem;
    height: 4.85rem;
    place-items: center;
    align-content: center;
    border-radius: 50%;
    background: var(--bs-body-bg);
    box-shadow: inset 0 0.4rem 1rem rgba(0, 0, 0, 0.12);
}

.health-orbit-inner strong {
    color: var(--diag-status);
    font-size: 1.72rem;
    line-height: 1;
}

.health-orbit-inner span {
    margin-top: 0.15rem;
    color: var(--bs-secondary-color);
    font-size: 0.64rem;
    font-weight: 700;
}

.health-score-copy {
    display: grid;
    gap: 0.14rem;
}

.health-score-label {
    color: var(--bs-secondary-color);
    font-size: 0.68rem;
    font-weight: 750;
    letter-spacing: 0.065em;
    text-transform: uppercase;
}

.health-score-copy > strong {
    color: var(--diag-status);
    font-size: 1.08rem;
}

.health-score-copy small {
    color: var(--bs-secondary-color);
    line-height: 1.35;
}

.diagnostics-kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.7rem;
}

.diagnostic-kpi {
    position: relative;
    display: grid;
    min-width: 0;
    align-content: center;
    gap: 0.12rem;
    padding: 0.8rem 0.75rem;
    border-radius: 1rem;
}

.diagnostic-kpi::after {
    position: absolute;
    right: 0.72rem;
    bottom: 0.7rem;
    width: 1.9rem;
    height: 0.2rem;
    border-radius: 999px;
    background: rgba(var(--bs-primary-rgb), 0.55);
    content: '';
}

.diagnostic-kpi--danger::after { background: var(--bs-danger); }
.diagnostic-kpi--warning::after { background: var(--bs-warning); }
.diagnostic-kpi--success::after { background: var(--bs-success); }

.diagnostic-kpi span {
    overflow: hidden;
    color: var(--bs-secondary-color);
    font-size: 0.68rem;
    font-weight: 750;
    letter-spacing: 0.045em;
    text-overflow: ellipsis;
    text-transform: uppercase;
    white-space: nowrap;
}

.diagnostic-kpi strong {
    font-size: clamp(1.35rem, 2.4vw, 2rem);
    letter-spacing: -0.04em;
}

.diagnostic-kpi small {
    color: var(--bs-secondary-color);
    font-size: 0.67rem;
}

.diagnostics-intelligence-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(15rem, 0.72fr) minmax(18rem, 1fr);
    gap: 0.7rem;
    padding: 0 0.85rem 0.85rem;
}

.diagnostics-panel {
    min-width: 0;
    border-radius: 1rem;
    padding: 0.9rem;
}

.panel-heading,
.feature-section-heading,
.service-matrix-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.panel-heading {
    margin-bottom: 0.65rem;
}

.panel-heading h3,
.feature-section-heading h3,
.service-matrix-heading h3 {
    margin: 0.12rem 0 0;
    font-size: 0.94rem;
    font-weight: 750;
    letter-spacing: -0.01em;
}

.live-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--bs-secondary-color);
    font-size: 0.65rem;
    font-weight: 650;
    white-space: nowrap;
}

.live-indicator > span {
    width: 0.48rem;
    height: 0.48rem;
    border-radius: 50%;
    background: var(--bs-success);
    box-shadow: 0 0 0.55rem rgba(var(--bs-success-rgb), 0.6);
}

.distribution-chart-wrap {
    display: grid;
    align-items: center;
    gap: 0.65rem;
}

.distribution-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.35rem;
}

.distribution-summary > div {
    display: grid;
    grid-template-columns: auto auto 1fr;
    align-items: center;
    gap: 0.3rem;
    color: var(--bs-secondary-color);
    font-size: 0.66rem;
}

.distribution-summary strong {
    color: var(--bs-body-color);
}

.status-dot,
.service-light {
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
}

.status-dot--healthy { background: var(--bs-success); }
.status-dot--warning { background: var(--bs-warning); }
.status-dot--critical { background: var(--bs-danger); }
.status-dot--inactive { background: var(--bs-secondary); }

.issue-count {
    display: inline-grid;
    min-width: 1.7rem;
    height: 1.7rem;
    place-items: center;
    padding: 0 0.4rem;
    border-radius: 999px;
    background: rgba(var(--bs-danger-rgb), 0.13);
    color: var(--bs-danger-text-emphasis);
    font-size: 0.7rem;
    font-weight: 800;
}

.issues-feed {
    display: grid;
    max-height: 21rem;
    gap: 0.42rem;
    overflow: auto;
    padding-right: 0.15rem;
}

.issue-row {
    --issue-color: var(--bs-warning);
    position: relative;
    display: grid;
    grid-template-columns: 0.25rem minmax(0, 1fr);
    gap: 0.6rem;
    padding: 0.58rem 0.62rem 0.58rem 0;
    border-radius: 0.68rem;
    background: color-mix(in srgb, var(--issue-color) 5%, var(--bs-tertiary-bg));
}

.issue-row--critical { --issue-color: var(--bs-danger); }
.issue-row--warning { --issue-color: var(--bs-warning); }

.issue-rail {
    border-radius: 0 999px 999px 0;
    background: var(--issue-color);
    box-shadow: 0 0 0.8rem color-mix(in srgb, var(--issue-color) 30%, transparent);
}

.issue-copy {
    min-width: 0;
}

.issue-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    color: var(--bs-secondary-color);
    font-size: 0.61rem;
}

.issue-meta strong {
    color: var(--issue-color);
    font-size: 0.64rem;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.issue-title {
    margin-top: 0.13rem;
    font-size: 0.75rem;
    font-weight: 720;
    line-height: 1.3;
}

.issue-detail {
    display: -webkit-box;
    margin-top: 0.12rem;
    overflow: hidden;
    color: var(--bs-secondary-color);
    font-size: 0.66rem;
    line-height: 1.33;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.issues-clear {
    display: grid;
    min-height: 14rem;
    place-items: center;
    align-content: center;
    gap: 0.28rem;
    text-align: center;
}

.issues-clear-mark {
    display: grid;
    width: 2.7rem;
    height: 2.7rem;
    place-items: center;
    border-radius: 50%;
    background: rgba(var(--bs-success-rgb), 0.14);
    color: var(--bs-success);
    font-size: 1.25rem;
    font-weight: 800;
}

.issues-clear small {
    max-width: 16rem;
    color: var(--bs-secondary-color);
}

.feature-section-heading {
    align-items: end;
    padding: 0.2rem 1rem 0.65rem;
}

.feature-section-heading small {
    max-width: 33rem;
    color: var(--bs-secondary-color);
    text-align: right;
}

.feature-health-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.7rem;
    padding: 0 0.85rem 0.85rem;
}

.feature-health-card {
    --diag-status: var(--bs-success);
    position: relative;
    min-width: 0;
    overflow: hidden;
    padding: 0.82rem 0.85rem 0.72rem 1rem;
    border-radius: 0.95rem;
}

.feature-status-rail {
    position: absolute;
    inset: 0 auto 0 0;
    width: 0.26rem;
    background: var(--diag-status);
    box-shadow: 0 0 1rem color-mix(in srgb, var(--diag-status) 35%, transparent);
}

.feature-card-topline,
.feature-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.55rem;
}

.feature-name {
    overflow: hidden;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.065em;
    text-overflow: ellipsis;
    text-transform: uppercase;
    white-space: nowrap;
}

.feature-status-pill {
    flex: 0 0 auto;
    padding: 0.2rem 0.42rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--diag-status) 13%, transparent);
    color: var(--diag-status);
    font-size: 0.58rem;
    font-weight: 800;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.feature-metric {
    display: block;
    margin-top: 0.7rem;
    font-size: 1.28rem;
    letter-spacing: -0.035em;
}

.feature-headline {
    margin-top: 0.12rem;
    font-size: 0.73rem;
    font-weight: 700;
}

.feature-health-card p {
    min-height: 2.55rem;
    margin: 0.2rem 0 0.62rem;
    color: var(--bs-secondary-color);
    font-size: 0.66rem;
    line-height: 1.35;
}

.feature-card-footer {
    padding-top: 0.55rem;
    border-top: 1px solid var(--bs-border-color-translucent);
    color: var(--bs-secondary-color);
    font-size: 0.6rem;
}

.feature-issue-count {
    color: var(--diag-status);
    font-weight: 750;
}

.feature-clear {
    color: var(--bs-success);
    font-weight: 750;
}

.service-matrix {
    margin: 0 0.85rem 0.85rem;
    padding: 0.85rem;
    border-radius: 1rem;
}

.service-matrix-heading {
    align-items: end;
    margin-bottom: 0.65rem;
}

.service-matrix-heading > span {
    color: var(--bs-secondary-color);
    font-size: 0.65rem;
}

.service-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.45rem;
}

.service-chip {
    --diag-status: var(--bs-success);
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.48rem;
    min-width: 0;
    padding: 0.52rem 0.58rem;
    border-radius: 0.65rem;
    background: var(--bs-tertiary-bg);
}

.service-light {
    background: var(--diag-status);
    box-shadow: 0 0 0.55rem color-mix(in srgb, var(--diag-status) 45%, transparent);
}

.service-chip div {
    display: grid;
    min-width: 0;
}

.service-chip strong {
    overflow: hidden;
    font-size: 0.68rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.service-chip small,
.service-chip > span:last-child {
    color: var(--bs-secondary-color);
    font-size: 0.57rem;
}

.diagnostics-footer-note {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.05rem 1rem 0.9rem;
    color: var(--bs-secondary-color);
    font-size: 0.62rem;
}

.diagnostics-load-error {
    display: grid;
    gap: 0.2rem;
    margin: 0 0.85rem 0.85rem;
    padding: 0.9rem 1rem;
    border-radius: 0.8rem;
    background: rgba(var(--bs-danger-rgb), 0.1);
    color: var(--bs-danger-text-emphasis);
}

.diag-status--healthy { --diag-status: var(--bs-success); }
.diag-status--warning { --diag-status: var(--bs-warning); }
.diag-status--critical { --diag-status: var(--bs-danger); }
.diag-status--inactive { --diag-status: var(--bs-secondary); }

@media (max-width: 1399.98px) {
    .diagnostics-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .diagnostics-intelligence-grid {
        grid-template-columns: minmax(0, 1.55fr) minmax(16rem, 0.78fr);
    }

    .diagnostics-panel--issues {
        grid-column: 1 / -1;
    }

    .issues-feed {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        max-height: 16rem;
    }
}

@media (max-width: 991.98px) {
    .diagnostics-hero,
    .feature-section-heading,
    .diagnostics-footer-note {
        align-items: flex-start;
        flex-direction: column;
    }

    .diagnostics-hero {
        display: flex;
    }

    .diagnostics-actions {
        justify-content: flex-start;
    }

    .diagnostics-command-row,
    .diagnostics-intelligence-grid {
        grid-template-columns: 1fr;
    }

    .diagnostics-panel--issues {
        grid-column: auto;
    }

    .feature-health-grid,
    .service-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .feature-section-heading small {
        text-align: left;
    }
}

@media (max-width: 575.98px) {
    .diagnostics-console {
        border-radius: 1rem;
    }

    .diagnostics-hero {
        padding: 1rem;
    }

    .diagnostics-heading {
        align-items: flex-start;
    }

    .diagnostics-mark {
        width: 2.7rem;
        height: 2.7rem;
    }

    .diagnostics-actions,
    .diagnostics-actions .btn,
    .diagnostics-actions a {
        width: 100%;
    }

    .diagnostics-kpis,
    .feature-health-grid,
    .service-grid,
    .issues-feed {
        grid-template-columns: 1fr;
    }

    .health-score-card {
        align-items: flex-start;
    }

    .health-orbit {
        width: 5.25rem;
        height: 5.25rem;
    }

    .health-orbit-inner {
        width: 4.15rem;
        height: 4.15rem;
    }

    .feature-health-card p {
        min-height: 0;
    }
}
</style>
