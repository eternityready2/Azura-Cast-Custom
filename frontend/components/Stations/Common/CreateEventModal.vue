<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="$gettext('Create Event')"
        :error="error"
        :disable-save-button="!isFormValid"
        @submit="doSave"
        @hidden="clearForm"
    >
        <!-- Source -->
        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Source') }}</label>
            <select
                v-model="form.source"
                class="form-select"
                @change="onSourceChange"
            >
                <option value="clock_wheel">
                    {{ $gettext('Clock Wheel') }}
                </option>
                <option value="playlist">
                    {{ $gettext('Playlist') }}
                </option>
            </select>
        </div>

        <!-- Entity selection -->
        <div class="mb-3">
            <label class="form-label fw-semibold">
                {{ form.source === 'playlist' ? $gettext('Playlist') : $gettext('Clock Wheel') }}
            </label>
            <select
                v-model="form.entity_id"
                class="form-select"
                :disabled="currentEntityOptions.length === 0"
            >
                <option
                    v-for="e in currentEntityOptions"
                    :key="e.id"
                    :value="e.id"
                >
                    {{ e.name }}
                </option>
            </select>
        </div>

        <!-- Start Date + Start Time -->
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">{{ $gettext('Start Date') }}</label>
                <input
                    v-model="form.start_date"
                    type="date"
                    class="form-control"
                    required
                >
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">{{ $gettext('Start Time') }}</label>
                <input
                    v-model="form.start_time_str"
                    type="time"
                    class="form-control"
                    required
                >
            </div>
        </div>

        <!-- Duration -->
        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Duration') }}</label>
            <div class="input-group">
                <input
                    v-model.number="form.duration_h"
                    type="number"
                    min="0"
                    max="23"
                    class="form-control"
                    :placeholder="$gettext('Hours')"
                >
                <span class="input-group-text">:</span>
                <input
                    v-model.number="form.duration_m"
                    type="number"
                    min="0"
                    max="59"
                    class="form-control"
                    :placeholder="$gettext('Minutes')"
                >
            </div>
            <small class="text-muted">{{ $gettext('Hours : Minutes') }}</small>
        </div>

        <!-- Scheduling -->
        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Scheduling') }}</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input
                        id="sched_flexible"
                        v-model="form.loop_once"
                        :value="false"
                        type="radio"
                        class="form-check-input"
                    >
                    <label
                        class="form-check-label"
                        for="sched_flexible"
                    >{{ $gettext('Flexible') }}</label>
                </div>
                <div class="form-check">
                    <input
                        id="sched_once"
                        v-model="form.loop_once"
                        :value="true"
                        type="radio"
                        class="form-check-input"
                    >
                    <label
                        class="form-check-label"
                        for="sched_once"
                    >{{ $gettext('Loop Once') }}</label>
                </div>
            </div>
        </div>

        <!-- Recurring -->
        <div class="mb-3">
            <div class="form-check">
                <input
                    id="create_event_recurring"
                    v-model="form.recurring"
                    type="checkbox"
                    class="form-check-input"
                >
                <label
                    class="form-check-label fw-semibold"
                    for="create_event_recurring"
                >
                    {{ $gettext('Recurring') }}
                </label>
            </div>
        </div>

        <template v-if="form.recurring">
            <!-- Recurring Days -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ $gettext('Recurring Days') }}</label>
                <div class="d-flex flex-wrap gap-3">
                    <div
                        v-for="day in dayOptions"
                        :key="day.value"
                        class="form-check"
                    >
                        <input
                            :id="'create_event_day_' + day.value"
                            v-model="form.days"
                            :value="day.value"
                            type="checkbox"
                            class="form-check-input"
                        >
                        <label
                            :for="'create_event_day_' + day.value"
                            class="form-check-label"
                        >
                            {{ day.label }}
                        </label>
                    </div>
                </div>
            </div>

            <!-- Repeat Until -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ $gettext('Repeat Until') }}</label>
                <input
                    v-model="form.end_date"
                    type="date"
                    class="form-control"
                >
            </div>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import {ref, computed, onMounted, watch, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {notifySuccess} = useNotify();

const emit = defineEmits<{
    relist: [];
}>();

interface EntityOption {
    id: number;
    name: string;
    self_url: string;
}

const playlists = ref<EntityOption[]>([]);
const clockWheels = ref<EntityOption[]>([]);

onMounted(async () => {
    const [plResp, cwResp] = await Promise.all([
        axios.get(getStationApiUrl('/playlists').value),
        axios.get(getStationApiUrl('/clock-wheels').value),
    ]);

    playlists.value = (plResp.data as Array<Record<string, unknown>>).map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
    }));

    clockWheels.value = (cwResp.data as Array<Record<string, unknown>>).map((cw) => ({
        id: cw.id as number,
        name: cw.name as string,
        self_url: (cw.links as Record<string, string>).self,
    }));
});

