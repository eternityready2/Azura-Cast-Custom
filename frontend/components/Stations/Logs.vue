<template>
    <div class="row row-of-cards">
        <div class="col-md-8">
            <section class="card diagnostics-card mb-4" role="region" aria-labelledby="hdr_custom_diagnostics">
                <div class="diagnostics-header">
                    <div class="diagnostics-icon">
                        <icon-ic-troubleshoot />
                    </div>
                    <div class="flex-fill">
                        <h2 id="hdr_custom_diagnostics">{{ $gettext('Custom Feature Diagnostics') }}</h2>
                        <p>{{ $gettext('One focused log for custom station automation and scheduling issues.') }}</p>
                    </div>
                    <span class="diagnostics-badge">{{ $gettext('Station Scoped') }}</span>
                </div>
                <div class="card-body">
                    <p class="mb-3 text-body-secondary">
                        {{ $gettext('AirCheck recovery events, Clock Wheel fallbacks, Linear Log build failures and Smart Block synchronization errors are collected here without mixing them into the normal service logs.') }}
                    </p>
                    <div class="diagnostics-features mb-3">
                        <span>AirCheck</span>
                        <span>Clock Wheels</span>
                        <span>Linear Log</span>
                        <span>Smart Blocks</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!diagnosticsLog"
                            @click="viewDiagnostics"
                        >
                            <icon-ic-visibility />
                            <span>{{ $gettext('View Diagnostics') }}</span>
                        </button>
                        <a class="btn btn-outline-primary" :href="diagnosticsDownloadUrl">
                            <icon-ic-download />
                            <span>{{ $gettext('Download Diagnostics') }}</span>
                        </a>
                    </div>
                </div>
            </section>

            <section class="card" role="region" aria-labelledby="hdr_available_logs">
                <div class="card-header text-bg-primary">
                    <h2 id="hdr_available_logs" class="card-title">
                        {{ $gettext('Available Logs') }}
                    </h2>
                </div>

                <loading :loading="isLoading" lazy>
                    <log-list
                        v-if="data"
                        :logs="data"
                        @view="viewLog"
                    />
                </loading>
            </section>

            <streaming-log-modal ref="$modal" />
        </div>
        <div class="col-md-4">
            <section class="card" role="region" aria-labelledby="hdr_need_help">
                <div class="card-header text-bg-primary">
                    <h2 id="hdr_need_help" class="card-title">
                        {{ $gettext('Need Help?') }}
                    </h2>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        {{ $gettext('You can find answers for many common questions in our support documents.') }}
                    </p>
                    <p class="card-text">
                        <a href="/docs/help/troubleshooting/" target="_blank">
                            {{ $gettext('Support Documents') }}
                        </a>
                    </p>
                    <p class="card-text">
                        {{ $gettext('If you\'re experiencing a bug or error, you can submit a GitHub issue using the link below.') }}
                    </p>
                </div>
                <div class="card-body">
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
import LogList from "~/components/Common/LogList.vue";
import {computed, useTemplateRef} from "vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useQuery} from "@tanstack/vue-query";
import {ApiLogType} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import IconIcDownload from "~icons/ic/baseline-download";
import IconIcSupport from "~icons/ic/baseline-support";
import IconIcTroubleshoot from "~icons/ic/baseline-troubleshoot";
import IconIcVisibility from "~icons/ic/baseline-visibility";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const logsUrl = getStationApiUrl('/logs');
const diagnosticsDownloadUrl = getStationApiUrl('/diagnostics/download');
const {axios} = useAxios();

type ApiLogRow = Required<ApiLogType>

const {data, isLoading} = useQuery<ApiLogRow[]>({
    queryKey: queryKeyWithStation([QueryKeys.StationLogs]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<ApiLogRow[]>(logsUrl.value, {signal});
        return data;
    },
    placeholderData: () => []
});

const diagnosticsLog = computed(() => data.value?.find((log) => log.key === 'custom_diagnostics'));
const $modal = useTemplateRef('$modal');

const viewLog = (url: string, isStreaming: boolean) => {
    $modal.value?.show(url, isStreaming);
};

const viewDiagnostics = () => {
    const log = diagnosticsLog.value;
    if (log) {
        viewLog(log.links.self, log.tail);
    }
};
</script>

<style scoped>
.diagnostics-card { overflow:hidden; border:1px solid color-mix(in srgb,var(--bs-primary) 28%,var(--bs-border-color)); box-shadow:0 .4rem 1.2rem rgba(0,0,0,.08); }
.diagnostics-header { display:flex; align-items:center; gap:.9rem; padding:1rem 1.1rem; color:#fff; background:linear-gradient(100deg,#125f9d 0%,#1688d5 58%,#1e9bea 100%); }
.diagnostics-header h2 { margin:0; color:#fff; font-size:1.05rem; font-weight:750; }
.diagnostics-header p { margin:.18rem 0 0; color:rgba(255,255,255,.82); font-size:.78rem; }
.diagnostics-icon { width:2.5rem; height:2.5rem; display:grid; place-items:center; flex:0 0 auto; border-radius:.65rem; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.2); }
.diagnostics-icon :deep(svg) { width:1.3rem; height:1.3rem; }
.diagnostics-badge { padding:.34rem .55rem; border-radius:999px; background:rgba(0,0,0,.18); font-size:.66rem; font-weight:750; white-space:nowrap; text-transform:uppercase; letter-spacing:.04em; }
.diagnostics-features { display:flex; flex-wrap:wrap; gap:.45rem; }
.diagnostics-features span { padding:.28rem .5rem; border:1px solid var(--bs-border-color); border-radius:999px; background:var(--bs-tertiary-bg); color:var(--bs-secondary-color); font-size:.7rem; font-weight:650; }
@media (max-width:767.98px) { .diagnostics-header { align-items:flex-start; flex-wrap:wrap; } .diagnostics-badge { margin-left:3.4rem; } }
</style>
