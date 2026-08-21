<template>
    <div class="licensing-page">
        <header class="report-hero mb-4">
            <div class="report-hero-icon">
                <icon-description />
            </div>
            <div>
                <h1>{{ title }}</h1>
                <p>{{ subtitle }}</p>
            </div>
        </header>

        <section class="report-info-card mb-3">
            <div class="info-icon"><icon-info /></div>
            <div>
                <h2>{{ aboutTitle }}</h2>
                <p>{{ description }}</p>
            </div>
        </section>

        <section class="report-notes-card mb-3">
            <div class="notes-title">
                <icon-warning />
                <h2>{{ $gettext('Important Notes') }}</h2>
            </div>
            <ul>
                <li v-for="note in notes" :key="note">{{ note }}</li>
            </ul>
        </section>

        <section class="generate-card">
            <div class="generate-header">
                <div class="generate-icon"><icon-settings /></div>
                <div>
                    <h2>{{ generateTitle }}</h2>
                    <p>{{ generateSubtitle }}</p>
                </div>
            </div>

            <form class="generate-body" @submit.prevent="download">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="report_start_date" class="form-label">{{ $gettext('Start Date') }}</label>
                        <input id="report_start_date" v-model="startDate" class="form-control" type="date" required>
                    </div>
                    <div class="col-md-6">
                        <label for="report_end_date" class="form-label">{{ $gettext('End Date') }}</label>
                        <input id="report_end_date" v-model="endDate" class="form-control" type="date" required>
                    </div>

                    <div v-if="showPlaylistSelector" class="col-12">
                        <label class="form-label d-block">
                            {{ playlistLabel }}<span v-if="'ppca' === kind" class="required">*</span>
                        </label>

                        <label class="playlist-option">
                            <input
                                v-model="includeDefaultPlaylist"
                                class="form-check-input"
                                type="checkbox"
                            >
                            <span>default</span>
                        </label>

                        <small class="field-help">{{ playlistHelp }}</small>
                    </div>

                    <div v-if="showRevenue" class="col-12">
                        <label for="report_revenue" class="form-label">
                            {{ $gettext('Total Revenue for Quarter (AUD, GST excl)') }}
                        </label>
                        <input
                            id="report_revenue"
                            v-model="revenue"
                            class="form-control"
                            type="number"
                            min="0"
                            step="0.01"
                        >
                        <small class="field-help">
                            {{ $gettext('Required for Schedule B (Accounting Report). Enter your total revenue for the quarter excluding GST.') }}
                        </small>
                    </div>

                    <div v-if="'cadence' === kind" class="col-12">
                        <label class="form-label d-block">{{ $gettext('File Format') }}</label>
                        <div class="format-options">
                            <label class="format-option" :class="{'is-active': 'csv' === format}">
                                <input v-model="format" class="form-check-input" type="radio" value="csv">
                                <span>
                                    <strong>{{ $gettext('CSV') }}</strong>
                                    <small>{{ $gettext('Comma-separated .csv file') }}</small>
                                </span>
                            </label>
                            <label class="format-option" :class="{'is-active': 'txt' === format}">
                                <input v-model="format" class="form-check-input" type="radio" value="txt">
                                <span>
                                    <strong>{{ $gettext('Tab-delimited') }}</strong>
                                    <small>{{ $gettext('Tab-separated .txt file') }}</small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="generate-footer">
                    <div class="footer-note">
                        {{ $gettext('The export is generated from data currently retained by this AzuraCast installation.') }}
                    </div>
                    <div class="download-actions">
                        <button
                            v-for="button in resolvedButtons"
                            :key="button.label"
                            class="btn btn-primary report-download"
                            type="button"
                            @click="download(button.param)"
                        >
                            <icon-download class="me-1" />
                            {{ button.label }}
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, ref} from "vue";
import IconDescription from "~icons/ic/baseline-description";
import IconDownload from "~icons/ic/baseline-cloud-download";
import IconInfo from "~icons/ic/baseline-info";
import IconSettings from "~icons/ic/baseline-settings";
import IconWarning from "~icons/ic/baseline-warning";
import {useApiRouter} from "~/functions/useApiRouter";
import {useTranslate} from "~/vendor/gettext";