const today = () => new Date().toISOString().substring(0, 10);

const blankForm = () => ({
    source: 'clock_wheel' as 'playlist' | 'clock_wheel',
    entity_id: null as number | null,
    start_date: today(),
    start_time_str: '08:00',
    duration_h: 1,
    duration_m: 0,
    loop_once: false,
    recurring: false,
    days: [] as number[],
    end_date: '',
});

const form = ref(blankForm());
const loading = ref(false);
const error = ref<string | null>(null);
const $modal = useTemplateRef('$modal');

const currentEntityOptions = computed(() =>
    form.value.source === 'playlist' ? playlists.value : clockWheels.value
);

// Auto-select first entity whenever options change or source changes
watch(currentEntityOptions, (opts) => {
    if (opts.length > 0 && (form.value.entity_id === null || !opts.find(e => e.id === form.value.entity_id))) {
        form.value.entity_id = opts[0].id;
    }
}, {immediate: true});

const isFormValid = computed(() =>
    form.value.entity_id !== null &&
    form.value.start_date !== '' &&
    form.value.start_time_str !== '' &&
    (form.value.duration_h > 0 || form.value.duration_m > 0)
);

const onSourceChange = () => {
    form.value.entity_id = null;
};

const dayOptions = [
    {value: 1, label: $gettext('Mon')},
    {value: 2, label: $gettext('Tue')},
    {value: 3, label: $gettext('Wed')},
    {value: 4, label: $gettext('Thu')},
    {value: 5, label: $gettext('Fri')},
    {value: 6, label: $gettext('Sat')},
    {value: 7, label: $gettext('Sun')},
];

// TimeCode format used by AzuraCast: HH * 100 + MM  (e.g. 08:30 → 830, 13:00 → 1300)
const timeStrToCode = (str: string): number => {
    const [h, m] = str.split(':').map(Number);
    return h * 100 + m;
};

const addMinutesToCode = (tc: number, minutes: number): number => {
    const totalMin = ((tc / 100 | 0) * 60 + tc % 100) + minutes;
    const h = Math.floor(totalMin / 60) % 24;
    const m = totalMin % 60;
    return h * 100 + m;
};

const clearForm = () => {
    form.value = blankForm();
    error.value = null;
};

const open = () => {
    clearForm();
    ($modal.value as any)?.show();
};

const doSave = async () => {
    if (!form.value.entity_id) return;

    const entityOption = currentEntityOptions.value.find(e => e.id === form.value.entity_id);
    if (!entityOption) return;

    loading.value = true;
    error.value = null;

    try {
        // Fetch current entity data
        const {data: entityData} = await axios.get(entityOption.self_url);

        const startTimeCode = timeStrToCode(form.value.start_time_str);
        const durationMin = form.value.duration_h * 60 + form.value.duration_m;
        const endTimeCode = addMinutesToCode(startTimeCode, durationMin);

        const newScheduleItem: Record<string, unknown> = {
            start_time: startTimeCode,
            end_time: endTimeCode,
            start_date: form.value.start_date,
            end_date: form.value.recurring && form.value.end_date
                ? form.value.end_date
                : form.value.start_date,
            days: form.value.recurring ? form.value.days : [],
            loop_once: form.value.loop_once,
            recurrence_type: 'weekly',
            recurrence_interval: 1,
            recurrence_monthly_pattern: null,
            recurrence_monthly_day: null,
            recurrence_monthly_week: null,
            recurrence_monthly_day_of_week: null,
            recurrence_end_type: 'never',
            recurrence_end_after: null,
            recurrence_end_date: null,
        };

        const {id: _id, links: _links, ...putData} = entityData as Record<string, unknown>;
        const updatedScheduleItems = [...((putData.schedule_items as unknown[]) ?? []), newScheduleItem];

        await axios.put(entityOption.self_url, {
            ...putData,
            schedule_items: updatedScheduleItems,
        });

        notifySuccess($gettext('Event created.'));
        ($modal.value as any)?.hide();
        emit('relist');
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

defineExpose({open});
</script>
