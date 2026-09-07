<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <div>
                    <h2 :id="id" class="card-title my-0">
                        {{ $gettext('Top of Hour Station ID') }}
                    </h2>
                    <div class="text-secondary small mt-1">
                        {{ $gettext('Exact wall-clock station identification') }}
                    </div>
                </div>
            </template>

            <info-card>
                <p class="mb-2">
                    {{ $gettext('When enabled, the Station ID owns the configured second inside minute :59. If another source is still on air, AzuraCast fades it down before the deadline and starts the ID exactly on time.') }}
                </p>
                <p class="mb-0">
                    {{ $gettext('A rigid program scheduled at :00 always starts at :00, even if that means cutting the tail of the ID. On an open hour the ID finishes naturally, then normal AutoDJ or top-hour AI News can continue.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-xl-7">
                            <div class="border rounded h-100 p-3">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                                    <div>
                                        <div class="text-uppercase text-secondary small fw-semibold">
                                            {{ $gettext('Next ID') }}
                                        </div>
                                        <div class="fs-5 fw-semibold">
                                            {{ nextModeTitle }}
                                        </div>
                                    </div>
                                    <span class="badge" :class="nextModeBadgeClass">
                                        {{ nextModeBadge }}
                                    </span>
                                </div>

                                <template v-if="!form.top_of_hour_id_enabled">
                                    <p class="text-secondary mb-0">
                                        {{ $gettext('Top-of-Hour Station ID is disabled. No automatic :59 takeover is applied.') }}
                                    </p>
                                </template>
                                <template v-else-if="nextPlan">
                                    <div class="row g-3">
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('ID Start') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.target_start_at) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('Boundary') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.boundary_at) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('ID Length') }}</div>
                                            <div class="fw-semibold">{{ formatDuration(nextPlan.duration_seconds) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('Selected ID') }}</div>
                                            <div class="fw-semibold text-truncate" :title="nextPlan.media.title ?? ''">
                                                {{ nextPlan.media.title || $gettext('Untitled ID') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                                        <span class="badge" :class="staging.is_staged ? 'text-bg-success' : 'text-bg-secondary'">
                                            {{ staging.is_staged ? $gettext('Staged in Upcoming Queue') : $gettext('Not staged yet') }}
                                        </span>
                                        <span v-if="staging.queue_id" class="small text-secondary">
                                            {{ $gettext('Queue #%{id}', {id: staging.queue_id}) }}
                                        </span>
                                    </div>

                                    <div v-if="nextPlan.will_be_cut_at_boundary" class="alert alert-warning mt-3 mb-0">
                                        {{ $gettext('This ID is longer than the time available before the rigid :00 program. The program will still start exactly at :00 and will cut the remaining ID audio. Move the ID start earlier to avoid that.') }}
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="text-warning mb-0">
                                        {{ $gettext('No eligible Station ID is available for the next hour. Add an ID file with a valid duration within the configured maximum.') }}
                                    </p>
                                </template>
                            </div>
                        </div>

                        <div class="col-12 col-xl-5">
                            <div class="border rounded h-100 p-3">
                                <div class="text-uppercase text-secondary small fw-semibold mb-2">
                                    {{ $gettext('How it behaves') }}
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $gettext('ID deadline') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('The ID begins at your selected :59:ss time every hour. Music cannot push it late.') }}
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $gettext('If music is still playing') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('The outgoing source receives a slow pre-fade, reaches silence at the deadline, and the Station ID takes air exactly on time.') }}
                                    </div>
                                </div>
                                <div>
                                    <div class="fw-semibold">{{ $gettext('At :00') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('Rigid programs win exactly at :00. If the hour is open, the ID finishes naturally and normal continuity resumes.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-xl-7">
                            <h3 class="h6 mb-3">{{ $gettext('Station ID Control') }}</h3>

                            <form-group id="top_of_hour_id_enabled" class="mb-3">
                                <template #label>{{ $gettext('Enable automatic Top-of-Hour Station ID') }}</template>
                                <form-checkbox id="top_of_hour_id_enabled" v-model="form.top_of_hour_id_enabled" />
                                <template #description>
                                    {{ $gettext('When disabled, the entire automatic :59 takeover is bypassed.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_start_second" class="mb-3">
                                <template #label>{{ $gettext('ID start time') }}</template>
                                <div class="input-group">
                                    <span class="input-group-text">:59:</span>
                                    <input
                                        id="top_of_hour_id_start_second"
                                        v-model.number="form.top_of_hour_id_start_second"
                                        type="number"
                                        class="form-control"
                                        min="0"
                                        max="59"
                                    >
                                </div>
                                <template #description>
                                    {{ $gettext('Choose the exact second in minute :59 when the ID starts. Current setting: %{time}.', {time: configuredStartLabel}) }}
                                    <template v-if="nextPlan">
                                        {{ $gettext(' For the selected ID, :59:%{second} is the latest whole-second start that should finish before :00.', {second: padSecond(nextPlan.recommended_start_second)}) }}
                                    </template>
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_fade_seconds" class="mb-3">
                                <template #label>{{ $gettext('Slow fade before ID') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_id_fade_seconds"
                                        v-model.number="form.top_of_hour_id_fade_seconds"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="10"
                                        step="0.5"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('If audio is still on air, it is faded to silence during this period immediately before the ID deadline.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_lookahead_minutes" class="mb-3">
                                <template #label>{{ $gettext('Staging lookahead') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_lookahead_minutes"
                                        v-model.number="form.top_of_hour_lookahead_minutes"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="30"
                                    >
                                    <span class="input-group-text">{{ $gettext('minutes') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('The selected ID is resolved and staged this far ahead so Liquidsoap already has it before the exact wall-clock deadline.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_max_seconds" class="mb-3">
                                <template #label>{{ $gettext('Maximum Station ID length') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_id_max_seconds"
                                        v-model.number="form.top_of_hour_id_max_seconds"
                                        type="number"
                                        class="form-control"
                                        min="15"
                                        max="60"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('Only files tagged as ID and within this maximum are eligible. Promos and commercials are never substituted.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_compliance_tolerance_seconds" class="mb-0">
                                <template #label>{{ $gettext('Compliance reporting tolerance') }}</template>
                                <div class="input-group">
                                    <input
                                        id="top_of_hour_compliance_tolerance_seconds"
                                        v-model.number="form.top_of_hour_compliance_tolerance_seconds"
                                        type="number"
                                        class="form-control"
                                        min="1"
                                        max="60"
                                    >
                                    <span class="input-group-text">{{ $gettext('seconds') }}</span>
                                </div>
                                <template #description>
                                    {{ $gettext('Reporting tolerance only. It does not change the wall-clock deadline or let an ID delay a rigid :00 program.') }}
                                </template>
                            </form-group>
                        </div>

                        <div class="col-12 col-xl-5">
                            <h3 class="h6 mb-3">{{ $gettext('Readiness') }}</h3>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>{{ $gettext('Station ID files') }}</span>
                                    <span class="fs-5 fw-semibold">{{ idMediaCount }}</span>
                                </div>
                                <div class="small text-secondary mt-1">
                                    {{ $gettext('Only files tagged as ID are eligible.') }}
                                </div>
                            </div>

                            <template v-if="compliance">
                                <h3 class="h6 mt-4 mb-3">{{ $gettext('7-Day Compliance') }}</h3>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold">
                                                {{ compliance.compliance_percent ?? '—' }}<span v-if="compliance.compliance_percent != null" class="fs-6">%</span>
                                            </div>
                                            <div class="small text-secondary">{{ $gettext('On time') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold">{{ compliance.on_time_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Compliant hours') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-warning">{{ compliance.late_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Late / missed') }}</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2 text-center h-100">
                                            <div class="fs-4 fw-semibold text-secondary">{{ compliance.fallback_count ?? 0 }}</div>
                                            <div class="small text-secondary">{{ $gettext('Fallback events') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </loading>

            <template #footer_actions>
                <button type="submit" class="btn btn-primary" :disabled="isLoading || isSaving">
                    {{ $gettext('Save Changes') }}
                </button>
            </template>
        </card-page>
    </form>
</template>

<script setup lang="ts">
import CardPage from '~/components/Common/CardPage.vue';
import InfoCard from '~/components/Common/InfoCard.vue';
import Loading from '~/components/Common/Loading.vue';
import FormGroup from '~/components/Form/FormGroup.vue';
import FormCheckbox from '~/components/Form/FormCheckbox.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import type {
    TopOfHourCompliance,
    TopOfHourForm,
    TopOfHourNextPlan,
    TopOfHourSettings,
    TopOfHourStagingStatus,
} from '~/entities/TopOfHour.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {computed, onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();
const {formatIsoAsTime} = useStationDateTimeFormatter();

const apiUrl = getStationApiUrl('/top-of-hour');
const isLoading = ref(true);
const isSaving = ref(false);
const idMediaCount = ref(0);
const compliance = ref<TopOfHourCompliance | null>(null);
const nextPlan = ref<TopOfHourNextPlan | null>(null);
const configuredStartLabel = ref(':59:00');
const staging = ref<TopOfHourStagingStatus>({is_staged: false, queue_id: null});

const form = ref<TopOfHourForm>({
    top_of_hour_id_enabled: false,
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_id_max_seconds: 60,
    top_of_hour_id_start_second: 0,
    top_of_hour_id_fade_seconds: 5,
});

const nextModeBadge = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'OFF';
    if (!nextPlan.value) return 'NO ID';
    return nextPlan.value.mode === 'hard_toh' ? 'HARD :00' : 'OPEN HOUR';
});

const nextModeBadgeClass = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'text-bg-secondary';
    if (!nextPlan.value) return 'text-bg-warning';
    return nextPlan.value.mode === 'hard_toh' ? 'text-bg-danger' : 'text-bg-primary';
});

const nextModeTitle = computed(() => {
    if (!form.value.top_of_hour_id_enabled) return 'Automatic ID Disabled';
    if (!nextPlan.value) return 'Station ID Required';
    return nextPlan.value.mode === 'hard_toh'
        ? 'ID before rigid :00 program'
        : 'ID before open new hour';
});

const formatClock = (value: string): string => formatIsoAsTime(value);
const formatDuration = (seconds: number): string => `${seconds.toFixed(1)}s`;
const padSecond = (second: number): string => String(second).padStart(2, '0');

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourSettings>(apiUrl.value);
        form.value = {
            top_of_hour_id_enabled: data.top_of_hour_id_enabled ?? false,
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes ?? 10,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds ?? 10,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds ?? 60,
            top_of_hour_id_start_second: data.top_of_hour_id_start_second ?? 0,
            top_of_hour_id_fade_seconds: data.top_of_hour_id_fade_seconds ?? 5,
        };
        configuredStartLabel.value = data.configured_start_label ?? ':59:00';
        idMediaCount.value = data.id_media_count ?? 0;
        compliance.value = data.compliance ?? null;
        nextPlan.value = data.next ?? null;
        staging.value = data.staging ?? {is_staged: false, queue_id: null};
    } catch {
        notifyError();
    } finally {
        isLoading.value = false;
    }
};

const saveChanges = async () => {
    isSaving.value = true;
    try {
        await axios.put(apiUrl.value, form.value);
        notifySuccess();
        await loadSettings();
    } catch {
        notifyError();
    } finally {
        isSaving.value = false;
    }
};

onMounted(loadSettings);
</script>
