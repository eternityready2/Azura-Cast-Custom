<template>
    <form
        class="ai-news-page"
        @submit.prevent="saveChanges"
    >
        <section
            class="ai-news-shell"
            role="region"
            aria-labelledby="hdr_ai_news"
        >
            <header class="ai-news-topbar">
                <div class="ai-news-branding">
                    <span class="logo-dot" />
                    <div>
                        <h2
                            id="hdr_ai_news"
                            class="ai-news-title"
                        >
                            {{ $gettext('Radio Newscaster') }}
                        </h2>
                        <p class="ai-news-subtitle mb-0">
                            {{ $gettext('AI News Bulletin Admin Panel') }}
                        </p>
                    </div>
                </div>

                <div
                    class="live-badge"
                    :class="liveBadgeClass"
                >
                    <span class="live-dot" />
                    {{ liveBadgeText }}
                </div>
            </header>

            <loading :loading="isLoading" lazy>
                <div class="ai-news-container">
                    <div class="reference-note">
                        <p class="mb-2">
                            {{ $gettext('This page mirrors the client dashboard while staying connected to the current AzuraCast AI News APIs.') }}
                        </p>
                        <p class="mb-0">
                            {{ $gettext('Active hours format: HH:MM-HH:MM. Leave blank to run all day. Source URLs should be one per line.') }}
                        </p>
                    </div>

                    <section class="dashboard-card status-strip">
                        <div class="dashboard-card-title">
                            {{ $gettext('System Status') }}
                        </div>

                        <div class="status-grid">
                            <div
                                v-for="item in statusCards"
                                :key="item.label"
                                class="stat-card"
                            >
                                <div class="stat-label">
                                    {{ item.label }}
                                </div>
                                <div
                                    class="stat-value"
                                    :class="item.tone"
                                >
                                    {{ item.value }}
                                </div>
                                <div
                                    v-if="item.helper"
                                    class="stat-helper"
                                >
                                    {{ item.helper }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="content-grid">
                        <div class="left-column">
                            <section class="dashboard-card">
                                <div class="dashboard-card-title">
                                    {{ $gettext('Generate Bulletin') }}
                                </div>

                                <div class="generate-area">
                                    <button
                                        type="button"
                                        class="big-btn"
                                        :disabled="isGenerateDisabled"
                                        @click="runTest"
                                    >
                                        <span
                                            v-if="isTesting"
                                            class="spinner"
                                        />
                                        <span v-else>{{ generateButtonIcon }}</span>
                                        <span>{{ generateButtonText }}</span>
                                    </button>
                                    <div
                                        v-if="generateHelpText"
                                        class="generate-help"
                                    >
                                        {{ generateHelpText }}
                                    </div>
                                </div>

                                <div class="audio-section show">
                                    <div class="player-box">
                                        <div class="player-title">
                                            {{ $gettext('Latest Bulletin') }}
                                        </div>
                                        <div class="audio-placeholder">
                                            {{ latestBulletinText }}
                                        </div>
                                        <audio
                                            v-if="audioAvailable && bulletinPlaybackUrl"
                                            :key="bulletinPlaybackUrl"
                                            class="bulletin-player"
                                            controls
                                            preload="metadata"
                                            :src="bulletinPlaybackUrl"
                                        />
                                        <div
                                            v-if="audioAvailable && bulletinPlaybackUrl"
                                            class="audio-link-row"
                                        >
                                            <a
                                                :href="bulletinPlaybackUrl"
                                                class="btn btn-secondary btn-sm"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {{ $gettext('Open Audio') }}
                                            </a>
                                            <a
                                                :href="bulletinPlaybackUrl"
                                                class="btn btn-secondary btn-sm"
                                                download="news_bulletin.mp3"
                                            >
                                                {{ $gettext('Download MP3') }}
                                            </a>
                                        </div>
                                        <div class="meta-row">
                                            <div class="meta-item">
                                                {{ metaStoriesText }}
                                            </div>
                                            <div class="meta-item">
                                                {{ metaSourcesText }}
                                            </div>
                                            <div class="meta-item">
                                                {{ metaTimeText }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="log-section">
                                    <div class="dashboard-card-title">
                                        {{ $gettext('Generation Log') }}
                                    </div>
                                    <div class="log-box">
                                        <div
                                            v-for="entry in logEntries"
                                            :key="entry.id"
                                            class="log-line"
                                            :class="entry.type"
                                        >
                                            [{{ entry.time }}] {{ entry.message }}
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="dashboard-card">
                                <div class="headlines-title-row">
                                    <div class="dashboard-card-title mb-0">
                                        {{ $gettext('Live Headlines Preview') }}
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm"
                                        @click="refreshHeadlinePreview"
                                    >
                                        {{ $gettext('Refresh') }}
                                    </button>
                                </div>

                                <div class="headline-list-wrap">
                                    <ul class="headline-list">
                                        <li
                                            v-for="headline in headlinePreviewItems"
                                            :key="headline.id"
                                            class="headline-item"
                                        >
                                            <span
                                                class="src-tag"
                                                :class="headline.tone"
                                            >
                                                {{ headline.source }}
                                            </span>
                                            <div>
                                                <div class="hl-title">
                                                    {{ headline.title }}
                                                </div>
                                                <div class="hl-summary">
                                                    {{ headline.summary }}
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </section>
                        </div>

                        <section class="dashboard-card settings-card settings-card-plain">
                            <div class="dashboard-card-title">
                                {{ $gettext('Settings') }}
                            </div>

                            <div class="toggle-row">
                                <div>
                                    <div class="toggle-label">
                                        {{ $gettext('Bulletin Enabled') }}
                                    </div>
                                    <div class="toggle-helper">
                                        {{ enabledDescription }}
                                    </div>
                                </div>

                                <label class="toggle">
                                    <input
                                        v-model="form.ai_news_enabled"
                                        type="checkbox"
                                    >
                                    <span class="slider" />
                                </label>
                            </div>

                            <form-group-field
                                id="edit_ai_news_reporter_name"
                                :field="r$.ai_news_reporter_name"
                            >
                                <template #label>
                                    {{ $gettext('AI Reporter Name') }}
                                </template>
                                <template #default="{id, model}">
                                    <input
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        type="text"
                                        :placeholder="$gettext('AzuraCast News Desk')"
                                    >
                                </template>
                                <template #description>
                                    {{ $gettext('Optional presenter line read before the bulletin intro.') }}
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="edit_ai_news_intro"
                                :field="r$.ai_news_intro"
                            >
                                <template #label>
                                    {{ $gettext('Intro Script') }}
                                    <span class="label-helper">{{ $gettext('(read at start of every bulletin)') }}</span>
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="4"
                                    />
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="edit_ai_news_outro"
                                :field="r$.ai_news_outro"
                            >
                                <template #label>
                                    {{ $gettext('Outro Script') }}
                                    <span class="label-helper">{{ $gettext('(read at end of every bulletin)') }}</span>
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="3"
                                    />
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="edit_ai_news_voice_model_path"
                                :field="r$.ai_news_voice_model_path"
                            >
                                <template #label>
                                    {{ $gettext('AI Voice') }}
                                </template>
                                <template #default="{model}">
                                    <form-select
                                        v-model="model.$model"
                                        class="form-control-dark"
                                        :options="voiceSelectOptions"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('Choose an installed Piper voice model. Add more voices by downloading additional Piper models onto the server.') }}
                                </template>
                            </form-group-field>

                            <form-group-field
                                id="edit_ai_news_story_count"
                                :field="r$.ai_news_story_count"
                            >
                                <template #label>
                                    {{ $gettext('Stories Per Bulletin') }}
                                </template>
                                <template #default="{id, model}">
                                    <input
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        type="number"
                                        min="1"
                                        max="25"
                                    >
                                </template>
                                <template #description>
                                    {{ $gettext('How many headlines to include in each generated bulletin. Range: 1-25.') }}
                                </template>
                            </form-group-field>

                            <div class="settings-group settings-group-tight">
                                <label>{{ $gettext('Broadcast Window') }}</label>
                                <div class="time-row">
                                    <div class="time-field">
                                        <label class="time-field-label">{{ $gettext('Start Time') }}</label>
                                        <vue-date-picker
                                            v-model="activeHoursStartPicker"
                                            v-bind="timePickerOptions"
                                            class="ai-news-time-picker"
                                        />
                                    </div>
                                    <div class="time-field">
                                        <label class="time-field-label">{{ $gettext('End Time') }}</label>
                                        <vue-date-picker
                                            v-model="activeHoursEndPicker"
                                            v-bind="timePickerOptions"
                                            class="ai-news-time-picker"
                                        />
                                    </div>
                                </div>
                                <div class="broadcast-slots">
                                    <label class="broadcast-slot-option">
                                        <input
                                            v-model="form.ai_news_top_of_hour"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <span>{{ $gettext('Top of hour') }}</span>
                                    </label>
                                    <label class="broadcast-slot-option">
                                        <input
                                            v-model="form.ai_news_bottom_of_hour"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <span>{{ $gettext('Bottom of hour') }}</span>
                                    </label>
                                </div>
                                <div class="field-note">
                                    {{ $gettext('Stored as a single HH:MM-HH:MM range in the current AzuraCast API.') }}
                                </div>
                                <div class="field-note">
                                    {{ $gettext('Top of hour runs at xx:00. Bottom of hour runs at xx:30. Select one or both options.') }}
                                </div>
                            </div>

                            <form-group-field
                                id="edit_ai_news_source_urls"
                                :field="r$.ai_news_source_urls"
                            >
                                <template #label>
                                    {{ $gettext('RSS/Atom Feed Sources') }}
                                </template>
                                <template #default="{id, model}">
                                    <textarea
                                        :id="id"
                                        v-model="model.$model"
                                        class="form-control form-control-dark"
                                        rows="5"
                                    />
                                </template>
                                <template #description>
                                    {{ $gettext('One RSS or Atom feed URL per line. Unsupported or non-feed URLs are skipped during generation unless a backend scraper is added for them.') }}
                                </template>
                            </form-group-field>

                            <div class="settings-group settings-group-tight">
                                <div class="source-list">
                                    <div
                                        v-for="source in fixedSources"
                                        :key="source.key"
                                        class="source-card"
                                        :class="{active: source.active}"
                                    >
                                        <div class="source-card-head">
                                            <span class="source-card-label">{{ source.label }}</span>
                                            <span class="source-card-status" :class="`status-${source.status}`">{{ sourceStatusLabel(source.status) }}</span>
                                        </div>
                                        <div class="source-card-url">
                                            {{ source.url }}
                                        </div>
                                        <div class="source-card-meta">
                                            {{ source.message }}
                                        </div>
                                        <div v-if="source.headlineCount > 0" class="source-card-count">
                                            {{ $gettext('Headlines fetched: ') + source.headlineCount }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="btn-row">
                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                >
                                    {{ $gettext('Save Settings') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="isLoading"
                                    @click="relist"
                                >
                                    {{ $gettext('Reset') }}
                                </button>
                            </div>

                            <div class="save-status">
                                {{ saveStatusText }}
                            </div>
                        </section>
                    </div>
                </div>
            </loading>
        </section>
    </form>
</template>

<script setup lang="ts">
import {RootProps, VueDatePicker} from "@vuepic/vue-datepicker";
import {computed, onMounted, onUnmounted, ref} from "vue";
import {useGettext} from "vue3-gettext";
import {DateTimeMaybeValid} from "luxon";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormSelect from "~/components/Form/FormSelect.vue";
import Loading from "~/components/Common/Loading.vue";
import mergeExisting from "~/functions/mergeExisting";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useMayNeedRestart} from "~/functions/useMayNeedRestart";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAppRegle} from "~/vendor/regle.ts";
import {ApiStatus} from "~/entities/ApiInterfaces.ts";
import {useLuxon} from "~/vendor/luxon.ts";

interface AiNewsForm {
    ai_news_enabled: boolean;
    ai_news_intro: string | null;
    ai_news_reporter_name: string | null;
    ai_news_source_urls: string | null;
    ai_news_story_count: number;
    ai_news_active_hours: string | null;
    ai_news_top_of_hour: boolean;
    ai_news_bottom_of_hour: boolean;
    ai_news_voice_model_path: string | null;
    ai_news_outro: string | null;
}

interface AiNewsStatusPayload {
    ai_news_last_generation_status?: string | null;
    ai_news_last_generation_time?: string | null;
    ai_news_last_error?: string | null;
}

interface AiNewsHeadlinePreviewItem {
    title: string;
    description: string;
    source_url?: string;
}

interface AiNewsSourceResult {
    url: string;
    status: string;
    message: string;
    headline_count: number;
}

interface AiNewsVoiceOption {
    label: string;
    path: string;
}

interface AiNewsTimeValue {
    hours: number;
    minutes: number;
    seconds?: number;
}

interface AiNewsDashboardPayload {
    latest_bulletin?: {
        generated_at?: string | null;
        story_count?: number | null;
        source_urls?: string[];
        source_results?: AiNewsSourceResult[];
        elapsed_seconds?: number | null;
        output_filename?: string | null;
        headline_preview?: AiNewsHeadlinePreviewItem[];
    };
    file_info?: {
        exists: boolean;
        size?: number;
        modified_at?: string | null;
    } | null;
    next_bulletin_time?: string | null;
    current_time_station?: string | null;
    tts_engine?: string | null;
    audio_available?: boolean;
    bulletin_url?: string | null;
}

interface AiNewsTestResponse extends ApiStatus, AiNewsStatusPayload {
    dashboard?: AiNewsDashboardPayload;
}

interface AiNewsResponse extends AiNewsForm, AiNewsStatusPayload {
    dashboard?: AiNewsDashboardPayload;
    voice_options?: AiNewsVoiceOption[];
}

interface LogEntry {
    id: number;
    time: string;
    message: string;
    type: string;
}

const {getStationApiUrl} = useApiRouter();
const apiUrl = getStationApiUrl('/ai-news');
const testUrl = getStationApiUrl('/ai-news/test');

const isLoading = ref(true);
const isTesting = ref(false);
const saveStatusText = ref('');
const logCounter = ref(0);
const logEntries = ref<LogEntry[]>([]);

const lastStatus = ref<string | null>(null);
const lastTime = ref<string | null>(null);
const lastError = ref<string | null>(null);
const dashboard = ref<AiNewsDashboardPayload | null>(null);
const voiceOptions = ref<AiNewsVoiceOption[]>([]);
const browserNow = ref<DateTimeMaybeValid | null>(null);

const {record: form, reset: resetForm} = useResettableRef<AiNewsForm>(() => ({
    ai_news_enabled: false,
    ai_news_intro: null,
    ai_news_reporter_name: null,
    ai_news_source_urls: null,
    ai_news_story_count: 10,
    ai_news_active_hours: null,
    ai_news_top_of_hour: true,
    ai_news_bottom_of_hour: false,
    ai_news_voice_model_path: null,
    ai_news_outro: null
}));

const {r$} = useAppRegle(form, {}, {});

const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();
const {mayNeedRestart} = useMayNeedRestart();
const {$gettext} = useGettext();
const {DateTime, Duration} = useLuxon();

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const displayDateTimeFormat = {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true
} as const;
const displayTimeFormat = {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true
} as const;

const formatBrowserDateTime = (value: string | null | undefined, fallback = '—') => {
    if (!value) {
        return fallback;
    }

    const parsed = DateTime.fromISO(value, {setZone: true});
    if (!parsed.isValid) {
        return fallback;
    }

    return parsed.setZone(browserTimezone).toLocaleString(displayDateTimeFormat);
};

const formatBrowserTime = (value: string | null | undefined, fallback = '—') => {
    if (!value) {
        return fallback;
    }

    const parsed = DateTime.fromISO(value, {setZone: true});
    if (!parsed.isValid) {
        return fallback;
    }

    return parsed.setZone(browserTimezone).toLocaleString(displayTimeFormat);
};

const formatBrowserNow = (value: DateTimeMaybeValid | null, fallback = '—') => {
    if (!value || !value.isValid) {
        return fallback;
    }

    return value.toLocaleString(displayDateTimeFormat);
};

const formatRelativeDuration = (targetIso: string | null | undefined) => {
    if (!targetIso || !browserNow.value?.isValid) {
        return '—';
    }

    const target = DateTime.fromISO(targetIso, {setZone: true}).setZone(browserTimezone);
    if (!target.isValid) {
        return '—';
    }

    const diffMillis = target.toMillis() - browserNow.value.toMillis();
    if (diffMillis <= 0) {
        return $gettext('Due now');
    }

    const duration = Duration.fromMillis(diffMillis).shiftTo('hours', 'minutes', 'seconds').normalize();
    const hours = Math.floor(duration.hours);
    const minutes = Math.floor(duration.minutes);
    const seconds = Math.floor(duration.seconds);
    const parts: string[] = [];

    if (hours > 0) {
        parts.push(`${hours}h`);
    }

    if (minutes > 0 || hours > 0) {
        parts.push(`${minutes}m`);
    }

    parts.push(`${seconds}s`);

    return parts.join(' ');
};

const statusText = computed(() => lastStatus.value ?? '—');
const timeText = computed(() => lastTime.value ?? '—');
const latestBulletin = computed(() => dashboard.value?.latest_bulletin ?? null);
const audioAvailable = computed(() => dashboard.value?.audio_available ?? false);
const bulletinUrl = computed(() => dashboard.value?.bulletin_url ?? null);
const bulletinPlaybackUrl = computed(() => {
    if (!bulletinUrl.value) {
        return null;
    }

    const version = latestBulletin.value?.generated_at ?? dashboard.value?.file_info?.modified_at ?? null;
    if (!version) {
        return bulletinUrl.value;
    }

    const separator = bulletinUrl.value.includes('?') ? '&' : '?';
    return `${bulletinUrl.value}${separator}v=${encodeURIComponent(version)}`;
});
const dashboardCurrentTime = computed(() => dashboard.value?.current_time_station ?? null);
const dashboardNextBulletinTime = computed(() => dashboard.value?.next_bulletin_time ?? null);
const dashboardTtsEngine = computed(() => dashboard.value?.tts_engine ?? null);
const voiceSelectOptions = computed(() => {
    const options = voiceOptions.value.map((voice) => ({
        text: voice.label,
        value: voice.path,
    }));

    if (!options.some((option) => option.value === form.value.ai_news_voice_model_path) && form.value.ai_news_voice_model_path) {
        options.push({
            text: $gettext('Custom Voice Path'),
            value: form.value.ai_news_voice_model_path,
        });
    }

    return options;
});
const sourceCatalog = [
    {
        label: $gettext('Worthy News'),
        url: 'https://worthynews.com/feed/',
        tone: 'src-worthy'
    },
    {
        label: $gettext('Rapture Ready'),
        url: 'https://www.raptureready.com/category/rapture-ready-news/feed/',
        tone: 'src-rapture'
    },
    {
        label: $gettext('BBC World'),
        url: 'https://feeds.bbci.co.uk/news/world/rss.xml',
        tone: 'src-bbc'
    }
] as const;

const activeHoursParts = computed(() => {
    const value = form.value.ai_news_active_hours?.trim() ?? '';
    const [start = '', end = ''] = value.split('-');

    return {
        start,
        end
    };
});

const activeHoursStart = computed(() => activeHoursParts.value.start);
const activeHoursEnd = computed(() => activeHoursParts.value.end);

const timePickerOptions = computed<Partial<RootProps>>(() => ({
    timePicker: true,
    autoApply: true,
    closeOnAutoApply: true,
    textInput: false,
    placeholder: $gettext('Select time'),
    dark: true,
    ui: {
        input: 'form-control form-control-dark'
    },
    timeConfig: {
        is24: false,
        minutesIncrement: 5,
        secondsIncrement: 1,
        enableSeconds: false,
    }
}));

const timeStringToPickerValue = (value: string): AiNewsTimeValue | null => {
    if (!value) {
        return null;
    }

    const parsed = DateTime.fromFormat(value, 'HH:mm');
    if (!parsed.isValid) {
        return null;
    }

    return {
        hours: parsed.hour,
        minutes: parsed.minute,
        seconds: 0,
    };
};

const pickerValueToTimeString = (value: AiNewsTimeValue | null) => {
    if (!value) {
        return '';
    }

    return DateTime.fromObject({
        hour: value.hours,
        minute: value.minutes,
        second: value.seconds ?? 0,
    }).toFormat('HH:mm');
};

const activeHoursStartPicker = computed<AiNewsTimeValue | null>({
    get: () => timeStringToPickerValue(activeHoursStart.value),
    set: (value) => {
        updateActiveHours(pickerValueToTimeString(value), activeHoursEnd.value);
    }
});

const activeHoursEndPicker = computed<AiNewsTimeValue | null>({
    get: () => timeStringToPickerValue(activeHoursEnd.value),
    set: (value) => {
        updateActiveHours(activeHoursStart.value, pickerValueToTimeString(value));
    }
});

const hasBroadcastSlotSelected = computed(() => form.value.ai_news_top_of_hour || form.value.ai_news_bottom_of_hour);
const broadcastSlotLabels = computed(() => {
    const labels: string[] = [];

    if (form.value.ai_news_top_of_hour) {
        labels.push($gettext('Top of hour'));
    }

    if (form.value.ai_news_bottom_of_hour) {
        labels.push($gettext('Bottom of hour'));
    }

    return labels;
});

const liveBadgeClass = computed(() => {
    return form.value.ai_news_enabled ? 'is-live' : 'is-off';
});

const liveBadgeText = computed(() => {
    return form.value.ai_news_enabled
        ? $gettext('Streaming Live')
        : $gettext('Bulletin Disabled');
});

const enabledDescription = computed(() => {
    return form.value.ai_news_enabled
        ? $gettext('The generator is allowed to run during the configured window.')
        : $gettext('Generation is disabled until you re-enable the bulletin.');
});

const statusTone = computed(() => {
    const value = lastStatus.value?.toLowerCase();

    if (value === 'completed') {
        return 'tone-green';
    }

    if (value === 'error') {
        return 'tone-red';
    }

    if (form.value.ai_news_enabled) {
        return 'tone-yellow';
    }

    return 'tone-muted';
});

const scheduleText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return $gettext('OFF');
    }

    const activeWindow = form.value.ai_news_active_hours?.trim() || $gettext('All Day');
    const slotSummary = broadcastSlotLabels.value.join(', ') || $gettext('No slots selected');

    return `${activeWindow} • ${slotSummary}`;
});

