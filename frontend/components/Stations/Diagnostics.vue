<template>
    <div class="row row-of-cards">
        <div class="col-xl-9">
            <section
                class="card diagnostics-card"
                role="region"
                aria-labelledby="hdr_custom_diagnostics"
            >
                <div class="diagnostics-header">
                    <div class="diagnostics-icon">
                        <icon-ic-troubleshoot />
                    </div>
                    <div class="flex-fill">
                        <h2 id="hdr_custom_diagnostics">
                            {{ $gettext('Custom Feature Diagnostics') }}
                        </h2>
                        <p>
                            {{ $gettext('Focused station diagnostics for custom automation and scheduling features.') }}
                        </p>
                    </div>
                    <span class="diagnostics-badge">
                        {{ $gettext('Station Scoped') }}
                    </span>
                </div>

                <div class="card-body diagnostics-body">
                    <p class="mb-3 text-body-secondary">
                        {{ $gettext('AirCheck recovery events, Clock Wheel fallbacks, Linear Log build failures and Smart Block synchronization errors are collected here without mixing them into the normal service logs.') }}
                    </p>

                    <div class="diagnostics-features mb-4">
                        <span>AirCheck</span>
                        <span>Clock Wheels</span>
                        <span>Linear Log</span>
                        <span>Smart Blocks</span>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="viewDiagnostics"
                        >
                            <icon-ic-visibility />
                            <span>{{ $gettext('View Diagnostics') }}</span>
                        </button>

                        <a
                            class="btn btn-outline-primary"
                            :href="diagnosticsDownloadUrl"
                        >
                            <icon-ic-download />
                            <span>{{ $gettext('Download Diagnostics') }}</span>
                        </a>
                    </div>
                </div>
            </section>

            <section
                class="card mt-4"
                role="region"
                aria-labelledby="hdr_diagnostic_sources"
            >
                <div class="card-header text-bg-primary">
                    <h2
                        id="hdr_diagnostic_sources"
                        class="card-title"
                    >
                        {{ $gettext('Diagnostic Sources') }}
                    </h2>
                </div>
                <div class="list-group list-group-flush diagnostics-source-list">
                    <div class="list-group-item">
                        <div class="fw-semibold">AirCheck</div>
                        <div class="small text-body-secondary">
                            {{ $gettext('Recovery actions, failures, and shared infrastructure down or recovered transitions.') }}
                        </div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">{{ $gettext('Clock Wheels') }}</div>
                        <div class="small text-body-secondary">
                            {{ $gettext('Clock Wheel deferrals, fallbacks, and meaningful scheduling safeguards.') }}
                        </div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">{{ $gettext('Linear Log') }}</div>
                        <div class="small text-body-secondary">
                            {{ $gettext('Linear Log build-queue failures that can affect schedule generation.') }}
                        </div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">{{ $gettext('Smart Blocks') }}</div>
                        <div class="small text-body-secondary">
                            {{ $gettext('Dynamic Smart Block synchronization failures and related station-scoped errors.') }}
                        </div>
                    </div>
                </div>
            </section>

            <streaming-log-modal ref="$modal" />
        </div>

        <div class="col-xl-3">
            <section
                class="card"
                role="region"
                aria-labelledby="hdr_diagnostics_help"
            >
                <div class="card-header text-bg-primary">
                    <h2
                        id="hdr_diagnostics_help"
                        class="card-title"
                    >
                        {{ $gettext('Using Diagnostics') }}
                    </h2>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        {{ $gettext('Use this page when a custom station feature behaves unexpectedly.') }}
                    </p>
                    <p class="card-text text-body-secondary">
                        {{ $gettext('Reproduce the issue, then open the live diagnostics viewer or download the log for a longer review.') }}
                    </p>
                    <p class="card-text text-body-secondary mb-0">
                        {{ $gettext('Station passwords are filtered from both the viewer and downloaded diagnostics.') }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import {useTemplateRef} from "vue";
import StreamingLogModal from "~/components/Common/StreamingLogModal.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import IconIcDownload from "~icons/ic/baseline-download";
import IconIcTroubleshoot from "~icons/ic/baseline-troubleshoot";
import IconIcVisibility from "~icons/ic/baseline-visibility";

const {getStationApiUrl} = useApiRouter();
const diagnosticsViewUrl = getStationApiUrl('/diagnostics');
const diagnosticsDownloadUrl = getStationApiUrl('/diagnostics/download');

const $modal = useTemplateRef('$modal');

const viewDiagnostics = () => {
    $modal.value?.show(diagnosticsViewUrl.value, true);
};
</script>

<style scoped>
.diagnostics-card {
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--bs-primary) 28%, var(--bs-border-color));
    box-shadow: 0 .4rem 1.2rem rgba(0, 0, 0, .08);
}

.diagnostics-header {
    display: flex;
    align-items: center;
    gap: .9rem;
    padding: 1rem 1.1rem;
    color: #fff;
    background: linear-gradient(100deg, #125f9d 0%, #1688d5 58%, #1e9bea 100%);
}

.diagnostics-header h2 {
    margin: 0;
    color: #fff;
    font-size: 1.05rem;
    font-weight: 750;
}

.diagnostics-header p {
    margin: .18rem 0 0;
    color: rgba(255, 255, 255, .82);
    font-size: .78rem;
}

.diagnostics-icon {
    width: 2.5rem;
    height: 2.5rem;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: .65rem;
    background: rgba(255, 255, 255, .14);
    border: 1px solid rgba(255, 255, 255, .2);
}

.diagnostics-icon :deep(svg) {
    width: 1.3rem;
    height: 1.3rem;
}

.diagnostics-badge {
    padding: .34rem .55rem;
    border-radius: 999px;
    background: rgba(0, 0, 0, .18);
    font-size: .66rem;
    font-weight: 750;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.diagnostics-body {
    padding: 1.15rem;
}

.diagnostics-features {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
}

.diagnostics-features span {
    padding: .28rem .5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
    font-size: .7rem;
    font-weight: 650;
}

.diagnostics-source-list .list-group-item {
    padding: .9rem 1rem;
}

@media (max-width: 767.98px) {
    .diagnostics-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .diagnostics-badge {
        margin-left: 3.4rem;
    }
}
</style>
