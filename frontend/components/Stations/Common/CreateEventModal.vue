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

        <!-- Schedule Row - Time section -->
        <div class="row g-3 mb-3">
            <form-group-field
                id="edit_form_start_time"
                class="col-md-4"
                :field="r$.start_time"
                :label="$gettext('Start Time')"
                :description="$gettext('To play once per day, set the start and end times to the same value.')"
            >
                <template #default="{id, model, fieldClass}">
                    <circular-time-picker
                        :input-id="id"
                        v-model="model.$model"
                        :class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-group-field
                id="edit_form_end_time"
                class="col-md-4"
                :field="r$.end_time"
                :label="$gettext('End Time')"
                :description="$gettext('If the end time is before the start time, the playlist will play overnight.')"
            >
                <template #default="{id, model, fieldClass}">
                    <circular-time-picker
                        :input-id="id"
                        v-model="model.$model"
                        :class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-markup
                id="edit_form_duration"
                class="col-md-4"
                :label="$gettext('Duration')"
                :description="$gettext('Hours:Minutes')"
            >
                <div class="input-group">
                    <input
                        v-model.number="durationHours"
                        type="number"
                        class="form-control"
                        min="0"
                        max="23"
                        placeholder="HH"
                        @change="updateDurationFromHours"
                    >
                    <span class="input-group-text">:</span>
                    <input
                        v-model.number="durationMinutes"
                        type="number"
                        class="form-control"
                        min="0"
                        max="59"
                        placeholder="MM"
                        @change="updateDurationFromMinutes"
                    >
                </div>
            </form-markup>

            <form-markup
                id="station_time_zone"
                class="col-md-4"
                :label="$gettext('Station Time Zone')"
            >
                <time-zone />
            </form-markup>

            <!-- Date section -->
            <form-group-field
                id="edit_form_start_date"
                class="col-md-4"
                :field="r$.start_date"
                input-type="date"
                :label="$gettext('Start Date')"
                :description="$gettext('Required. Use with End date to limit when the schedule runs.')"
            />

            <form-group-field
                id="edit_form_end_date"
                class="col-md-4"
                :field="r$.end_date"
                input-type="date"
                :label="$gettext('End Date')"
                :description="$gettext('Use with Start date to limit when the schedule runs. Recurrence uses this as the last day.')"
                :required="scheduleRow.recurrence_end_type !== 'after'"
                :input-attrs="{ disabled: scheduleRow.recurrence_end_type === 'after' }"
            />

            <form-markup
                id="edit_form_scheduling"
                class="col-md-4"
                :label="$gettext('Scheduling')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_flexible"
                            v-model="schedulingMode"
                            class="form-check-input"
                            type="radio"
                            value="flexible"
                        >
                        <label class="form-check-label" for="scheduling_flexible">
                            {{ $gettext('Flexible') }}
                        </label>
                    </div>
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_strict"
                            v-model="schedulingMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <div class="form-check mt-2">
                    <input
                        id="scheduling_loop_once"
                        v-model="schedulingMode"
                        class="form-check-input"
                        type="radio"
                        value="loop_once"
                    >
                    <label class="form-check-label" for="scheduling_loop_once">
                        {{ $gettext('Loop Once') }}
                    </label>
                </div>
            </form-markup>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input
                    id="edit_form_is_recurring"
                    v-model="isRecurring"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_recurring">
                    {{ $gettext('Recurring') }}
                </label>
            </div>
            <small class="form-text text-muted">
                {{ $gettext('Schedule this event on a recurring basis.') }}
            </small>
        </div>

        <template v-if="isRecurring">
        <!-- Days of Week -->
        <form-group-multi-check
            id="edit_form_days"
            class="mb-3"
            :field="r$.days"
            :label="$gettext('Scheduled Play Days of Week')"
            :description="daysOfWeekFieldDescription"
            :options="dayOptions"
            :required="!isMonthlyDatePattern"
            :disabled="isMonthlyDatePattern"
            stacked
        />

        <!-- Repeat section -->
        <div class="mb-3">
            <h6 class="text-muted mb-2">
                {{ $gettext('Repeat') }}
            </h6>
        </div>

        <div class="row g-3 mb-3">
            <form-group-select
                id="edit_form_recurrence_type"
                class="col-md-4"
                :field="r$.recurrence_type"
                :label="$gettext('Repeat')"
                :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Custom = every N weeks; Monthly = by date or specific day of week.')"
                :options="recurrenceTypeOptions"
            />

            <form-group-field
                v-if="scheduleRow.recurrence_type === 'custom'"
                id="edit_form_recurrence_interval"
                class="col-md-4"
                :field="r$.recurrence_interval"
                input-type="number"
                min="1"
                max="52"
                :label="$gettext('Every (weeks)')"
                :description="$gettext('E.g. 3 = every 3 weeks. Set Start date for correct alignment.')"
            />

            <template v-if="scheduleRow.recurrence_type === 'monthly'">
                <form-group-select
                    id="edit_form_recurrence_monthly_pattern"
                    class="col-md-4"
                    :field="r$.recurrence_monthly_pattern"
                    :label="$gettext('Monthly Pattern')"
                    :options="recurrenceMonthlyPatternOptions"
                />

                <form-group-field
                    v-if="scheduleRow.recurrence_monthly_pattern === 'date'"
                    id="edit_form_recurrence_monthly_day"
                    class="col-md-4"
                    :field="r$.recurrence_monthly_day"
                    input-type="number"
                    min="1"
                    max="31"
                    :label="$gettext('Day of Month')"
                    :description="$gettext('Day of the month (1–31).')"
                />

                <template v-if="scheduleRow.recurrence_monthly_pattern === 'day_of_week'">
                    <form-group-select
                        id="edit_form_recurrence_monthly_week"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_week"
                        :label="$gettext('Week of Month')"
                        :description="$gettext('For monthly specific day of week.')"
                        :options="recurrenceMonthlyWeekOptions"
                    />
                </template>
            </template>

            <form-group-select
                id="edit_form_recurrence_end_type"
                class="col-md-4"
                :field="r$.recurrence_end_type"
                :label="$gettext('Stop Recurrence')"
                :description="$gettext('Optional: stop after a number of occurrences or use End date above.')"
                :options="recurrenceEndTypeOptions"
            />

            <form-group-field
                v-if="scheduleRow.recurrence_end_type === 'after'"
                id="edit_form_recurrence_end_after"
                class="col-md-4"
                :field="r$.recurrence_end_after"
                input-type="number"
                min="1"
                :label="$gettext('Stop After (occurrences)')"
            />
        </div>
    </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import CircularTimePicker from '~/components/Common/CircularTimePicker.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import FormGroupMultiCheck from '~/components/Form/FormGroupMultiCheck.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormMarkup from '~/components/Form/FormMarkup.vue';