const nextBulletinText = computed(() => {
    if (!form.value.ai_news_enabled) {
        return '—';
    }

    if (dashboardNextBulletinTime.value) {
        const datetime = formatBrowserDateTime(dashboardNextBulletinTime.value);
        const remaining = formatRelativeDuration(dashboardNextBulletinTime.value);
        return remaining && remaining !== '—'
            ? `${datetime}\n${remaining}`
            : datetime;
    }

    return form.value.ai_news_active_hours?.trim() ?? $gettext('Within active window');
});

const currentTimeText = computed(() => {
    if (dashboardCurrentTime.value) {
        return formatBrowserNow(browserNow.value);
    }

    return formatBrowserNow(browserNow.value);
});

const ttsEngineText = computed(() => {
    return dashboardTtsEngine.value ?? form.value.ai_news_voice_model_path?.trim() ?? $gettext('Default voice model');
});

const latestBulletinText = computed(() => {
    if (audioAvailable.value && bulletinUrl.value) {
        return $gettext('Latest bulletin audio is ready. Use the generated bulletin endpoint to play or download it.');
    }

    if (latestBulletin.value?.generated_at) {
        return $gettext('The latest successful bulletin was generated at: ') + formatBrowserDateTime(latestBulletin.value.generated_at);
    }

    if (lastStatus.value === 'error' && lastError.value) {
        return $gettext('Latest generation failed: ') + lastError.value;
    }

    return $gettext('No bulletin audio has been generated yet.');
});

