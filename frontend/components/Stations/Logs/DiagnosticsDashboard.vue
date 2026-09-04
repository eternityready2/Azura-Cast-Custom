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
                        {{ $gettext('Live operational intelligence for playout, scheduling, remote streams, RSS imports, AI, monitoring and station services.') }}
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
                    <span>{{ $gettext('Developer Report') }}</span>
                </a>
                <a
                    class="btn btn-sm btn-outline-light diagnostics-download"
                    :href="diagnosticsCsvUrl"
                >
                    <icon-ic-download />
                    <span>{{ $gettext('CSV') }}</span>
                </a>
            </div>
        </div>

        <div class="diagnostics-filter-bar">
            <div class="filter-block">
                <span class="filter-label">{{ $gettext('Time Range') }}</span>
                <div class="range-buttons" role="group" :aria-label="$gettext('Diagnostics time range')">
                    <button
                        v-for="option in rangeOptions"
                        :key="option.value"
                        type="button"
                        class="range-button"
                        :class="{'is-active': range === option.value}"
                        @click="setRange(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div class="filter-block filter-block--feature">
                <label class="filter-label" for="diagnostics_feature_filter">
                    {{ $gettext('Feature') }}
                </label>
                <select
                    id="diagnostics_feature_filter"
                    v-model="featureFilter"
                    class="form-select form-select-sm diagnostics-select"
                >
                    <option value="all">{{ $gettext('All Feature Areas') }}</option>
                    <option
                        v-for="feature in availableFeatures"
                        :key="feature.key"
                        :value="feature.key"
                    >
                        {{ feature.label }}
                    </option>
                </select>
            </div>

            <div v-if="range === 'custom'" class="custom-range">
                <label>
                    <span>{{ $gettext('Start') }}</span>
                    <input v-model="customStart" type="date" class="form-control form-control-sm">
                </label>
                <label>
                    <span>{{ $gettext('End') }}</span>
                    <input v-model="customEnd" type="date" class="form-control form-control-sm">
                </label>
                <button type="button" class="btn btn-sm btn-primary" @click="applyCustomRange">
                    {{ $gettext('Apply') }}
                </button>
            </div>

            <div class="filter-summary">
                <strong>{{ rangeLabel }}</strong>
                <span v-if="summary">{{ formatWindow(summary.window.start, summary.window.end) }}</span>
            </div>
        </div>

        <loading :loading="isLoading" lazy>
            <div
                v-if="isError"
                class="diagnostics-load-error"
            >
                <strong>{{ $gettext('Diagnostics could not be loaded.') }}</strong>
                <span>{{ $gettext('The raw station logs below are still available. Refresh to retry the operational scan.') }}</span>
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
                            <small>{{ healthSummaryText }}</small>
                        </div>
                    </div>

                    <div class="diagnostics-kpis">
                        <div class="diagnostic-kpi diagnostic-kpi--success">
                            <span>{{ $gettext('Successes') }}</span>
                            <strong>{{ summary.counts.successes }}</strong>
                            <small>{{ $gettext('Checks + executions') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--warning">
                            <span>{{ $gettext('Warnings') }}</span>
                            <strong>{{ summary.counts.warning_signals }}</strong>
                            <small>{{ $gettext('Need review') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--danger">
                            <span>{{ $gettext('Failures') }}</span>
                            <strong>{{ summary.counts.failures }}</strong>
                            <small>{{ $gettext('Failed checks/events') }}</small>
                        </div>
                        <div class="diagnostic-kpi">
                            <span>{{ $gettext('Active Issues') }}</span>
                            <strong>{{ summary.counts.active_issues }}</strong>
                            <small>{{ $gettext('Specific problems') }}</small>
                        </div>
                        <div class="diagnostic-kpi diagnostic-kpi--success">
                            <span>{{ $gettext('Services Online') }}</span>
                            <strong>{{ summary.counts.services_running }}/{{ summary.counts.services_total }}</strong>
                            <small>{{ $gettext('Checked live') }}</small>
                        </div>
                    </div>
                </div>

                <div class="diagnostics-intelligence-grid">
                    <article class="diagnostics-panel diagnostics-panel--activity">
                        <div class="panel-heading">
                            <div>
                                <span class="panel-kicker">{{ $gettext('Execution Timeline') }}</span>
                                <h3>{{ $gettext('Operational Activity') }} · {{ rangeLabel }}</h3>
                            </div>
                            <span class="live-indicator">
                                <span />
                                {{ $gettext('Auto-refresh 60s') }}
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
                                :aspect-ratio="1.18"
                            />
                            <div class="distribution-summary">
                                <div>
                                    <span class="status-dot status-dot--healthy" />
                                    <strong>{{ summary.distribution.healthy }}</strong>
                                    <span>{{ $gettext('Healthy') }}</span>
                                </div>
                                <div>
                                    <span class="status-dot status-dot--monitoring" />
                                    <strong>{{ summary.distribution.monitoring }}</strong>
                                    <span>{{ $gettext('Monitoring') }}</span>
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
                            <strong>{{ $gettext('No warning or critical issues detected') }}</strong>
                            <small>{{ $gettext('Feature cards below still distinguish proven runtime health from configuration-only monitoring.') }}</small>
                        </div>

                        <div v-else class="issues-feed">
                            <div
                                v-for="(issue, index) in summary.recent_issues.slice(0, 10)"
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
                                    <small class="issue-source">{{ sourceLabel(issue.source) }}</small>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="feature-section-heading">
                    <div>
                        <span class="panel-kicker">{{ $gettext('Feature-Level Diagnostics') }}</span>
                        <h3>{{ $gettext('Exactly What Is Working — and What Is Not') }}</h3>
                    </div>
                    <small>
                        {{ $gettext('Each feature exposes its observed successes, warnings, failures, current state and specific problems.') }}
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
                            <div>
                                <span class="feature-category">{{ categoryLabel(feature.category) }}</span>
                                <span class="feature-name">{{ feature.label }}</span>
                            </div>
                            <span class="feature-status-pill">{{ statusLabel(feature.status) }}</span>
                        </div>

                        <div class="feature-summary-row">
                            <div>
                                <strong class="feature-metric">{{ feature.metric }}</strong>
                                <div class="feature-headline">{{ feature.headline }}</div>
                            </div>
                            <div class="feature-rate" :class="rateClass(feature)">
                                <strong>{{ formatSuccessRate(feature.stats.success_rate) }}</strong>
                                <span>{{ $gettext('success rate') }}</span>
                            </div>
                        </div>

                        <p class="feature-description">{{ feature.detail }}</p>

                        <div v-if="feature.confidence_note" class="confidence-note">
                            <strong>{{ $gettext('Runtime confidence') }}</strong>
                            <span>{{ feature.confidence_note }}</span>
                        </div>

                        <div class="feature-stat-grid">
                            <div>
                                <strong>{{ feature.stats.successful_executions }}</strong>
                                <span>{{ $gettext('Executed') }}</span>
                            </div>
                            <div>
                                <strong>{{ feature.stats.checks_passed }}</strong>
                                <span>{{ $gettext('Checks Passed') }}</span>
                            </div>
                            <div class="stat-warning">
                                <strong>{{ feature.stats.warnings }}</strong>
                                <span>{{ $gettext('Warnings') }}</span>
                            </div>
                            <div class="stat-failure">
                                <strong>{{ feature.stats.failures }}</strong>
                                <span>{{ $gettext('Failures') }}</span>
                            </div>
                        </div>

                        <diagnostics-feature-chart
                            :successes="feature.stats.successes"
                            :warnings="feature.stats.warnings"
                            :failures="feature.stats.failures"
                        />

                        <div
                            v-if="feature.top_problems.length > 0"
                            class="feature-problems"
                        >
                            <div class="feature-subheading">
                                <strong>{{ $gettext('Problems Found') }}</strong>
                                <span>{{ feature.top_problems.length }} {{ $gettext('shown') }}</span>
                            </div>
                            <div
                                v-for="(problem, problemIndex) in feature.top_problems"
                                :key="`${feature.key}-problem-${problem.timestamp}-${problemIndex}`"
                                class="feature-problem-row"
                                :class="`feature-problem-row--${problem.severity}`"
                            >
                                <div class="problem-heading">
                                    <strong>{{ problem.title }}</strong>
                                    <span>{{ formatIssueTime(problem.timestamp) }}</span>
                                </div>
                                <p>{{ problem.detail || $gettext('No additional detail was supplied by the diagnostic source.') }}</p>
                                <small>{{ sourceLabel(problem.source) }}</small>
                            </div>
                        </div>
                        <div v-else class="feature-problems-clear">
                            <strong>{{ $gettext('No detected problems in this range') }}</strong>
                            <span v-if="feature.status === 'monitoring'">
                                {{ $gettext('Configuration is valid; waiting for runtime execution evidence.') }}
                            </span>
                            <span v-else>
                                {{ $gettext('No warning or failure evidence was found for this feature.') }}
                            </span>
                        </div>

                        <details class="feature-drilldown">
                            <summary>
                                <span>{{ $gettext('Inspection Details') }}</span>
                                <small>{{ $gettext('state + execution trail') }}</small>
                            </summary>

                            <div class="feature-state-grid">
                                <div
                                    v-for="(detail, detailIndex) in feature.details"
                                    :key="`${feature.key}-detail-${detailIndex}`"
                                >
                                    <span>{{ detail.label }}</span>
                                    <strong>{{ formatDetailValue(detail.value) }}</strong>
                                </div>
                            </div>

                            <div v-if="feature.drilldown.length > 0" class="execution-trail">
                                <div class="feature-subheading">
                                    <strong>{{ $gettext('Recent Evidence') }}</strong>
                                </div>
                                <div
                                    v-for="(row, rowIndex) in feature.drilldown"
                                    :key="`${feature.key}-trail-${row.timestamp}-${rowIndex}`"
                                    class="trail-row"
                                    :class="`trail-row--${row.state}`"
                                >
                                    <span class="trail-state" />
                                    <div>
                                        <div class="trail-heading">
                                            <strong>{{ row.title }}</strong>
                                            <span>{{ formatIssueTime(row.timestamp) }}</span>
                                        </div>
                                        <p v-if="row.detail">{{ row.detail }}</p>
                                        <small>{{ sourceLabel(row.source) }}</small>
                                    </div>
                                </div>
                            </div>

                            <div class="feature-timestamps">
                                <span>
                                    {{ $gettext('Last success') }}:
                                    <strong>{{ formatEvidenceTime(feature.last_success_at) }}</strong>
                                </span>
                                <span>
                                    {{ $gettext('Last failure') }}:
                                    <strong>{{ formatEvidenceTime(feature.last_failure_at) }}</strong>
                                </span>
                                <span>
                                    {{ $gettext('Evidence basis') }}:
                                    <strong>{{ basisLabel(feature.basis) }}</strong>
                                </span>
                            </div>
                        </details>
                    </article>
                </div>

                <div class="service-matrix">
                    <div class="service-matrix-heading">
                        <div>
                            <span class="panel-kicker">{{ $gettext('Runtime Services') }}</span>
                            <h3>{{ $gettext('Broadcast & Infrastructure') }}</h3>
                        </div>
                        <span>{{ $gettext('Live status with failure details') }}</span>
                    </div>
                    <div class="service-grid">
                        <article
                            v-for="service in summary.services"
                            :key="`${service.scope}-${service.key}`"
                            class="service-chip"
                            :class="statusClass(service.status)"
                        >
                            <div class="service-topline">
                                <span class="service-light" />
                                <div>
                                    <strong>{{ service.name }}</strong>
                                    <small>{{ service.scope === 'station' ? $gettext('Station') : $gettext('System') }}</small>
                                </div>
                                <span class="service-status">{{ statusLabel(service.status) }}</span>
                            </div>
                            <p v-if="service.status === 'critical'" class="service-problem">
                                {{ service.problem || service.description || $gettext('Service is configured but is not running.') }}
                            </p>
                            <p v-else-if="service.description" class="service-description">
                                {{ service.description }}
                            </p>
                            <small v-if="service.recovery" class="service-recovery">
                                {{ $gettext('Recovery') }}: {{ service.recovery }}
                            </small>
                        </article>
                    </div>
                </div>

                <div class="diagnostics-footer-note">
                    <span>{{ $gettext('Last analyzed') }}: {{ formatGenerated(summary.generated_at) }}</span>
                    <span>{{ $gettext('The Developer Report and CSV use the same time and feature filters shown above.') }}</span>
                </div>
            </template>
        </loading>

        <streaming-log-modal ref="$diagnosticsModal" />
    </section>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from "vue";
import {useQuery} from "@tanstack/vue-query";
import DoughnutChart from "~/components/Common/Charts/DoughnutChart.vue";
import Loading from "~/components/Common/Loading.vue";
import StreamingLogModal from "~/components/Common/StreamingLogModal.vue";
import DiagnosticsActivityChart from "~/components/Stations/Logs/DiagnosticsActivityChart.vue";
import DiagnosticsFeatureChart from "~/components/Stations/Logs/DiagnosticsFeatureChart.vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useTranslate} from "~/vendor/gettext";
import IconIcDownload from "~icons/ic/baseline-download";
import IconIcRefresh from "~icons/ic/baseline-refresh";
import IconIcVisibility from "~icons/ic/baseline-visibility";

type RangeValue = '24h' | '7d' | '30d' | 'custom';
type DiagnosticStatus = 'healthy' | 'monitoring' | 'warning' | 'critical' | 'inactive' | string;

interface DiagnosticsTimelinePoint {
    timestamp: number,
    critical: number,
    warning: number,
    info: number,
}

interface DiagnosticsDetail {
    label: string,
    value: unknown,
}

interface DiagnosticsIssue {
    severity: 'critical' | 'warning' | 'success',
    feature_key: string,
    feature: string,
    title: string,
    detail: string,
    timestamp: number,
    source: string,
}

interface DiagnosticsDrilldown {
    state: 'success' | 'warning' | 'failure',
    title: string,
    detail: string,
    timestamp: number,
    source: string,
}

interface DiagnosticsFeatureStats {
    successes: number,
    successful_executions: number,
    checks_passed: number,
    warnings: number,
    failures: number,
    observations: number,
    success_rate: number | null,
}

interface DiagnosticsFeature {
    key: string,
    label: string,
    category: string,
    status: DiagnosticStatus,
    headline: string,
    detail: string,
    metric: string,
    basis: string,
    issues: number,
    details: DiagnosticsDetail[],
    stats: DiagnosticsFeatureStats,
    top_problems: DiagnosticsIssue[],
    activity: Array<Record<string, number>>,
    drilldown: DiagnosticsDrilldown[],
    last_success_at: number | null,
    last_failure_at: number | null,
    confidence_note?: string | null,
}

interface DiagnosticsService {
    key: string,
    name: string,
    description: string,
    problem?: string | null,
    scope: string,
    recovery: string,
    status: DiagnosticStatus,
    running: boolean | null,
}

interface AvailableFeature {
    key: string,
    label: string,
    category: string,
}

interface DiagnosticsSummary {
    generated_at: number,
    window_hours: number,
    window: {
        start: number,
        end: number,
        hours: number,
        bucket_seconds: number,
    },
    filter: {
        feature: string | null,
    },
    available_features: AvailableFeature[],
    overall_status: DiagnosticStatus,
    health_score: number,
    counts: {
        critical: number,
        warning: number,
        healthy: number,
        monitoring: number,
        inactive: number,
        recent_events: number,
        active_issues: number,
        services_running: number,
        services_total: number,
        successes: number,
        failures: number,
        warning_signals: number,
    },
    distribution: {
        healthy: number,
        monitoring: number,
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

const summaryBaseUrl = getStationApiUrl('/diagnostics/summary');
const diagnosticsBaseUrl = getStationApiUrl('/diagnostics');
const diagnosticsDownloadBaseUrl = getStationApiUrl('/diagnostics/download');
const diagnosticsCsvBaseUrl = getStationApiUrl('/diagnostics/report');

const range = ref<RangeValue>('24h');
const featureFilter = ref('all');
const customStart = ref(dateInputValue(-7));
const customEnd = ref(dateInputValue(0));
const appliedCustomStart = ref(customStart.value);
const appliedCustomEnd = ref(customEnd.value);

const rangeOptions = computed(() => [
    {value: '24h' as RangeValue, label: $gettext('24 Hours')},
    {value: '7d' as RangeValue, label: $gettext('7 Days')},
    {value: '30d' as RangeValue, label: $gettext('30 Days')},
    {value: 'custom' as RangeValue, label: $gettext('Custom')},
]);

const queryParams = computed(() => {
    const params = new URLSearchParams();
    params.set('range', range.value);
    if (featureFilter.value !== 'all') {
        params.set('feature', featureFilter.value);
    }
    if (range.value === 'custom') {
        params.set('start', appliedCustomStart.value);
        params.set('end', appliedCustomEnd.value);
    }
    return params.toString();
});

const withQuery = (base: string): string => `${base}?${queryParams.value}`;
const summaryRequestUrl = computed(() => withQuery(summaryBaseUrl.value));
const diagnosticsViewUrl = computed(() => withQuery(diagnosticsBaseUrl.value));
const diagnosticsDownloadUrl = computed(() => withQuery(diagnosticsDownloadBaseUrl.value));
const diagnosticsCsvUrl = computed(() => withQuery(diagnosticsCsvBaseUrl.value));

const {
    data: summary,
    isLoading,
    isFetching,
    isError,
    refetch,
} = useQuery<DiagnosticsSummary>({
    queryKey: computed(() => queryKeyWithStation([
        QueryKeys.StationDiagnostics,
        queryParams.value,
    ])),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<DiagnosticsSummary>(summaryRequestUrl.value, {signal});
        return data;
    },
    refetchInterval: 60_000,
});

const $diagnosticsModal = useTemplateRef('$diagnosticsModal');

const viewDetailedDiagnostics = () => {
    $diagnosticsModal.value?.show(diagnosticsViewUrl.value, false);
};

const setRange = (newRange: RangeValue) => {
    range.value = newRange;
    if (newRange === 'custom') {
        appliedCustomStart.value = customStart.value;
        appliedCustomEnd.value = customEnd.value;
    }
};

const applyCustomRange = () => {
    appliedCustomStart.value = customStart.value;
    appliedCustomEnd.value = customEnd.value;
    range.value = 'custom';
};

const availableFeatures = computed(() => summary.value?.available_features ?? []);

const rangeLabel = computed(() => {
    switch (range.value) {
        case '7d':
            return $gettext('Last 7 Days');
        case '30d':
            return $gettext('Last 30 Days');
        case 'custom':
            return $gettext('Custom Range');
        default:
            return $gettext('Last 24 Hours');
    }
});

const healthSummaryText = computed(() => {
    if (!summary.value) {
        return '';
    }
    const d = summary.value.distribution;
    return `${d.healthy} ${$gettext('healthy')} · ${d.monitoring} ${$gettext('monitoring')} · ${d.warning} ${$gettext('warning')} · ${d.critical} ${$gettext('critical')}`;
});

const distributionLabels = computed(() => [
    $gettext('Healthy'),
    $gettext('Monitoring'),
    $gettext('Warning'),
    $gettext('Critical'),
    $gettext('Inactive'),
]);

const distributionDatasets = computed(() => [{
    data: summary.value
        ? [
            summary.value.distribution.healthy,
            summary.value.distribution.monitoring,
            summary.value.distribution.warning,
            summary.value.distribution.critical,
            summary.value.distribution.inactive,
        ]
        : [0, 0, 0, 0, 0],
    backgroundColor: [
        'rgba(25, 135, 84, 0.86)',
        'rgba(13, 202, 240, 0.82)',
        'rgba(255, 193, 7, 0.86)',
        'rgba(220, 53, 69, 0.88)',
        'rgba(108, 117, 125, 0.52)',
    ],
    borderWidth: 0,
    hoverOffset: 4,
}] as any[]);

const statusClass = (status: DiagnosticStatus): string => `diag-status--${status}`;

const statusLabel = (status: DiagnosticStatus): string => {
    switch (status) {
        case 'healthy':
            return $gettext('Healthy');
        case 'monitoring':
            return $gettext('Monitoring');
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
    const labels: string[] = [];
    if (basis.includes('live')) labels.push($gettext('live runtime'));
    if (basis.includes('history')) labels.push($gettext('play history'));
    if (basis.includes('database')) labels.push($gettext('database'));
    if (basis.includes('events')) labels.push($gettext('diagnostic events'));
    if (basis.includes('logs')) labels.push($gettext('runtime logs'));
    if (basis.includes('state')) labels.push($gettext('configuration'));
    return labels.length > 0 ? labels.join(' + ') : $gettext('configuration');
};

const categoryLabel = (category: string): string => category
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');

const sourceLabel = (source: string): string => {
    switch (source) {
        case 'live':
            return $gettext('Live service check');
        case 'service_log':
            return $gettext('Runtime service log');
        case 'diagnostics':
            return $gettext('Feature diagnostic event');
        case 'state':
            return $gettext('Configuration/state check');
        default:
            return source || $gettext('Diagnostic source');
    }
};

const rateClass = (feature: DiagnosticsFeature): string => {
    if (feature.stats.success_rate === null) return 'rate-neutral';
    if (feature.stats.failures > 0) return 'rate-danger';
    if (feature.stats.warnings > 0) return 'rate-warning';
    return 'rate-success';
};

const formatSuccessRate = (rate: number | null): string => rate === null ? '—' : `${rate.toFixed(1)}%`;
const formatGenerated = (timestamp: number): string => new Date(timestamp * 1000).toLocaleString();
const formatEvidenceTime = (timestamp: number | null): string => timestamp ? new Date(timestamp * 1000).toLocaleString() : $gettext('No evidence in range');
const formatWindow = (start: number, end: number): string => `${new Date(start * 1000).toLocaleString()} — ${new Date(end * 1000).toLocaleString()}`;

const formatIssueTime = (timestamp: number): string => {
    const then = new Date(timestamp * 1000);
    const now = Date.now();
    const elapsedMinutes = Math.max(0, Math.floor((now - then.getTime()) / 60_000));

    if (elapsedMinutes < 1) return $gettext('now');
    if (elapsedMinutes < 60) return `${elapsedMinutes}m`;
    if (elapsedMinutes < 1440) return `${Math.floor(elapsedMinutes / 60)}h`;
    return then.toLocaleString();
};

const formatDetailValue = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? $gettext('Yes') : $gettext('No');
    return String(value);
};

function dateInputValue(offsetDays: number): string {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
</script>

<style lang="scss" scoped>
.diagnostics-console {
    --diag-status: var(--bs-success);
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--bs-border-color) 68%, transparent);
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
    padding: 1.15rem 1.25rem 1.05rem;
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
.panel-kicker,
.feature-category,
.filter-label {
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
    max-width: 56rem;
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

.diagnostics-filter-bar {
    display: flex;
    align-items: end;
    gap: 0.8rem;
    padding: 0.78rem 0.9rem;
    border-top: 1px solid color-mix(in srgb, var(--bs-border-color) 45%, transparent);
    border-bottom: 1px solid color-mix(in srgb, var(--bs-border-color) 55%, transparent);
    background: color-mix(in srgb, var(--bs-body-bg) 91%, var(--bs-primary) 9%);
}

.filter-block {
    display: grid;
    gap: 0.3rem;
}

.filter-block--feature {
    min-width: 14rem;
}

.range-buttons {
    display: inline-flex;
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.62rem;
    background: var(--bs-body-bg);
}

.range-button {
    border: 0;
    border-right: 1px solid var(--bs-border-color);
    padding: 0.42rem 0.68rem;
    background: transparent;
    color: var(--bs-secondary-color);
    font-size: 0.74rem;
    font-weight: 700;
}

.range-button:last-child { border-right: 0; }
.range-button:hover { color: var(--bs-body-color); background: var(--bs-tertiary-bg); }
.range-button.is-active { color: #fff; background: var(--bs-primary); }

.diagnostics-select {
    min-height: 2.1rem;
    border-radius: 0.62rem;
}

.custom-range {
    display: flex;
    align-items: end;
    gap: 0.45rem;
}

.custom-range label {
    display: grid;
    gap: 0.2rem;
    color: var(--bs-secondary-color);
    font-size: 0.67rem;
    font-weight: 700;
}

.custom-range input { min-width: 8.8rem; border-radius: 0.55rem; }

.filter-summary {
    display: grid;
    min-width: 0;
    margin-left: auto;
    justify-items: end;
    gap: 0.08rem;
    text-align: right;
}

.filter-summary strong { font-size: 0.76rem; }
.filter-summary span {
    overflow: hidden;
    max-width: 27rem;
    color: var(--bs-secondary-color);
    font-size: 0.64rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.diagnostics-command-row {
    display: grid;
    grid-template-columns: minmax(16rem, 0.82fr) minmax(0, 2.35fr);
    gap: 0.7rem;
    padding: 0.85rem 0.85rem 0.8rem;
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

.health-orbit-inner strong { color: var(--diag-status); font-size: 1.72rem; line-height: 1; }
.health-orbit-inner span { margin-top: 0.15rem; color: var(--bs-secondary-color); font-size: 0.64rem; font-weight: 700; }

.health-score-copy { display: grid; gap: 0.14rem; }
.health-score-label { color: var(--bs-secondary-color); font-size: 0.68rem; font-weight: 750; letter-spacing: 0.065em; text-transform: uppercase; }
.health-score-copy > strong { color: var(--diag-status); font-size: 1.08rem; }
.health-score-copy small { color: var(--bs-secondary-color); line-height: 1.35; }

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
.diagnostic-kpi span { overflow: hidden; color: var(--bs-secondary-color); font-size: 0.68rem; font-weight: 750; letter-spacing: 0.045em; text-overflow: ellipsis; text-transform: uppercase; white-space: nowrap; }
.diagnostic-kpi strong { font-size: clamp(1.35rem, 2.4vw, 2rem); letter-spacing: -0.04em; }
.diagnostic-kpi small { color: var(--bs-secondary-color); font-size: 0.67rem; }

.diagnostics-intelligence-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(15rem, 0.72fr) minmax(19rem, 1fr);
    gap: 0.7rem;
    padding: 0 0.85rem 0.85rem;
}

.diagnostics-panel { min-width: 0; border-radius: 1rem; padding: 0.9rem; }
.panel-heading,
.feature-section-heading,
.service-matrix-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.panel-heading { margin-bottom: 0.65rem; }
.panel-heading h3,
.feature-section-heading h3,
.service-matrix-heading h3 { margin: 0.12rem 0 0; font-size: 0.94rem; font-weight: 750; letter-spacing: -0.01em; }

.live-indicator { display: inline-flex; align-items: center; gap: 0.35rem; color: var(--bs-secondary-color); font-size: 0.65rem; font-weight: 650; white-space: nowrap; }
.live-indicator > span { width: 0.48rem; height: 0.48rem; border-radius: 50%; background: var(--bs-success); box-shadow: 0 0 0.55rem rgba(var(--bs-success-rgb), 0.6); }
.distribution-chart-wrap { display: grid; align-items: center; gap: 0.65rem; }
.distribution-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.3rem 0.55rem; }
.distribution-summary > div { display: grid; grid-template-columns: auto auto 1fr; align-items: center; gap: 0.32rem; font-size: 0.68rem; }
.status-dot { width: 0.48rem; height: 0.48rem; border-radius: 50%; background: var(--bs-secondary); }
.status-dot--healthy { background: var(--bs-success); }
.status-dot--monitoring { background: var(--bs-info); }
.status-dot--warning { background: var(--bs-warning); }
.status-dot--critical { background: var(--bs-danger); }
.status-dot--inactive { background: var(--bs-secondary); }

.issue-count { display: grid; min-width: 1.65rem; height: 1.65rem; place-items: center; border-radius: 999px; background: color-mix(in srgb, var(--bs-danger) 18%, var(--bs-body-bg)); color: var(--bs-danger); font-size: 0.7rem; font-weight: 800; }
.issues-feed { display: grid; max-height: 18.5rem; gap: 0.45rem; overflow: auto; padding-right: 0.15rem; }
.issue-row { position: relative; display: grid; grid-template-columns: 0.22rem 1fr; overflow: hidden; border-radius: 0.7rem; background: color-mix(in srgb, var(--bs-secondary-bg) 88%, var(--bs-warning) 12%); }
.issue-row--critical { background: color-mix(in srgb, var(--bs-secondary-bg) 88%, var(--bs-danger) 12%); }
.issue-rail { background: var(--bs-warning); }
.issue-row--critical .issue-rail { background: var(--bs-danger); }
.issue-copy { min-width: 0; padding: 0.55rem 0.62rem; }
.issue-meta { display: flex; justify-content: space-between; gap: 0.6rem; color: var(--bs-secondary-color); font-size: 0.62rem; }
.issue-meta strong { color: var(--bs-body-color); }
.issue-title { margin-top: 0.13rem; font-size: 0.72rem; font-weight: 720; }
.issue-detail { margin-top: 0.12rem; color: var(--bs-secondary-color); font-size: 0.66rem; line-height: 1.35; }
.issue-source { display: block; margin-top: 0.25rem; color: var(--bs-secondary-color); font-size: 0.58rem; text-transform: uppercase; }
.issues-clear { display: grid; min-height: 12rem; place-content: center; gap: 0.2rem; text-align: center; }
.issues-clear strong { font-size: 0.82rem; }
.issues-clear small { max-width: 22rem; color: var(--bs-secondary-color); }

.feature-section-heading { padding: 0.25rem 0.95rem 0.7rem; }
.feature-section-heading > small { max-width: 36rem; color: var(--bs-secondary-color); text-align: right; }

.feature-health-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.72rem;
    padding: 0 0.85rem 0.85rem;
}

.feature-health-card {
    --diag-status: var(--bs-success);
    position: relative;
    min-width: 0;
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--diag-status) 20%, var(--bs-border-color));
    border-radius: 1rem;
    padding: 0.9rem 0.95rem 0.85rem 1.05rem;
}

.feature-status-rail { position: absolute; inset: 0 auto 0 0; width: 0.24rem; background: var(--diag-status); }
.feature-card-topline { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.65rem; }
.feature-card-topline > div { display: grid; gap: 0.08rem; }
.feature-category { font-size: 0.56rem; }
.feature-name { font-size: 0.9rem; font-weight: 780; }
.feature-status-pill { flex: 0 0 auto; border: 1px solid color-mix(in srgb, var(--diag-status) 36%, transparent); border-radius: 999px; padding: 0.2rem 0.48rem; background: color-mix(in srgb, var(--diag-status) 12%, transparent); color: var(--diag-status); font-size: 0.59rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }

.feature-summary-row { display: flex; align-items: end; justify-content: space-between; gap: 0.7rem; margin-top: 0.65rem; }
.feature-metric { display: block; color: var(--diag-status); font-size: 1.18rem; line-height: 1.05; }
.feature-headline { margin-top: 0.16rem; font-size: 0.72rem; font-weight: 700; }
.feature-description { min-height: 2.2rem; margin: 0.35rem 0 0.55rem; color: var(--bs-secondary-color); font-size: 0.68rem; line-height: 1.45; }
.feature-rate { display: grid; justify-items: end; }
.feature-rate strong { font-size: 1rem; }
.feature-rate span { color: var(--bs-secondary-color); font-size: 0.56rem; text-transform: uppercase; }
.rate-success strong { color: var(--bs-success); }
.rate-warning strong { color: var(--bs-warning); }
.rate-danger strong { color: var(--bs-danger); }
.rate-neutral strong { color: var(--bs-secondary-color); }

.confidence-note { display: grid; gap: 0.08rem; margin-bottom: 0.55rem; border-left: 0.2rem solid var(--bs-info); padding: 0.38rem 0.48rem; background: color-mix(in srgb, var(--bs-info) 8%, transparent); }
.confidence-note strong { color: var(--bs-info-text-emphasis); font-size: 0.62rem; text-transform: uppercase; }
.confidence-note span { color: var(--bs-secondary-color); font-size: 0.64rem; line-height: 1.35; }

.feature-stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.34rem; margin-bottom: 0.45rem; }
.feature-stat-grid > div { display: grid; gap: 0.05rem; border-radius: 0.55rem; padding: 0.38rem 0.42rem; background: var(--bs-tertiary-bg); }
.feature-stat-grid strong { font-size: 0.85rem; }
.feature-stat-grid span { color: var(--bs-secondary-color); font-size: 0.55rem; text-transform: uppercase; }
.feature-stat-grid .stat-warning strong { color: var(--bs-warning); }
.feature-stat-grid .stat-failure strong { color: var(--bs-danger); }

.feature-problems,
.feature-problems-clear { margin-top: 0.55rem; border-radius: 0.7rem; background: color-mix(in srgb, var(--bs-secondary-bg) 93%, var(--bs-danger) 7%); }
.feature-problems { padding: 0.55rem; }
.feature-problems-clear { display: grid; gap: 0.12rem; padding: 0.55rem 0.62rem; background: color-mix(in srgb, var(--bs-secondary-bg) 94%, var(--bs-success) 6%); }
.feature-problems-clear strong { font-size: 0.68rem; }
.feature-problems-clear span { color: var(--bs-secondary-color); font-size: 0.62rem; }
.feature-subheading { display: flex; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.32rem; }
.feature-subheading strong { font-size: 0.65rem; text-transform: uppercase; }
.feature-subheading span { color: var(--bs-secondary-color); font-size: 0.6rem; }
.feature-problem-row { border-top: 1px solid color-mix(in srgb, var(--bs-border-color) 70%, transparent); padding: 0.42rem 0; }
.feature-problem-row:first-of-type { border-top: 0; }
.feature-problem-row--critical { border-left: 0.16rem solid var(--bs-danger); padding-left: 0.45rem; }
.feature-problem-row--warning { border-left: 0.16rem solid var(--bs-warning); padding-left: 0.45rem; }
.problem-heading { display: flex; justify-content: space-between; gap: 0.55rem; }
.problem-heading strong { font-size: 0.68rem; }
.problem-heading span { color: var(--bs-secondary-color); font-size: 0.58rem; }
.feature-problem-row p { margin: 0.12rem 0; color: var(--bs-secondary-color); font-size: 0.63rem; line-height: 1.4; }
.feature-problem-row small { color: var(--bs-secondary-color); font-size: 0.55rem; text-transform: uppercase; }

.feature-drilldown { margin-top: 0.55rem; border-top: 1px solid var(--bs-border-color); padding-top: 0.48rem; }
.feature-drilldown summary { display: flex; cursor: pointer; align-items: center; justify-content: space-between; gap: 0.5rem; list-style: none; font-size: 0.68rem; font-weight: 730; }
.feature-drilldown summary::-webkit-details-marker { display: none; }
.feature-drilldown summary small { color: var(--bs-secondary-color); font-size: 0.56rem; font-weight: 500; text-transform: uppercase; }
.feature-state-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.35rem; margin-top: 0.55rem; }
.feature-state-grid > div { display: grid; gap: 0.06rem; border-radius: 0.52rem; padding: 0.4rem 0.48rem; background: var(--bs-tertiary-bg); }
.feature-state-grid span { color: var(--bs-secondary-color); font-size: 0.57rem; }
.feature-state-grid strong { overflow-wrap: anywhere; font-size: 0.66rem; }
.execution-trail { margin-top: 0.65rem; }
.trail-row { display: grid; grid-template-columns: 0.45rem 1fr; gap: 0.42rem; border-top: 1px solid var(--bs-border-color); padding: 0.42rem 0; }
.trail-state { width: 0.42rem; height: 0.42rem; margin-top: 0.22rem; border-radius: 50%; background: var(--bs-success); }
.trail-row--warning .trail-state { background: var(--bs-warning); }
.trail-row--failure .trail-state { background: var(--bs-danger); }
.trail-heading { display: flex; justify-content: space-between; gap: 0.45rem; }
.trail-heading strong { font-size: 0.65rem; }
.trail-heading span { color: var(--bs-secondary-color); font-size: 0.56rem; }
.trail-row p { margin: 0.08rem 0; color: var(--bs-secondary-color); font-size: 0.61rem; }
.trail-row small { color: var(--bs-secondary-color); font-size: 0.54rem; text-transform: uppercase; }
.feature-timestamps { display: grid; gap: 0.18rem; margin-top: 0.5rem; border-radius: 0.52rem; padding: 0.45rem; background: var(--bs-tertiary-bg); }
.feature-timestamps span { color: var(--bs-secondary-color); font-size: 0.59rem; }
.feature-timestamps strong { color: var(--bs-body-color); }

.service-matrix { margin: 0 0.85rem 0.85rem; border-radius: 1rem; padding: 0.9rem; }
.service-matrix-heading { margin-bottom: 0.65rem; }
.service-matrix-heading > span { color: var(--bs-secondary-color); font-size: 0.63rem; }
.service-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.48rem; }
.service-chip { --diag-status: var(--bs-success); min-width: 0; border: 1px solid color-mix(in srgb, var(--diag-status) 18%, var(--bs-border-color)); border-radius: 0.72rem; padding: 0.55rem 0.62rem; background: var(--bs-tertiary-bg); }
.service-topline { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 0.5rem; }
.service-light { width: 0.52rem; height: 0.52rem; border-radius: 50%; background: var(--diag-status); box-shadow: 0 0 0.6rem color-mix(in srgb, var(--diag-status) 44%, transparent); }
.service-topline > div { display: grid; min-width: 0; }
.service-topline strong { overflow: hidden; font-size: 0.69rem; text-overflow: ellipsis; white-space: nowrap; }
.service-topline small { color: var(--bs-secondary-color); font-size: 0.55rem; }
.service-status { color: var(--diag-status); font-size: 0.56rem; font-weight: 800; text-transform: uppercase; }
.service-problem { margin: 0.42rem 0 0; border-left: 0.18rem solid var(--bs-danger); padding-left: 0.42rem; color: var(--bs-danger-text-emphasis); font-size: 0.61rem; line-height: 1.4; }
.service-description { margin: 0.35rem 0 0; color: var(--bs-secondary-color); font-size: 0.59rem; line-height: 1.35; }
.service-recovery { display: block; margin-top: 0.25rem; color: var(--bs-secondary-color); font-size: 0.56rem; }

.diagnostics-footer-note { display: flex; justify-content: space-between; gap: 1rem; padding: 0 1rem 0.9rem; color: var(--bs-secondary-color); font-size: 0.6rem; }
.diagnostics-load-error { display: grid; gap: 0.2rem; margin: 0.9rem; border-left: 0.25rem solid var(--bs-danger); border-radius: 0.5rem; padding: 0.75rem; background: color-mix(in srgb, var(--bs-danger) 9%, var(--bs-body-bg)); }
.diagnostics-load-error span { color: var(--bs-secondary-color); font-size: 0.7rem; }

.diag-status--healthy { --diag-status: var(--bs-success); }
.diag-status--monitoring { --diag-status: var(--bs-info); }
.diag-status--warning { --diag-status: var(--bs-warning); }
.diag-status--critical { --diag-status: var(--bs-danger); }
.diag-status--inactive { --diag-status: var(--bs-secondary); }

@media (max-width: 1450px) {
    .diagnostics-intelligence-grid { grid-template-columns: minmax(0, 1.35fr) minmax(15rem, 0.72fr); }
    .diagnostics-panel--issues { grid-column: 1 / -1; }
    .issues-feed { grid-template-columns: repeat(2, minmax(0, 1fr)); max-height: none; }
}

@media (max-width: 1199.98px) {
    .diagnostics-hero { align-items: flex-start; }
    .diagnostics-filter-bar { flex-wrap: wrap; }
    .filter-summary { width: 100%; margin-left: 0; justify-items: start; text-align: left; }
    .diagnostics-command-row { grid-template-columns: 1fr; }
    .diagnostics-kpis { grid-template-columns: repeat(5, minmax(7rem, 1fr)); overflow-x: auto; }
    .feature-health-grid { grid-template-columns: 1fr; }
    .service-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 767.98px) {
    .diagnostics-hero { flex-direction: column; }
    .diagnostics-actions { width: 100%; justify-content: flex-start; }
    .diagnostics-filter-bar { align-items: stretch; }
    .filter-block--feature { min-width: 100%; }
    .range-buttons { display: grid; grid-template-columns: repeat(2, 1fr); }
    .range-button { border-bottom: 1px solid var(--bs-border-color); }
    .custom-range { width: 100%; flex-wrap: wrap; }
    .diagnostics-intelligence-grid { grid-template-columns: 1fr; }
    .diagnostics-panel--issues { grid-column: auto; }
    .issues-feed { grid-template-columns: 1fr; }
    .service-grid { grid-template-columns: 1fr; }
    .feature-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .feature-state-grid { grid-template-columns: 1fr; }
    .feature-section-heading,
    .service-matrix-heading,
    .diagnostics-footer-note { flex-direction: column; }
    .feature-section-heading > small { text-align: left; }
}
</style>