import TimeZone from '~/components/Stations/Common/TimeZone.vue';
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from '@regle/rules';
import {useAppScopedRegle} from '~/vendor/regle.ts';
import {ref, computed, onMounted, watch, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {
    type PlaylistScheduleRow,
    createScheduleItemDefaults,
} from '~/components/Stations/Common/scheduleItemDefaults.ts';

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

const blankForm = () => ({
    source: 'clock_wheel' as 'playlist' | 'clock_wheel',
    entity_id: null as number | null,
});

const form = ref(blankForm());

const schedulingMode = ref<'flexible' | 'strict' | 'loop_once'>('flexible');

// Schedule row state - matches PlaylistScheduleRow interface
const scheduleRow = ref<PlaylistScheduleRow>(createScheduleItemDefaults());

const loading = ref(false);
const error = ref<string | null>(null);
const $modal = useTemplateRef('$modal');

// Duration state
const durationHours = ref(1);
const durationMinutes = ref(0);

// Recurring toggle
const isRecurring = ref(false);

// Update end_time from duration inputs
const updateDuration = () => {
    const startTime = scheduleRow.value.start_time;
    const startHours = Math.floor(startTime / 100);
    const startMinutes = startTime % 100;
    const durationTotalMinutes = durationHours.value * 60 + durationMinutes.value;
    let endTotalMinutes = startHours * 60 + startMinutes + durationTotalMinutes;
    endTotalMinutes = endTotalMinutes % (24 * 60);
    const endHours = Math.floor(endTotalMinutes / 60);
    const endMinutes = endTotalMinutes % 60;
    scheduleRow.value.end_time = endHours * 100 + endMinutes;
};

const updateDurationFromHours = () => updateDuration();
const updateDurationFromMinutes = () => updateDuration();

const currentEntityOptions = computed(() =>
    form.value.source === 'playlist' ? playlists.value : clockWheels.value
);

// Auto-select first entity whenever options change or source changes
watch(currentEntityOptions, (opts) => {
    if (opts.length > 0 && (form.value.entity_id === null || !opts.find(e => e.id === form.value.entity_id))) {
        form.value.entity_id = opts[0].id;
    }
}, {immediate: true});

watch(
    schedulingMode,
    (mode) => {
        scheduleRow.value.loop_once = mode !== 'flexible';
    },
    {immediate: true}
);

// Regle validation for schedule row
const isMonthlyDatePattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => !isMonthlyDatePattern.value);

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used when monthly pattern is "On day of month" — pick the calendar day below instead.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For monthly "specific day of week", select one or more days; each gets that week-of-month (e.g. 1st + Mon–Wed).');
    }
    return $gettext('Select at least one day of the week.');
});