const fixedSources = computed(() => {
    const configuredUrls = (form.value.ai_news_source_urls ?? '')
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
    const sourceResults = latestBulletin.value?.source_results ?? [];
    const resultMap = new Map(sourceResults.map((result) => [result.url, result]));
    const activeUrls = latestBulletin.value?.source_urls ?? configuredUrls;
    const knownSources = sourceCatalog.map((source) => {
        const result = resultMap.get(source.url);

        return {
            key: source.url,
            label: source.label,
            url: source.url,
            active: activeUrls.includes(source.url),
            status: result?.status ?? 'idle',
            message: result?.message ?? $gettext('No fetch attempt recorded yet.'),
            headlineCount: result?.headline_count ?? 0,
        };
    });
    const customSources = configuredUrls
        .filter((url) => !sourceCatalog.some((source) => source.url === url))
        .map((url, index) => {
            const result = resultMap.get(url);

            return {
                key: `custom-${index}-${url}`,
                label: $gettext('Custom RSS/Atom Feed'),
                url,
                active: activeUrls.includes(url),
                status: result?.status ?? 'idle',
                message: result?.message ?? $gettext('No fetch attempt recorded yet.'),
                headlineCount: result?.headline_count ?? 0,
            };
        });

    return [...knownSources, ...customSources];
});

