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
                    {{ $gettext('Advanced hard-clock and audio-ducking controls, kept separate from the Top of Hour ID page.') }}
                </p>
            </info-card>

            <loading :loading="isLoading" lazy>
                <div class="card-body">
                    <h3 class="h6">
                        {{ $gettext('Hard Clock Trigger') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('System-clock safety trigger used by the existing playout engine. Changes here preserve the pre-rebuild Top of Hour behavior.') }}
                    </p>

                    <form-group id="hard_clock_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable hard clock trigger') }}
                        </template>
                        <form-checkbox id="hard_clock_enabled" v-model="form.hard_clock_enabled" />
                    </form-group>

                    <template v-if="form.hard_clock_enabled">
                        <form-group id="hard_clock_trigger_seconds" class="mb-3">
                            <template #label>
                                {{ $gettext('Trigger window (seconds)') }}
                            </template>
                            <input
                                id="hard_clock_trigger_seconds"
                                v-model.number="form.hard_clock_trigger_seconds"
                                type="number"
                                class="form-control"
                                min="1"
                                max="30"
                                step="0.5"
                            >
                        </form-group>

                        <form-group id="hard_clock_fade_seconds" class="mb-3">
                            <template #label>
                                {{ $gettext('Fade duration (seconds)') }}
                            </template>
                            <input
                                id="hard_clock_fade_seconds"
                                v-model.number="form.hard_clock_fade_seconds"
                                type="number"
                                class="form-control"
                                min="0"
                                max="10"
                                step="0.5"
                            >
                        </form-group>
                    </template>

                    <hr class="my-4">

                    <h3 class="h6">
                        {{ $gettext('Smart Ducking') }}
                    </h3>
                    <p class="text-secondary small">
                        {{ $gettext('Lowers the music bed while supported interrupting audio plays, then restores it smoothly.') }}
                    </p>

                    <form-group id="smart_duck_enabled" class="mb-3">
                        <template #label>
                            {{ $gettext('Enable smart ducking') }}
                        </template>
                        <form-checkbox id="smart_duck_enabled" v-model="form.smart_duck_enabled" />
                    </form-group>

                    <template v-if="form.smart_duck_enabled">
                        <form-group id="smart_duck_attenuation" class="mb-3">
                            <template #label>
                                {{ $gettext('Music bed level while ducked (0 = silent, 1 = full volume)') }}
                            </template>
                            <input
                                id="smart_duck_attenuation"
                                v-model.number="form.smart_duck_attenuation"
                                type="number"
                                class="form-control"
                                min="0"
                                max="1"
                                step="0.05"
                            >
                        </form-group>

                        <form-group id="smart_duck_delay" class="mb-3">
                            <template #label>
                                {{ $gettext('Ducking fade time (seconds)') }}
                            </template>
                            <input
                                id="smart_duck_delay"
                                v-model.number="form.smart_duck_delay"
                                type="number"
                                class="form-control"
                                min="0.5"
                                max="15"
                                step="0.5"
                            >
                        </form-group>
                    </template>

                    <div class="alert alert-info mb-0">
                        {{ $gettext('Ducking and Liquidsoap hard-clock configuration changes take effect after broadcasting is restarted.') }}
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
import {useApiRouter} from '~/functions/useApiRouter.ts';
import type {PlayoutControlsSettings} from '~/entities/PlayoutControls.ts';
import {useAxios} from '~/vendor/axios.ts';
import {onMounted, ref} from 'vue';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess, notifyError} = useNotify();

const apiUrl = getStationApiUrl('/playout-controls');
const isLoading = ref(true);
const isSaving = ref(false);

const form = ref<PlayoutControlsSettings>({
    hard_clock_enabled: false,
    hard_clock_trigger_seconds: 3,
    hard_clock_fade_seconds: 3,
    smart_duck_enabled: false,
    smart_duck_attenuation: 0.2,
    smart_duck_delay: 3,
});

const loadSettings = async () => {
    isLoading.value = true;
    try {
        const {data} = await axios.get<PlayoutControlsSettings>(apiUrl.value);
        form.value = {
            hard_clock_enabled: data.hard_clock_enabled ?? false,
            hard_clock_trigger_seconds: data.hard_clock_trigger_seconds ?? 3,
            hard_clock_fade_seconds: data.hard_clock_fade_seconds ?? 3,
            smart_duck_enabled: data.smart_duck_enabled ?? false,
            smart_duck_attenuation: data.smart_duck_attenuation ?? 0.2,
            smart_duck_delay: data.smart_duck_delay ?? 3,
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