type DownloadButton = {
    label: string,
    param?: string
};

const props = withDefaults(defineProps<{
    kind: "ppca" | "ppl" | "cadence",
    title: string,
    subtitle: string,
    aboutTitle: string,
    description: string,
    notes: string[],
    buttonLabel?: string,
    buttons?: DownloadButton[],
    generateTitle?: string,
    generateSubtitle?: string,
    showPlaylistSelector?: boolean,
    playlistLabel?: string,
    playlistHelp?: string,
    showRevenue?: boolean,
}>(), {
    buttonLabel: "",
    buttons: () => [],
    generateTitle: "Generate Report",
    generateSubtitle: "Choose the reporting period and output format.",
    showPlaylistSelector: false,
    playlistLabel: "Music Playlists",
    playlistHelp: "",
    showRevenue: false,
});

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();

const today = new Date();
const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
const defaultStart = "cadence" === props.kind
    ? new Date(today.getFullYear(), quarterStartMonth, 1)
    : new Date(today.getFullYear(), today.getMonth(), 1);

const toDateInput = (date: Date) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
};

const startDate = ref(toDateInput(defaultStart));
const endDate = ref(toDateInput(today));
const format = ref<"csv" | "txt">("csv");
const revenue = ref("0");
const includeDefaultPlaylist = ref(true);
const apiUrl = getStationApiUrl(`/reports/${props.kind}`);

const resolvedButtons = computed<DownloadButton[]>(() => {
    if (props.buttons.length > 0) {
        return props.buttons;
    }

    return [{
        label: props.buttonLabel || $gettext("Download Report")
    }];
});

const download = (reportType?: string) => {
    const url = new URL(apiUrl.value, window.location.origin);
    url.searchParams.set("start_date", startDate.value);
    url.searchParams.set("end_date", endDate.value);

    if ("cadence" === props.kind) {
        url.searchParams.set("format", format.value);
    }

    if (reportType) {
        url.searchParams.set("schedule", reportType);
    }

    if (props.showRevenue) {
        url.searchParams.set("revenue", revenue.value);
    }

    if (props.showPlaylistSelector) {
        url.searchParams.set(
            "default_playlist",
            includeDefaultPlaylist.value ? "1" : "0"
        );
    }

    window.location.assign(url.toString());
};
</script>

<style scoped>
.licensing-page {
    max-width: 1000px;
    margin: 0 auto;
    color: var(--bs-body-color);
}