const metaStoriesText = computed(() => {
    const storyCount = latestBulletin.value?.story_count;

    return (typeof storyCount === 'number')
        ? $gettext('Stories: ') + storyCount
        : $gettext('Stories: not available yet');
});
const metaSourcesText = computed(() => {
    const activeSources = latestBulletin.value?.source_urls ?? [];
    const sourceLabels = activeSources.map((url) => {
        return sourceCatalog.find((source) => source.url === url)?.label ?? url;
    });

    return sourceLabels.length > 0
        ? $gettext('Sources: ') + sourceLabels.join(', ')
        : $gettext('Sources: none configured');
});
const metaTimeText = computed(() => {
    const elapsedSeconds = latestBulletin.value?.elapsed_seconds;

    return (typeof elapsedSeconds === 'number')
        ? $gettext('Generated in ') + elapsedSeconds + 's'
        : $gettext('Generation timing unavailable');
});

const headlinePreviewItems = computed(() => {
    const previewItems = latestBulletin.value?.headline_preview ?? [];

    if (previewItems.length === 0) {
        return [
            {
                id: 'empty',
                source: $gettext('Info'),
                title: $gettext('No headline preview available yet.'),
                summary: $gettext('Run a test bulletin to fetch stories and populate the live preview panel.'),
                tone: 'src-info'
            }
        ];
    }

    return previewItems.map((item, index) => {
        const source = sourceCatalog.find((sourceItem) => sourceItem.url === item.source_url) ?? null;

        return {
            id: `${index}-${item.title}`,
            source: source?.label ?? $gettext('Feed'),
            title: item.title,
            summary: item.description || $gettext('No summary available for this story.'),
            tone: source?.tone ?? 'src-info'
        };
    });
});

