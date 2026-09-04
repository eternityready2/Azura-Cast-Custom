<template>
    <diagnostics-dashboard />

    <div class="raw-logs-heading">
        <div>
            <span>{{ $gettext('Deep Inspection') }}</span>
            <h2>{{ $gettext('Detailed Service Logs') }}</h2>
            <p>{{ $gettext('Use the raw station and service logs when you need the complete execution trail behind a diagnostic signal.') }}</p>
        </div>
    </div>

    <div class="row row-of-cards">
        <div class="col-lg-8">
            <section
                class="card raw-logs-card"
                role="region"
                aria-labelledby="hdr_available_logs"
            >
                <div class="card-header">
                    <h2
                        id="hdr_available_logs"
                        class="card-title"
                    >
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
                        {{ $gettext('If you are investigating a bug, the diagnostics dashboard and detailed logs on this page can be shared with a developer.') }}
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
import LogList from "~/components/Common/LogList.vue";
import DiagnosticsDashboard from "~/components/Stations/Logs/DiagnosticsDashboard.vue";
import {useTemplateRef} from "vue";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import {useQuery} from "@tanstack/vue-query";
import {ApiLogType} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import IconIcSupport from "~icons/ic/baseline-support";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {getStationApiUrl} = useApiRouter();
const logsUrl = getStationApiUrl('/logs');

const {axios} = useAxios();

type ApiLogRow = Required<ApiLogType>

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

const $modal = useTemplateRef('$modal');

const viewLog = (url: string, isStreaming: boolean) => {
    $modal.value?.show(url, isStreaming);
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
    max-width: 52rem;
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

.raw-logs-card .card-title,
.support-card .card-title {
    font-size: 0.9rem;
    font-weight: 730;
}
</style>
