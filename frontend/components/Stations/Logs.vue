<template>
    <diagnostics-dashboard />

    <div class="raw-logs-heading">
        <div>
            <span>{{ $gettext('Deep Inspection') }}</span>
            <h2>{{ $gettext('Detailed Service Logs') }}</h2>
            <p>{{ $gettext('Raw files are shown with their live availability and size. An empty file means that service has not written any entries to that log yet.') }}</p>
        </div>
    </div>

    <div class="row row-of-cards">
        <div class="col-lg-8">
            <section
                class="card raw-logs-card"
                role="region"
                aria-labelledby="hdr_available_logs"
            >
                <div class="card-header raw-logs-card-header">
                    <div>
                        <h2
                            id="hdr_available_logs"
                            class="card-title"
                        >
                            {{ $gettext('Available Logs') }}
                        </h2>
                        <small>{{ $gettext('Click an available file to inspect its current contents.') }}</small>
                    </div>
                    <div v-if="data" class="log-file-summary">
                        <span>{{ populatedLogCount }} {{ $gettext('with data') }}</span>
                        <span>{{ emptyLogCount }} {{ $gettext('empty') }}</span>
                        <span>{{ unavailableLogCount }} {{ $gettext('unavailable') }}</span>
                    </div>
                </div>

                <loading :loading="isLoading" lazy>
                    <div v-if="data" class="station-log-list">
                        <button
                            v-for="log in data"
                            :key="log.key"
                            type="button"
                            class="station-log-row"
                            :class="{
                                'station-log-row--empty': log.exists && log.size === 0,
                                'station-log-row--missing': !log.exists,
                            }"
                            :disabled="!log.exists"
                            @click="viewLog(log.links.self, log.tail)"
                        >
                            <span class="log-state-light" :class="logStateClass(log)" />
                            <span class="log-row-copy">
                                <span class="log-row-topline">
                                    <strong>{{ log.name }}</strong>
                                    <span class="log-state-badge" :class="logStateClass(log)">
                                        {{ logStateLabel(log) }}
                                    </span>
                                </span>
                                <small class="log-path">{{ log.path }}</small>
                                <span class="log-meta">
                                    <span v-if="log.exists">{{ formatBytes(log.size) }}</span>
                                    <span v-if="log.modified_at">{{ $gettext('Updated') }} {{ formatModified(log.modified_at) }}</span>
                                    <span v-if="log.exists && log.size === 0">{{ $gettext('No entries have been written yet') }}</span>
                                    <span v-if="!log.exists">{{ $gettext('File has not been created by this service') }}</span>
                                </span>
                            </span>
                        </button>
                    </div>
                </loading>

                <div class="raw-log-note">
                    <strong>{{ $gettext('Need a complete shareable diagnostic?') }}</strong>
                    <span>{{ $gettext('Use Developer Report at the top of this page. It is generated from station state, execution history, feature diagnostics and live service checks, so it remains useful even when an individual raw error log is empty.') }}</span>
                </div>
            </section>

            <streaming-log-modal ref="$modal" />
        </div>
        <div class="col-lg-4">
            <section
                class="card support-card"
                role="region"
                aria-labelledby="hdr_need_help"
            >
                <div class="card-header">
                    <h2
                        id="hdr_need_help"
                        class="card-title"
                    >
                        {{ $gettext('Need Help?') }}
                    </h2>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        {{ $gettext('You can find answers for many common questions in our support documents.') }}
                    </p>
                    <p class="card-text">
                        <a
                            href="/docs/help/troubleshooting/"
                            target="_blank"
                        >
                            {{ $gettext('Support Documents') }}
                        </a>
                    </p>
                    <p class="card-text">
                        {{ $gettext('For a bug report, share the filtered Developer Report or CSV plus any populated raw service log relevant to the issue.') }}
                    </p>
                </div>
                <div class="card-body pt-0">
                    <a
                        class="btn btn-primary"
                        role="button"
                        href="https://github.com/AzuraCast/AzuraCast/issues/new/choose"
                        target="_blank"
                    >
                        <icon-ic-support />
                        <span>{{ $gettext('Add New GitHub Issue') }}</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import StreamingLogModal from "~/components/Common/StreamingLogModal.vue";
import DiagnosticsDashboard from "~/components/Stations/Logs/DiagnosticsDashboard.vue";
import {computed, useTemplateRef} from "vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useQuery} from "@tanstack/vue-query";
import {ApiLogType} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import IconIcSupport from "~icons/ic/baseline-support";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const logsUrl = getStationApiUrl('/logs');
const {axios} = useAxios();

type ApiLogRow = Required<ApiLogType> & {
    exists: boolean,
    size: number,
    modified_at: number | null,
}

const {data, isLoading} = useQuery<ApiLogRow[]>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationLogs
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ApiLogRow[]>(logsUrl.value, {signal});
        return data;
    },
    placeholderData: () => []
});

const populatedLogCount = computed(() => data.value?.filter((log) => log.exists && log.size > 0).length ?? 0);
const emptyLogCount = computed(() => data.value?.filter((log) => log.exists && log.size === 0).length ?? 0);
const unavailableLogCount = computed(() => data.value?.filter((log) => !log.exists).length ?? 0);

const $modal = useTemplateRef('$modal');

const viewLog = (url: string, isStreaming: boolean) => {
    $modal.value?.show(url, isStreaming);
};