const statusCards = computed(() => {
    return [
        {
            label: $gettext('Bulletin Schedule'),
            value: scheduleText.value,
            helper: '',
            tone: form.value.ai_news_enabled ? 'tone-green' : 'tone-red'
        },
        {
            label: $gettext('Next Bulletin'),
            value: nextBulletinText.value,
            helper: dashboardNextBulletinTime.value ? browserTimezone : '',
            tone: 'tone-yellow'
        },
        {
            label: $gettext('Last Generated'),
            value: latestBulletin.value?.generated_at ? formatBrowserDateTime(latestBulletin.value.generated_at) : (timeText.value === '—' ? $gettext('Never') : formatBrowserDateTime(timeText.value, timeText.value)),
            helper: latestBulletin.value?.generated_at ? browserTimezone : '',
            tone: 'tone-blue'
        },
        {
            label: $gettext('Current Time'),
            value: currentTimeText.value,
            helper: browserTimezone,
            tone: 'tone-default'
        },
        {
            label: $gettext('Stream Output'),
            value: audioAvailable.value ? $gettext('Latest bulletin ready') : statusText.value,
            helper: '',
            tone: audioAvailable.value ? 'tone-green' : statusTone.value
        },
        {
            label: $gettext('TTS Engine'),
            value: ttsEngineText.value,
            helper: '',
            tone: 'tone-blue'
        }
    ];
});

