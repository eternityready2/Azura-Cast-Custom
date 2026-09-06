<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <div>
                    <h2
                        :id="id"
                        class="card-title my-0"
                    >
                        {{ $gettext('Top of Hour Station ID') }}
                    </h2>
                    <div class="text-secondary small mt-1">
                        {{ $gettext('Broadcast-clock controlled station identification') }}
                    </div>
                </div>
            </template>

            <info-card>
                <p class="mb-2">
                    {{ $gettext('This engine uses the same broadcast clock as scheduled programming, the 24-hour Linear Log, and stretch/squeeze. It does not use a second interrupt queue or a hard-cut timer.') }}
                </p>
                <p class="mb-0">
                    {{ $gettext('Tag approved identification audio as ID on the Music Files page. Promos and commercials are never substituted for a missing station ID.') }}
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
                                            {{ $gettext('Next Clock Decision') }}
                                        </div>
                                        <div class="fs-5 fw-semibold">
                                            {{ nextModeTitle }}
                                        </div>
                                    </div>
                                    <span
                                        class="badge"
                                        :class="nextModeBadgeClass"
                                    >
                                        {{ nextModeBadge }}
                                    </span>
                                </div>

                                <template v-if="!form.top_of_hour_id_enabled">
                                    <p class="text-secondary mb-0">
                                        {{ $gettext('Top-of-Hour Station ID is disabled. No automatic ID timing, protection, backtiming, fade, stretch, or squeeze rule is applied.') }}
                                    </p>
                                </template>
                                <template v-else-if="nextPlan">
                                    <div class="row g-3">
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('Boundary') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.boundary_at) }}</div>
                                        </div>
                                        <div class="col-6 col-lg-3">
                                            <div class="small text-secondary">{{ $gettext('ID Start') }}</div>
                                            <div class="fw-semibold">{{ formatClock(nextPlan.target_start_at) }}</div>
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
                                    {{ $gettext('Operating Modes') }}
                                </div>
                                <div class="mb-3">
                                    <div class="fw-semibold">{{ $gettext('HARD TOH') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('When a rigid event is scheduled at :00, the actual ID length is backtimed so the ID ends exactly at :00. The rigid event keeps absolute priority.') }}
                                    </div>
                                </div>
                                <div>
                                    <div class="fw-semibold">{{ $gettext('SOFT ETM') }}</div>
                                    <div class="small text-secondary">
                                        {{ $gettext('When the new hour is open, the station ID targets :59:00. After the ID, normal AutoDJ continuity resumes; no ad or promo is inserted merely to fill the minute.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-12 col-xl-7">
                            <h3 class="h6 mb-3">
                                {{ $gettext('Station ID Control') }}
                            </h3>

                            <form-group id="top_of_hour_id_enabled" class="mb-3">
                                <template #label>
                                    {{ $gettext('Enable automatic Top-of-Hour Station ID') }}
                                </template>
                                <form-checkbox
                                    id="top_of_hour_id_enabled"
                                    v-model="form.top_of_hour_id_enabled"
                                />
                                <template #description>
                                    {{ $gettext('When disabled, the entire automatic Top-of-Hour ID rule set is bypassed.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_lookahead_minutes" class="mb-3">
                                <template #label>
                                    {{ $gettext('Broadcast-clock lookahead') }}
                                </template>
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
                                    {{ $gettext('How early AutoDJ may begin choosing better-fitting music and applying the shared stretch/squeeze plan before the ID anchor.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_id_max_seconds" class="mb-3">
                                <template #label>
                                    {{ $gettext('Maximum Station ID length') }}
                                </template>
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
                                    {{ $gettext('IDs longer than this are not selected automatically. The hard limit is 60 seconds so a complete ID always fits inside minute :59.') }}
                                </template>
                            </form-group>

                            <form-group id="top_of_hour_compliance_tolerance_seconds" class="mb-0">
                                <template #label>
                                    {{ $gettext('Compliance reporting tolerance') }}
                                </template>
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
                                    {{ $gettext('Reporting tolerance only. It does not authorize a late ID to delay a rigid :00 event.') }}
                                </template>
                            </form-group>
                        </div>

                        <div class="col-12 col-xl-5">
                            <h3 class="h6 mb-3">
                                {{ $gettext('Readiness') }}
                            </h3>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>{{ $gettext('Station ID files') }}</span>
                                    <span class="fs-5 fw-semibold">{{ idMediaCount }}</span>
                                </div>
                                <div class="small text-secondary mt-1">
                                    {{ $gettext('Only files tagged as ID are eligible. Promo fallback is intentionally disabled.') }}
                                </div>
                            </div>

                            <template v-if="compliance">
                                <h3 class="h6 mt-4 mb-3">
                                    {{ $gettext('7-Day Compliance') }}
                                </h3>
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
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="isLoading || isSaving"
                >
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

const form = ref<TopOfHourForm>({
    top_of_hour_id_enabled: false,
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_id_max_seconds: 60,
});

const nextModeBadge = computed(() => {
    if (!form.value.top_of_hour_id_enabled) {
        return 'OFF';
    }
    if (!nextPlan.value) {
        return 'NO ID';
    }
    return nextPlan.value.mode === 'hard_toh' ? 'HARD TOH' : 'SOFT ETM';
});

const nextModeBadgeClass = computed(() => {
    if (!form.value.top_of_hour_id_enabled) {
        return 'text-bg-secondary';
    }
    if (!nextPlan.value) {
        return 'text-bg-warning';
    }
    return nextPlan.value.mode === 'hard_toh' ? 'text-bg-danger' : 'text-bg-primary';
});

const nextModeTitle = computed(() => {
    if (!form.value.top_of_hour_id_enabled) {
        return 'Automatic ID Disabled';
    }
    if (!nextPlan.value) {
        return 'Station ID Required';
    }
    return nextPlan.value.mode === 'hard_toh'
        ? 'Rigid :00 handoff'
        : 'Open-hour :59 identification';
});

const formatClock = (value: string): string => formatIsoAsTime(value);

const formatDuration = (seconds: number): string => `${seconds.toFixed(1)}s`;

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourSettings>(apiUrl.value);
        form.value = {
            top_of_hour_id_enabled: data.top_of_hour_id_enabled ?? false,
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes ?? 10,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds ?? 10,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds ?? 60,
        };
        idMediaCount.value = data.id_media_count ?? 0;
        compliance.value = data.compliance ?? null;
        nextPlan.value = data.next ?? null;
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