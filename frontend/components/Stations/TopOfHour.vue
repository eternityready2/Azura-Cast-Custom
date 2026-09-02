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
                            'Plans one station ID during minute :59 through the normal AutoDJ queue. It never interrupts or resumes a song. The scheduler prefers music that can finish before the ID window; if no safe fit exists, the song is allowed to finish rather than being cut. Tag files as ID on the Music Files page.'
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
                        {{ $gettext('Related Feature: Interrupting Audio Ducking') }}
                    </h3>
                    <p class="text-secondary small">
                        {{
                            $gettext(
                                'This is separate from Top-of-Hour ID playback. When enabled, normal interrupting liners and promos lower the music bed under them instead of replacing it.'
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
import type {TopOfHourCompliance, TopOfHourSettings} from '~/entities/TopOfHour.ts';


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
    top_of_hour_lookahead_minutes: 10,
    top_of_hour_compliance_tolerance_seconds: 10,
    top_of_hour_id_max_seconds: 60,
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
            top_of_hour_lookahead_minutes: data.top_of_hour_lookahead_minutes ?? 10,
            top_of_hour_compliance_tolerance_seconds: data.top_of_hour_compliance_tolerance_seconds ?? 10,
            top_of_hour_id_max_seconds: data.top_of_hour_id_max_seconds ?? 60,
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