const generateButtonIcon = computed(() => form.value.ai_news_enabled ? '▶' : '■');
const isGenerateDisabled = computed(() => isTesting.value || !form.value.ai_news_enabled);
const generateButtonText = computed(() => {
    if (isTesting.value) {
        return $gettext('Generating...');
    }

    if (!form.value.ai_news_enabled) {
        return $gettext('Generation Disabled');
    }

    return $gettext('Generate Now');
});
const generateHelpText = computed(() => {
    return form.value.ai_news_enabled
        ? ''
        : $gettext('Re-enable the bulletin before running a manual generation test.');
});

const sourceStatusLabel = (status: string) => {
    switch (status) {
        case 'ok':
            return $gettext('Fetched');
        case 'empty':
            return $gettext('Empty');
        case 'skipped':
            return $gettext('Skipped');
        default:
            return $gettext('Standby');
    }
};

const appendLog = (message: string, type = 'log-info') => {
    const timestamp = browserNow.value?.isValid
        ? browserNow.value.toLocaleString(displayTimeFormat)
        : DateTime.now().setZone(browserTimezone).toLocaleString(displayTimeFormat);

    logCounter.value += 1;
    logEntries.value = [
        ...logEntries.value,
        {
            id: logCounter.value,
            time: timestamp,
            message,
            type
        }
    ];
};

const appendSourceResultsToLog = (sourceResults: AiNewsSourceResult[] = []) => {
    sourceResults.forEach((result) => {
        const label = sourceStatusLabel(result.status);
        const headlineSuffix = result.headline_count > 0
            ? $gettext(' Headlines fetched: ') + result.headline_count
            : '';
        const type = result.status === 'ok'
            ? 'log-ok'
            : (result.status === 'skipped' ? 'log-err' : 'log-info');

        appendLog(`[${label}] ${result.url} - ${result.message}${headlineSuffix}`, type);
    });
};

const setInitialLogs = () => {
    logEntries.value = [];
    appendLog($gettext('Ready. Click "Generate Now" to produce a bulletin with the current station settings.'), 'log-info');
};

const hydrateFromResponse = (data: AiNewsResponse) => {
    resetForm();
    r$.$reset();
    form.value = mergeExisting(form.value, data);

    lastStatus.value = data.ai_news_last_generation_status ?? null;
    lastTime.value = data.ai_news_last_generation_time ?? null;
    lastError.value = data.ai_news_last_error ?? null;
    dashboard.value = data.dashboard ?? null;
    voiceOptions.value = data.voice_options ?? [];

    setInitialLogs();

    if (latestBulletin.value?.generated_at) {
        appendLog($gettext('Latest bulletin completed successfully at ') + formatBrowserDateTime(latestBulletin.value.generated_at), 'log-ok');
    } else if (lastStatus.value === 'error' && lastError.value) {
        appendLog($gettext('Latest bulletin failed: ') + lastError.value, 'log-err');
    }

    appendSourceResultsToLog(data.dashboard?.latest_bulletin?.source_results ?? []);
};

const relist = async () => {
    isLoading.value = true;

    try {
        const {data} = await axios.get<AiNewsResponse>(apiUrl.value);
        hydrateFromResponse(data);
        saveStatusText.value = '';
    } finally {
        isLoading.value = false;
    }
};

const timeTicker = window.setInterval(() => {
    browserNow.value = DateTime.now().setZone(browserTimezone);
}, 1000);

onUnmounted(() => {
    window.clearInterval(timeTicker);
});

onMounted(() => {
    browserNow.value = DateTime.now().setZone(browserTimezone);
    void relist();
});

const saveChanges = async () => {
    const {valid} = await r$.$validate();
    if (!valid) {
        return;
    }

    if (!hasBroadcastSlotSelected.value) {
        notifyError($gettext('Select at least one broadcast slot.'));
        appendLog($gettext('Settings not saved because no broadcast slot was selected.'), 'log-err');
        return;
    }

    const {data} = await axios.put<ApiStatus>(apiUrl.value, form.value);

    notifySuccess(data.message);
    appendLog($gettext('Settings saved successfully.'), 'log-ok');
    saveStatusText.value = $gettext('All settings saved');
    mayNeedRestart();
    await relist();
};

const runTest = async () => {
    if (!form.value.ai_news_enabled) {
        notifyError($gettext('Enable AI News before running a manual bulletin generation.'));
        appendLog($gettext('Manual generation blocked while the bulletin is disabled.'), 'log-err');
        return;
    }

    isTesting.value = true;
    appendLog($gettext('Fetching headlines from configured RSS/Atom feeds...'), 'log-info');

    try {
        const {data} = await axios.post<AiNewsTestResponse>(testUrl.value);
        notifySuccess(data.message);
        lastStatus.value = data.ai_news_last_generation_status ?? lastStatus.value;
        lastTime.value = data.ai_news_last_generation_time ?? lastTime.value;
        lastError.value = data.ai_news_last_error ?? null;
        dashboard.value = data.dashboard ?? dashboard.value;
        appendLog($gettext('Bulletin generated successfully.'), 'log-ok');
        await relist();
    } catch (error: any) {
        const apiMessage = error?.response?.data?.message;
        notifyError(apiMessage);
        lastStatus.value = 'error';
        if (apiMessage) {
            lastError.value = apiMessage;
        }
        appendLog($gettext('Generation failed. Review the latest error status for details.'), 'log-err');
        await relist();
    } finally {
        isTesting.value = false;
    }
};