const {r$} = useAppScopedRegle(
    scheduleRow,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => scheduleRow.value.recurrence_end_type !== 'after'),
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_end_after: {
            required: requiredIf(() => scheduleRow.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
            ),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);

// Sync recurrence_interval when type changes
watch(
    () => scheduleRow.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            scheduleRow.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            scheduleRow.value.recurrence_interval = 1;
        }
    }
);

// Clear days when monthly date pattern is selected
watch(
    () => [scheduleRow.value.recurrence_type, scheduleRow.value.recurrence_monthly_pattern] as const,
    () => {
        if (isMonthlyDatePattern.value) {
            scheduleRow.value.days = [];
        }
    }
);

const isFormValid = computed(() =>
    form.value.entity_id !== null &&
    !r$.$invalid
);

const onSourceChange = () => {
    form.value.entity_id = null;
};

const dayOptions = [
    {value: 1, text: $gettext('Monday')},
    {value: 2, text: $gettext('Tuesday')},
    {value: 3, text: $gettext('Wednesday')},
    {value: 4, text: $gettext('Thursday')},
    {value: 5, text: $gettext('Friday')},
    {value: 6, text: $gettext('Saturday')},
    {value: 7, text: $gettext('Sunday')}
];

const recurrenceTypeOptions = [
    {value: 'weekly', text: $gettext('Weekly (default)')},
    {value: 'biweekly', text: $gettext('Bi-weekly (every 2 weeks)')},
    {value: 'monthly', text: $gettext('Monthly')},
    {value: 'custom', text: $gettext('Custom (every N weeks)')}
];

const recurrenceMonthlyPatternOptions = [
    {value: 'date', text: $gettext('On day of month (e.g. 15th)')},
    {value: 'day_of_week', text: $gettext('Specific day of week (e.g. 3rd Monday)')}
];

const recurrenceMonthlyWeekOptions = [
    {value: 1, text: $gettext('1st')},
    {value: 2, text: $gettext('2nd')},
    {value: 3, text: $gettext('3rd')},
    {value: 4, text: $gettext('4th')},
    {value: 5, text: $gettext('Last')}
];

const recurrenceEndTypeOptions = [
    {value: 'never', text: $gettext('Never (use End date above to limit range)')},
    {value: 'after', text: $gettext('After number of occurrences')}
];

const clearForm = () => {
    form.value = blankForm();
    schedulingMode.value = 'flexible';
    scheduleRow.value = createScheduleItemDefaults();
    error.value = null;
};

const open = () => {
    clearForm();
    // If options are already loaded, auto-select the first one (watch won't re-fire if options didn't change)
    if (currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }
    ($modal.value as any)?.show();
};

const doSave = async () => {
    if (!form.value.entity_id) return;

    loading.value = true;
    error.value = null;

    try {
        // Build URL using getStationApiUrl to avoid Docker-internal host issues
        // Note: individual endpoints use singular: /playlist/{id} and /clock-wheel/{id}
        const entityType = form.value.source === 'playlist' ? 'playlist' : 'clock-wheel';
        const entityApiUrl = getStationApiUrl(`/${entityType}/${form.value.entity_id}`).value;

        // Fetch current entity data
        const {data: entityData} = await axios.get(entityApiUrl);

        // Use scheduleRow data directly - it already has all the fields in the correct format
        const newScheduleItem: PlaylistScheduleRow = {
            start_time: scheduleRow.value.start_time,
            end_time: scheduleRow.value.end_time,
            start_date: scheduleRow.value.start_date,
            end_date: scheduleRow.value.end_date || scheduleRow.value.start_date,
            days: scheduleRow.value.days,
            loop_once: scheduleRow.value.loop_once,
            recurrence_type: scheduleRow.value.recurrence_type,
            recurrence_interval: scheduleRow.value.recurrence_interval,
            recurrence_monthly_pattern: scheduleRow.value.recurrence_monthly_pattern,
            recurrence_monthly_day: scheduleRow.value.recurrence_monthly_day,
            recurrence_monthly_week: scheduleRow.value.recurrence_monthly_week,
            recurrence_monthly_day_of_week: scheduleRow.value.recurrence_monthly_day_of_week,
            recurrence_end_type: scheduleRow.value.recurrence_end_type,
            recurrence_end_after: scheduleRow.value.recurrence_end_after,
            recurrence_end_date: scheduleRow.value.recurrence_end_date,
        };

        const {id: _id, links: _links, ...putData} = entityData as Record<string, unknown>;
        const updatedScheduleItems = [...((putData.schedule_items as unknown[]) ?? []), newScheduleItem];

        await axios.put(entityApiUrl, {
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
