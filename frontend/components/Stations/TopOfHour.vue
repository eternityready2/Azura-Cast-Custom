<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_top_of_hour">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Top of Hour ID') }}
                </h2>
            </template>

            <info-card>
                <p class="mb-0">
                    {{
                        $gettext(
                            'Queues a station ID at exactly :00 without interrupting the current song. During the lookahead window, music picks are filtered so long songs do not run past the hour. Tag files as ID on the Music Files page.'
                        )
                    }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <form-group
                        id="top_of_hour_id_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Require station ID at top of hour') }}
                        </template>

                        <form-checkbox
                            id="top_of_hour_id_enabled"
                            v-model="form.top_of_hour_id_enabled"
                        />
                    </form-group>

                    <form-group
                        id="top_of_hour_lookahead_minutes"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Lookahead (minutes before :00)') }}
                        </template>

                        <input
                            id="top_of_hour_lookahead_minutes"
                            v-model.number="form.top_of_hour_lookahead_minutes"
                            type="number"
                            class="form-control"
                            min="1"
                            max="30"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_finish_buffer_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Finish buffer (seconds before :00)') }}
                        </template>

                        <input
                            id="top_of_hour_finish_buffer_seconds"
                            v-model.number="form.top_of_hour_finish_buffer_seconds"
                            type="number"
                            class="form-control"
                            min="0"
                            max="30"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_compliance_tolerance_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Compliance tolerance (seconds late = miss)') }}
                        </template>

                        <input
                            id="top_of_hour_compliance_tolerance_seconds"
                            v-model.number="form.top_of_hour_compliance_tolerance_seconds"
                            type="number"
                            class="form-control"
                            min="1"
                            max="60"
                        >
                    </form-group>

                    <form-group
                        id="top_of_hour_id_max_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Max ID length for scheduling (seconds)') }}
                        </template>

                        <input
                            id="top_of_hour_id_max_seconds"
                            v-model.number="form.top_of_hour_id_max_seconds"
                            type="number"
                            class="form-control"
                            min="15"
                            max="120"
                        >
                    </form-group>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Hard Clock Trigger') }}
                    </h3>
                    <p class="text-secondary small">
                        {{
                            $gettext(
                                'Belt-and-suspenders safety net driven purely by the system clock, independent of the AutoDJ queue. If nothing was queued in time, this forces a fallback ID/announcement across the hour boundary with a smooth fade rather than a hard cut.'
                            )
                        }}
                    </p>

                    <form-group
                        id="top_of_hour_hard_trigger_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Enable hard clock trigger') }}
                        </template>

                        <form-checkbox
                            id="top_of_hour_hard_trigger_enabled"
                            v-model="form.top_of_hour_hard_trigger_enabled"
                        />
                    </form-group>

                    <template v-if="form.top_of_hour_hard_trigger_enabled">
                        <form-group
                            id="top_of_hour_hard_trigger_seconds"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Trigger window (seconds before :00)') }}
                            </template>

                            <input
                                id="top_of_hour_hard_trigger_seconds"
                                v-model.number="form.top_of_hour_hard_trigger_seconds"
                                type="number"
                                class="form-control"
                                min="1"
                                max="30"
                                step="0.5"
                                placeholder="5"
                            >
                        </form-group>

                        <form-group
                            id="top_of_hour_hard_trigger_fade"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Fade duration (seconds)') }}
                            </template>

                            <input
                                id="top_of_hour_hard_trigger_fade"
                                v-model.number="form.top_of_hour_hard_trigger_fade"
                                type="number"
                                class="form-control"
                                min="0"
                                max="10"
                                step="0.5"
                                placeholder="3"
                            >
                        </form-group>
                    </template>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Smart Ducking') }}
                    </h3>
                    <p class="text-secondary small">
                        {{
                            $gettext(
                                'When enabled, legal IDs and promos lower the music bed under them instead of hard-replacing it, then smoothly bring the music back up afterward.'
                            )
                        }}
                    </p>

                    <form-group
                        id="top_of_hour_duck_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Enable smart ducking') }}
                        </template>

                        <form-checkbox
                            id="top_of_hour_duck_enabled"
                            v-model="form.top_of_hour_duck_enabled"
                        />
                    </form-group>

                    <template v-if="form.top_of_hour_duck_enabled">
                        <form-group
                            id="top_of_hour_duck_attenuation"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Music bed level while ducked (0 = silent, 1 = full volume)') }}
                            </template>

                            <input
                                id="top_of_hour_duck_attenuation"
                                v-model.number="form.top_of_hour_duck_attenuation"
                                type="number"
                                class="form-control"
                                min="0"
                                max="1"
                                step="0.05"
                                placeholder="0.2"
                            >
                        </form-group>

                        <form-group
                            id="top_of_hour_duck_delay"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Ducking fade time (seconds)') }}
                            </template>

                            <input
                                id="top_of_hour_duck_delay"
                                v-model.number="form.top_of_hour_duck_delay"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="15"
                                step="0.5"
                                placeholder="3"
                            >
                        </form-group>
                    </template>

                    <p class="text-secondary mb-3">
                        {{
                            $gettext(
                                'ID files in library: %{count}',
                                {count: legalIdMediaCount}
                            )
                        }}
                    </p>

                    <template v-if="(compliance?.hours_with_legal_id ?? 0) > 0">
                        <h3 class="h6">
                            {{ $gettext('Top-of-hour ID compliance (last 7 days)') }}
                            <span class="text-muted fw-normal small">
                                ({{ $gettext('tolerance') }}: {{ compliance?.tolerance_seconds ?? 10 }}s)
                            </span>
                        </h3>
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 text-center">
                                    <div class="fs-4 fw-semibold">
                                        {{ compliance?.compliance_percent ?? '—' }}<span
                                            v-if="compliance?.compliance_percent != null"
                                            class="fs-6"
                                        >%</span>
                                    </div>
                                    <div class="small text-muted">
                                        {{ $gettext('On time') }}
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 text-center">
                                    <div class="fs-4 fw-semibold">
                                        {{ compliance?.on_time_count ?? 0 }}
                                    </div>
                                    <div class="small text-muted">
                                        {{ $gettext('Compliant hours') }}
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 text-center">
                                    <div class="fs-4 fw-semibold text-warning">
                                        {{ compliance?.late_count ?? 0 }}
                                    </div>
                                    <div class="small text-muted">
                                        {{ $gettext('Late (> tolerance)') }}
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2 text-center">
                                    <div class="fs-4 fw-semibold text-secondary">
                                        {{ compliance?.fallback_count ?? 0 }}
                                    </div>
                                    <div class="small text-muted">
                                        {{ $gettext('Fallback events') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
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
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {onMounted, ref} from 'vue';

interface TopOfHourCompliance {
    tolerance_seconds: number;
    hours_with_legal_id: number;
    on_time_count: number;
    late_count: number;
    compliance_percent: number | null;
    fallback_count: number;
}

interface TopOfHourSettings {
    top_of_hour_id_enabled: boolean;
    top_of_hour_id_mode: string;
    top_of_hour_lookahead_minutes: number;
    top_of_hour_compliance_tolerance_seconds: number;
    top_of_hour_finish_buffer_seconds: number;
    top_of_hour_id_max_seconds: number;
    top_of_hour_hard_trigger_enabled: boolean;
    top_of_hour_hard_trigger_seconds: number;
    top_of_hour_hard_trigger_fade: number;
    top_of_hour_duck_enabled: boolean;
    top_of_hour_duck_attenuation: number;
    top_of_hour_duck_delay: number;
    id_media_count?: number;
    legal_id_media_count: number;
    compliance?: TopOfHourCompliance;
}

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/top-of-hour');

const isLoading = ref(true);
const isSaving = ref(false);
const legalIdMediaCount = ref(0);
const compliance = ref<TopOfHourCompliance | null>(null);

const form = ref({
    top_of_hour_id_enabled: false,
    top_of_hour_id_mode: 'strict',
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_finish_buffer_seconds: 15,
    top_of_hour_id_max_seconds: 60,
    top_of_hour_hard_trigger_enabled: false,
    top_of_hour_hard_trigger_seconds: 3,
    top_of_hour_hard_trigger_fade: 3,
    top_of_hour_duck_enabled: false,
    top_of_hour_duck_attenuation: 0.2,
    top_of_hour_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<TopOfHourSettings>(apiUrl.value);
        form.value = {
            top_of_hour_id_enabled: data.top_of_hour_id_enabled ?? false,
            top_of_hour_id_mode: data.top_of_hour_id_mode ?? 'strict',
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes ?? 10,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds ?? 10,
            top_of_hour_finish_buffer_seconds: data.top_of_hour_finish_buffer_seconds ?? 15,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds ?? 60,
            top_of_hour_hard_trigger_enabled: data.top_of_hour_hard_trigger_enabled ?? false,
            top_of_hour_hard_trigger_seconds: data.top_of_hour_hard_trigger_seconds ?? 5,
            top_of_hour_hard_trigger_fade: data.top_of_hour_hard_trigger_fade ?? 3,
            top_of_hour_duck_enabled: data.top_of_hour_duck_enabled ?? false,
            top_of_hour_duck_attenuation: data.top_of_hour_duck_attenuation ?? 0.2,
            top_of_hour_duck_delay: data.top_of_hour_duck_delay ?? 3,
        };
        legalIdMediaCount.value = data.id_media_count ?? data.legal_id_media_count ?? 0;
        compliance.value = data.compliance ?? null;
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