const updateActiveHours = (start: string, end: string) => {
    const normalizedStart = start.trim();
    const normalizedEnd = end.trim();

    if (normalizedStart.length === 0 && normalizedEnd.length === 0) {
        form.value.ai_news_active_hours = null;
        return;
    }

    form.value.ai_news_active_hours = `${normalizedStart}-${normalizedEnd}`;
};

const refreshHeadlinePreview = () => {
    appendLog($gettext('Headline preview refreshed from the latest backend dashboard payload.'), 'log-info');
};
</script>

<style scoped>
.ai-news-page {
    color: #e2e8f0;
}

.ai-news-shell {
    overflow: hidden;
    border: 1px solid #2a2d3e;
    border-radius: 14px;
    background: #0f1117;
}

.ai-news-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #2a2d3e;
    background: #1a1d27;
}

.ai-news-branding {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.logo-dot {
    flex: 0 0 auto;
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: #4f8ef7;
    box-shadow: 0 0 12px rgba(79, 142, 247, 0.75);
}

.ai-news-title {
    margin: 0;
    color: #e2e8f0;
    font-size: 1.2rem;
    font-weight: 700;
}

.ai-news-subtitle {
    color: #94a3b8;
    font-size: 0.85rem;
}

.live-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.85rem;
    border: 1px solid #22c55e;
    border-radius: 999px;
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
    font-size: 0.8rem;
    font-weight: 600;
}

.live-badge.is-off {
    border-color: #ef4444;
    background: rgba(239, 68, 68, 0.08);
    color: #fca5a5;
}

.live-dot {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: currentColor;
    box-shadow: 0 0 8px currentColor;
}

.ai-news-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}

.reference-note {
    margin-bottom: 1.25rem;
    color: #94a3b8;
    font-size: 0.85rem;
    line-height: 1.5;
}

.reference-note p {
    margin: 0;
}

.dashboard-card {
    padding: 1.5rem;
    border: 1px solid #2a2d3e;
    border-radius: 12px;
    background: #1a1d27;
}

.status-strip {
    margin-bottom: 1.5rem;
}

.dashboard-card-title {
    margin-bottom: 1rem;
    color: #6b7280;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.stat-card {
    padding: 0.875rem 1rem;
    border: 1px solid #2a2d3e;
    border-radius: 8px;
    background: #0f1117;
}

.stat-label {
    margin-bottom: 0.25rem;
    color: #6b7280;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.stat-value {
    color: #e2e8f0;
    font-size: 1.05rem;
    font-weight: 600;
    white-space: pre-line;
}

.stat-helper {
    margin-top: 0.35rem;
    color: #94a3b8;
    font-size: 0.75rem;
}

.tone-green {
    color: #22c55e;
}

.tone-yellow {
    color: #f59e0b;
}

.tone-blue {
    color: #4f8ef7;
}

.tone-red {
    color: #ef4444;
}

.tone-muted {
    color: #94a3b8;
}

.tone-default {
    color: #e2e8f0;
}

.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 1.5rem;
}

.left-column {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.generate-area {
    padding: 0.75rem 0;
    text-align: center;
}

.big-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 2.25rem;
    border: 0;
    border-radius: 8px;
    background: #4f8ef7;
    color: #fff;
    font-size: 1.1rem;
    font-weight: 700;
    box-shadow: 0 0 20px rgba(79, 142, 247, 0.3);
    transition: filter 0.2s ease, box-shadow 0.2s ease;
}

.big-btn:hover:not(:disabled) {
    filter: brightness(1.08);
    box-shadow: 0 0 30px rgba(79, 142, 247, 0.45);
}

.big-btn:disabled {
    opacity: 0.6;
    box-shadow: none;
    cursor: not-allowed;
}

.generate-help {
    margin-top: 0.75rem;
    color: #fca5a5;
    font-size: 0.8rem;
    line-height: 1.45;
}

.spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 999px;
    animation: spin 0.7s linear infinite;
}

.audio-section {
    margin-top: 1.5rem;
}

.player-box,
.log-box {
    border: 1px solid #2a2d3e;
    border-radius: 8px;
    background: #0f1117;
}

.player-box {
    padding: 1rem 1.25rem;
}

.player-title {
    margin-bottom: 0.75rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.audio-placeholder {
    padding: 0.75rem 0.9rem;
    border: 1px dashed #334155;
    border-radius: 6px;
    color: #cbd5e1;
    font-size: 0.9rem;
    line-height: 1.5;
}

.bulletin-player {
    width: 100%;
    margin-top: 0.9rem;
    filter: saturate(0.9);
}

.audio-link-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.9rem;
}

.meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 0.75rem;
    color: #94a3b8;
    font-size: 0.78rem;
}

.meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.log-section {
    margin-top: 1.25rem;
}

.log-box {
    min-height: 70px;
    max-height: 180px;
    padding: 0.75rem 0.9rem;
    overflow-y: auto;
    color: #94a3b8;
    font-family: monospace;
    font-size: 0.78rem;
}

.log-line + .log-line {
    margin-top: 0.25rem;
}

.log-ok {
    color: #22c55e;
}

.log-err {
    color: #ef4444;
}

.log-info {
    color: #60a5fa;
}

.headlines-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.headline-list-wrap {
    max-height: 420px;
    padding-right: 0.25rem;
    overflow-y: auto;
}

.headline-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.headline-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid #2a2d3e;
}