.report-hero {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem 1.35rem;
    border: 1px solid color-mix(in srgb, var(--bs-primary) 38%, var(--bs-border-color));
    border-radius: .95rem;
    background: linear-gradient(90deg, #0a6fc2 0%, #2196f3 100%);
    color: #fff;
    box-shadow: 0 .55rem 1.5rem rgba(16, 24, 40, .18);
}

.report-hero-icon,
.generate-icon {
    width: 2.8rem;
    height: 2.8rem;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: .7rem;
    background: rgba(255, 255, 255, .16);
    border: 1px solid rgba(255, 255, 255, .22);
}

.report-hero-icon :deep(svg),
.generate-icon :deep(svg),
.info-icon :deep(svg),
.notes-title :deep(svg),
.report-download :deep(svg) {
    width: 1.25rem;
    height: 1.25rem;
}

.report-hero h1 {
    margin: 0;
    font-size: 1.55rem;
    font-weight: 750;
    color: #fff;
}

.report-hero p {
    margin: .25rem 0 0;
    color: rgba(255, 255, 255, .9);
    font-size: .92rem;
}

.report-info-card,
.report-notes-card,
.generate-card {
    border: 1px solid var(--bs-border-color);
    border-radius: .9rem;
    background: color-mix(in srgb, var(--bs-body-bg) 94%, var(--bs-secondary-bg) 6%);
    color: var(--bs-body-color);
    box-shadow: 0 .25rem .9rem rgba(0, 0, 0, .06);
}

.report-info-card {
    display: flex;
    gap: 1rem;
    padding: 1.1rem 1.2rem;
    border-left: 4px solid var(--bs-info);
}

.info-icon {
    color: var(--bs-info);
    flex: 0 0 auto;
    padding-top: .05rem;
}

.report-info-card h2,
.report-notes-card h2,
.generate-card h2 {
    margin: 0;
    color: var(--bs-body-color);
    font-size: 1rem;
    font-weight: 750;
}

.report-info-card p,
.generate-header p,
.footer-note,
.format-option small {
    color: color-mix(in srgb, var(--bs-body-color) 78%, transparent);
}

.report-info-card p {
    margin: .3rem 0 0;
    line-height: 1.55;
    font-size: .88rem;
}

.report-notes-card {
    padding: 1.1rem 1.2rem;
    border-left: 4px solid var(--bs-warning);
}

.notes-title {
    display: flex;
    align-items: center;
    gap: .55rem;
    color: var(--bs-warning);
}

.report-notes-card ul {
    margin: .85rem 0 0;
    padding-left: 1.2rem;
}

.report-notes-card li {
    margin: .5rem 0;
    color: color-mix(in srgb, var(--bs-body-color) 88%, transparent);
    line-height: 1.5;
    font-size: .86rem;
}

.generate-card {
    overflow: hidden;
}

.generate-header {
    display: flex;
    align-items: center;
    gap: .9rem;
    padding: 1.05rem 1.2rem;
    border-bottom: 1px solid var(--bs-border-color);
    background: linear-gradient(90deg, color-mix(in srgb, var(--bs-primary) 16%, var(--bs-body-bg)), color-mix(in srgb, var(--bs-primary) 7%, var(--bs-body-bg)));
}

.generate-icon {
    background: linear-gradient(135deg, #0a6fc2, #2196f3);
    color: #fff;
}

.generate-header p {
    margin: .2rem 0 0;
    font-size: .84rem;
}

.generate-body {
    padding: 1.25rem 1.25rem 0;
}

.form-label {
    color: var(--bs-body-color);
    font-weight: 650;
}

.format-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .8rem;
}

.format-option {
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: .85rem 1rem;
    margin: 0;
    border: 1px solid var(--bs-border-color);
    border-radius: .7rem;
    background: var(--bs-body-bg);
    cursor: pointer;
}

.format-option.is-active {
    border-color: var(--bs-primary);
    box-shadow: 0 0 0 .12rem color-mix(in srgb, var(--bs-primary) 20%, transparent);
}

.format-option strong,
.format-option small {
    display: block;
}

.format-option strong {
    color: var(--bs-body-color);
}

.generate-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin: 1.25rem -1.25rem 0;
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--bs-border-color);
    background: color-mix(in srgb, var(--bs-secondary-bg) 60%, var(--bs-body-bg));
}

.footer-note {
    max-width: 620px;
    font-size: .8rem;
}

.report-download {
    min-width: 190px;
    background: linear-gradient(90deg, #0a6fc2, #2196f3);
    border-color: transparent;
}

@media (max-width: 767px) {
    .format-options { grid-template-columns: 1fr; }
    .generate-footer { align-items: stretch; flex-direction: column; }
    .report-download { width: 100%; }
}

.required {
    color: var(--bs-danger);
}

.playlist-option {
    display: inline-flex;
    align-items: center;
    gap: .55rem;
    margin: 0 0 .35rem;
    cursor: pointer;
}

.playlist-option .form-check-input {
    margin: 0;
}

.field-help {
    display: block;
    margin-top: .25rem;
    color: var(--bs-secondary-color);
}

.download-actions {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    justify-content: flex-end;
    gap: .5rem;
    width: 100%;
}

.download-actions .btn {
    flex: 0 0 auto;
    white-space: nowrap;
}

@media (max-width: 991.98px) {
    .download-actions {
        flex-wrap: wrap;
    }
}

@media (max-width: 767.98px) {
    .download-actions {
        justify-content: stretch;
    }

    .download-actions .btn {
        flex: 1 1 100%;
    }
}

</style>