const logStateLabel = (log: ApiLogRow): string => {
    if (!log.exists) return $gettext('Unavailable');
    if (log.size === 0) return $gettext('Empty');
    return $gettext('Has Data');
};

const logStateClass = (log: ApiLogRow): string => {
    if (!log.exists) return 'log-state--missing';
    if (log.size === 0) return 'log-state--empty';
    return 'log-state--ready';
};

const formatModified = (timestamp: number): string => new Date(timestamp * 1000).toLocaleString();

const formatBytes = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
</script>

<style lang="scss" scoped>
.raw-logs-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 1rem;
    margin: 0.1rem 0 0.75rem;
    padding: 0 0.2rem;
}

.raw-logs-heading span {
    color: var(--bs-primary-text-emphasis);
    font-size: 0.66rem;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.raw-logs-heading h2 {
    margin: 0.12rem 0 0.14rem;
    font-size: 1.12rem;
    font-weight: 760;
}

.raw-logs-heading p {
    max-width: 58rem;
    margin: 0;
    color: var(--bs-secondary-color);
    font-size: 0.76rem;
}

.raw-logs-card,
.support-card {
    overflow: hidden;
    border: 0;
    border-radius: 1rem;
    box-shadow:
        0 0.6rem 1.8rem rgba(0, 0, 0, 0.09),
        inset 0 1px 0 rgba(255, 255, 255, 0.035);
}

.raw-logs-card .card-header,
.support-card .card-header {
    border: 0;
    background:
        linear-gradient(110deg, rgba(var(--bs-primary-rgb), 0.15), transparent 72%),
        var(--bs-tertiary-bg);
}

.raw-logs-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.raw-logs-card .card-title,
.support-card .card-title {
    margin-bottom: 0.08rem;
    font-size: 0.9rem;
    font-weight: 730;
}

.raw-logs-card-header small {
    color: var(--bs-secondary-color);
    font-size: 0.62rem;
}

.log-file-summary {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.35rem;
}

.log-file-summary span {
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    padding: 0.2rem 0.42rem;
    color: var(--bs-secondary-color);
    font-size: 0.58rem;
    font-weight: 650;
}

.station-log-list {
    display: grid;
}

.station-log-row {
    display: grid;
    width: 100%;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    gap: 0.7rem;
    border: 0;
    border-top: 1px solid var(--bs-border-color);
    padding: 0.72rem 0.8rem;
    background: transparent;
    color: var(--bs-body-color);
    text-align: left;
    transition: background-color 120ms ease;
}

.station-log-row:first-child { border-top: 0; }
.station-log-row:not(:disabled):hover { background: color-mix(in srgb, var(--bs-primary) 7%, var(--bs-body-bg)); }
.station-log-row:disabled { cursor: not-allowed; opacity: 0.63; }
.station-log-row--empty { background: color-mix(in srgb, var(--bs-warning) 3%, transparent); }
.station-log-row--missing { background: color-mix(in srgb, var(--bs-secondary) 4%, transparent); }

.log-state-light {
    width: 0.56rem;
    height: 0.56rem;
    border-radius: 50%;
    background: var(--bs-success);
    box-shadow: 0 0 0.5rem rgba(var(--bs-success-rgb), 0.36);
}

.log-state-light.log-state--empty { background: var(--bs-warning); box-shadow: 0 0 0.5rem rgba(var(--bs-warning-rgb), 0.3); }
.log-state-light.log-state--missing { background: var(--bs-secondary); box-shadow: none; }

.log-row-copy { display: grid; min-width: 0; gap: 0.12rem; }
.log-row-topline { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; }
.log-row-topline strong { overflow: hidden; font-size: 0.72rem; text-overflow: ellipsis; white-space: nowrap; }
.log-path { overflow: hidden; color: var(--bs-secondary-color); font-size: 0.61rem; text-overflow: ellipsis; white-space: nowrap; }
.log-meta { display: flex; flex-wrap: wrap; gap: 0.55rem; color: var(--bs-secondary-color); font-size: 0.56rem; }

.log-state-badge {
    flex: 0 0 auto;
    border-radius: 999px;
    padding: 0.18rem 0.42rem;
    background: color-mix(in srgb, var(--bs-success) 11%, transparent);
    color: var(--bs-success);
    font-size: 0.54rem;
    font-weight: 800;
    text-transform: uppercase;
}

.log-state-badge.log-state--empty { background: color-mix(in srgb, var(--bs-warning) 12%, transparent); color: var(--bs-warning-text-emphasis); }
.log-state-badge.log-state--missing { background: color-mix(in srgb, var(--bs-secondary) 12%, transparent); color: var(--bs-secondary-color); }

.raw-log-note {
    display: grid;
    gap: 0.12rem;
    border-top: 1px solid var(--bs-border-color);
    padding: 0.72rem 0.8rem;
    background: color-mix(in srgb, var(--bs-info) 5%, var(--bs-body-bg));
}

.raw-log-note strong { font-size: 0.67rem; }
.raw-log-note span { color: var(--bs-secondary-color); font-size: 0.62rem; line-height: 1.4; }

@media (max-width: 767.98px) {
    .raw-logs-card-header { align-items: flex-start; flex-direction: column; }
    .log-file-summary { justify-content: flex-start; }
}
</style>