.headline-item:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.src-tag {
    flex: 0 0 auto;
    margin-top: 0.1rem;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.src-worthy {
    background: rgba(79, 142, 247, 0.15);
    color: #4f8ef7;
}

.src-rapture {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.src-bbc {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.src-info {
    background: rgba(148, 163, 184, 0.15);
    color: #cbd5e1;
}

.hl-title {
    color: #e2e8f0;
    font-size: 0.88rem;
    line-height: 1.4;
    word-break: break-word;
}

.hl-summary {
    margin-top: 0.2rem;
    color: #94a3b8;
    font-size: 0.78rem;
    line-height: 1.45;
}

.settings-card :deep(.form-group) {
    margin-bottom: 1rem;
}

.settings-card-plain :deep(.form-group) {
    margin-bottom: 1rem;
}

.settings-card-plain :deep(.form-group-label) {
    margin-bottom: 0;
}

.settings-card-plain :deep(.form-group .form-label) {
    margin-bottom: 0.4rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.settings-card :deep(label) {
    display: block;
    margin-bottom: 0.4rem;
    color: #94a3b8;
    font-size: 0.8rem;
}

.toggle-label-row {
    margin-bottom: 0.4rem;
}

.label-helper {
    color: #6b7280;
    font-weight: 400;
}

.settings-group {
    margin-bottom: 1rem;
}

.settings-group-tight {
    margin-bottom: 1rem;
}

.settings-group-tight:last-of-type {
    margin-bottom: 0;
}

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.25rem;
}

.toggle-label {
    color: #e2e8f0;
    font-weight: 600;
}

.toggle-helper,
.field-note,
.save-status {
    color: #94a3b8;
    font-size: 0.8rem;
}

.field-note {
    margin-top: 0.4rem;
    line-height: 1.45;
}

.toggle {
    position: relative;
    flex: 0 0 auto;
    width: 48px;
    height: 26px;
    cursor: pointer;
}

.toggle input {
    width: 0;
    height: 0;
    opacity: 0;
}

.slider {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #374151;
    transition: background 0.2s ease;
}

.slider::before {
    content: "";
    position: absolute;
    left: 4px;
    bottom: 4px;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: #fff;
    transition: transform 0.2s ease;
}

.toggle input:checked + .slider {
    background: #4f8ef7;
}

.toggle input:checked + .slider::before {
    transform: translateX(22px);
}

.form-control-dark {
    border: 1px solid #2a2d3e;
    background: #0f1117;
    color: #e2e8f0;
}

.form-control-dark:focus {
    border-color: #4f8ef7;
    background: #0f1117;
    color: #e2e8f0;
    box-shadow: 0 0 0 0.2rem rgba(79, 142, 247, 0.15);
}

.form-control-dark:disabled {
    background: #111827;
    color: #94a3b8;
    opacity: 1;
}

.ai-news-time-picker {
    width: 100%;
}

.ai-news-time-picker :deep(.dp__main),
.ai-news-time-picker :deep(.dp__input_wrap) {
    width: 100%;
}

.ai-news-time-picker :deep(.dp__input_icon),
.ai-news-time-picker :deep(.dp--time-overlay-btn) {
    display: none;
}

.ai-news-time-picker :deep(.dp__input),
.ai-news-time-picker :deep(.dp__input_icon_pad) {
    width: 100%;
    padding-left: 0.75rem !important;
    border: 1px solid #2a2d3e;
    background: #0f1117;
    color: #e2e8f0;
}

.ai-news-time-picker :deep(.dp__input:focus) {
    border-color: #4f8ef7;
    box-shadow: 0 0 0 0.2rem rgba(79, 142, 247, 0.15);
}

.ai-news-time-picker :deep(.dp__theme_dark) {
    --dp-background-color: #111827;
    --dp-text-color: #e2e8f0;
    --dp-hover-color: #1f2937;
    --dp-hover-text-color: #e2e8f0;
    --dp-primary-color: #4f8ef7;
    --dp-primary-text-color: #ffffff;
    --dp-border-color: #2a2d3e;
    --dp-menu-border-color: #2a2d3e;
}

.time-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.time-field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.time-field-label {
    margin-bottom: 0;
    color: #94a3b8;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.broadcast-slots {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 0.75rem;
}

.broadcast-slot-option {
    display: inline-flex !important;
    align-items: center;
    gap: 0.55rem;
    margin-bottom: 0;
    color: #e2e8f0 !important;
    font-size: 0.9rem !important;
}

.broadcast-slot-option .form-check-input {
    margin: 0;
}

.source-card {
    border: 1px solid #2a2d3e;
    border-radius: 0.9rem;
    background: #0f1117;
    padding: 0.9rem 1rem;
}

.source-card.active {
    border-color: rgba(79, 142, 247, 0.6);
    box-shadow: inset 0 0 0 1px rgba(79, 142, 247, 0.18);
}

.source-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.35rem;
}

.source-card-label {
    color: #e2e8f0;
    font-size: 0.95rem;
    font-weight: 600;
}

.source-card-status {
    color: #4f8ef7;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.source-card-status.status-ok {
    color: #34d399;
}

.source-card-status.status-empty {
    color: #fbbf24;
}

.source-card-status.status-skipped {
    color: #f87171;
}

.source-card-status.status-idle {
    color: #94a3b8;
}

.source-card-url {
    color: #94a3b8;
    font-size: 0.8rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.source-card-meta,
.source-card-count {
    margin-top: 0.45rem;
    color: #94a3b8;
    font-size: 0.78rem;
    line-height: 1.45;
}

.btn-row {
    display: flex;
    gap: 0.625rem;
    margin-top: 1.25rem;
}

.save-status {
    min-height: 1.25rem;
    margin-top: 0.75rem;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 900px) {
    .status-grid,
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .ai-news-topbar {
        align-items: flex-start;
        flex-direction: column;
        padding: 1rem;
    }

    .ai-news-container {
        padding: 1rem;
    }

    .status-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .time-row,
    .btn-row {
        grid-template-columns: 1fr;
        flex-direction: column;
    }

    .broadcast-slots {
        flex-direction: column;
        gap: 0.65rem;
    }
}
</style>

