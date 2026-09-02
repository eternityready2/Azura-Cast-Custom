<template>
    <form @submit.prevent="saveChanges">
        <card-page header-id="hdr_playout_controls">
            <template #header="{id}">
                <h2
                    :id="id"
                    class="card-title my-0"
                >
                    {{ $gettext('Playout Controls') }}
                </h2>
            </template>

            <info-card>
                <p class="mb-0">
                    {{
                        $gettext(
                            'Station-wide controls for strict scheduled boundaries and interrupting-audio mixing. These settings are separate from Top of Hour ID scheduling.'
                        )
                    }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <h3 class="h6">
                        {{ $gettext('Scheduled Boundary Protection') }}
                    </h3>
                    <p class="text-secondary small">
                        {{
                            $gettext(
                                'Protects strict AutoDJ playlist and Clock Wheel start times. Inside the trigger window, if the current track would run past the scheduled boundary, AzuraCast skips it using the station normal crossfade transition. Top of Hour ID is explicitly excluded from this feature.'
                            )
                        }}
                    </p>

                    <form-group
                        id="scheduled_boundary_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Enable scheduled boundary protection') }}
                        </template>

                        <form-checkbox
                            id="scheduled_boundary_enabled"
                            v-model="form.scheduled_boundary_enabled"
                        />
                    </form-group>

                    <form-group
                        v-if="form.scheduled_boundary_enabled"
                        id="scheduled_boundary_window_seconds"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Boundary trigger window (seconds)') }}
                        </template>

                        <input
                            id="scheduled_boundary_window_seconds"
                            v-model.number="form.scheduled_boundary_window_seconds"
                            type="number"
                            class="form-control"
                            min="60"
                            max="180"
                            step="1"
                        >
                        <div class="form-text">
                            {{ $gettext('Recommended: 90 seconds. The boundary transition uses your station crossfade settings.') }}
                        </div>
                    </form-group>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Interrupting Audio Ducking') }}
                    </h3>
                    <p class="text-secondary small">
                        {{
                            $gettext(
                                'When enabled, normal interrupting liners, promos and similar interrupting audio lower the music bed instead of fully replacing it, then restore the music smoothly.'
                            )
                        }}
                    </p>

                    <form-group
                        id="interrupting_duck_enabled"
                        class="mb-3"
                    >
                        <template #label>
                            {{ $gettext('Enable smart ducking') }}
                        </template>

                        <form-checkbox
                            id="interrupting_duck_enabled"
                            v-model="form.interrupting_duck_enabled"
                        />
                    </form-group>

                    <template v-if="form.interrupting_duck_enabled">
                        <form-group
                            id="interrupting_duck_attenuation"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Music bed level while ducked (0 = silent, 1 = full volume)') }}
                            </template>

                            <input
                                id="interrupting_duck_attenuation"
                                v-model.number="form.interrupting_duck_attenuation"
                                type="number"
                                class="form-control"
                                min="0"
                                max="1"
                                step="0.05"
                            >
                        </form-group>

                        <form-group
                            id="interrupting_duck_delay"
                            class="mb-3"
                        >
                            <template #label>
                                {{ $gettext('Ducking fade time (seconds)') }}
                            </template>

                            <input
                                id="interrupting_duck_delay"
                                v-model.number="form.interrupting_duck_delay"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="15"
                                step="0.5"
                            >
                        </form-group>
                    </template>

                    <div class="alert alert-info mb-0">
                        {{ $gettext('Ducking configuration changes, including enabling or disabling it, take effect after broadcasting is restarted.') }}
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
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {onMounted, ref} from 'vue';
import type {PlayoutControlsSettings} from '~/entities/PlayoutControls.ts';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/playout-controls');
const isLoading = ref(true);
const isSaving = ref(false);

const form = ref<PlayoutControlsSettings>({
    scheduled_boundary_enabled: false,
    scheduled_boundary_window_seconds: 90,
    interrupting_duck_enabled: false,
    interrupting_duck_attenuation: 0.2,
    interrupting_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<PlayoutControlsSettings>(apiUrl.value);
        form.value = {
            scheduled_boundary_enabled: data.scheduled_boundary_enabled ?? false,
            scheduled_boundary_window_seconds: data.scheduled_boundary_window_seconds ?? 90,
            interrupting_duck_enabled: data.interrupting_duck_enabled ?? false,
            interrupting_duck_attenuation: data.interrupting_duck_attenuation ?? 0.2,
            interrupting_duck_delay: data.interrupting_duck_delay ?? 3,
        };
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
