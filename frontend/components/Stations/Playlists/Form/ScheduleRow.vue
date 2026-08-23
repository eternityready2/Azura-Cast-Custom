<template>
    <section class="card mb-3">
        <div class="card-header text-bg-primary d-flex align-items-center">
            <div class="flex-fill">
                <h2 class="card-title">
                    {{ $gettext('Scheduled Time #%{num}', {num: index + 1}) }}
                </h2>
            </div>
            <div class="flex-shrink-0">
                <button
                    type="button"
                    class="btn btn-sm btn-dark"
                    @click="doRemove()"
                >
                    <icon-ic-remove/>

                    <span>
                        {{ $gettext('Remove') }}
                    </span>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <form-group-field
                    :id="'edit_form_start_time_'+index"
                    class="col-md-4"
                    :field="r$.start_time"
                    :label="$gettext('Start Time')"
                    :description="$gettext('To play once per day, set start and end to the same value.')"
                >
                    <template #default="{id, model, fieldClass}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                            :class="fieldClass"
                        />
                    </template>
                </form-group-field>

                <form-group-field
                    :id="'edit_form_end_time_'+index"
                    class="col-md-4"
                    :field="r$.end_time"
                    :label="$gettext('End Time')"
                    :description="$gettext('If end is before start, the event plays overnight. To avoid overlapping the next event, you can end at :59 (e.g. 1:59 PM before 2:00 PM).')"
                >
                    <template #default="{id, model, fieldClass}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                            :class="fieldClass"
                        />
                    </template>
                </form-group-field>

                <form-markup
                    id="station_time_zone"
                    class="col-md-4"
                    :label="$gettext('Station Time Zone')"
                >
                    <time-zone />
                </form-markup>

                <form-group-field
                    :id="'edit_form_start_date_'+index"
                    class="col-md-4"
                    :field="r$.start_date"
                    input-type="date"
                    :label="$gettext('Start Date')"
                    :description="$gettext('Required. Use with End date to limit when the schedule runs.')"
                />

                <form-group-field
                    :id="'edit_form_end_date_'+index"
                    class="col-md-4"
                    :field="r$.end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                    :description="$gettext('Use with Start date to limit when the schedule runs. Recurrence uses this as the last day.')"
                    :required="row.recurrence_end_type !== 'after'"
                    :input-attrs="{ disabled: row.recurrence_end_type === 'after' }"
                />

                <form-markup
                    :id="'edit_form_scheduling_'+index"
                    class="col-12"
                    :label="$gettext('Scheduling')"
                >
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check mb-0">
                            <input
                                :id="'scheduling_flexible_'+index"
                                v-model="schedulingMode"
                                class="form-check-input"
                                type="radio"
                                value="flexible"
                            >
                            <label
                                class="form-check-label"
                                :for="'scheduling_flexible_'+index"
                            >
                                {{ $gettext('Flexible') }}
                            </label>
                        </div>
                        <div class="form-check mb-0">
                            <input
                                :id="'scheduling_strict_'+index"
                                v-model="schedulingMode"
                                class="form-check-input"
                                type="radio"
                                value="strict"
                            >
                            <label
                                class="form-check-label"
                                :for="'scheduling_strict_'+index"
                            >
                                {{ $gettext('Strict') }}
                            </label>
                        </div>
                    </div>
                    <small class="form-text text-muted d-block mt-2">
                        {{ $gettext('Flexible prefers full songs when they fit; AutoDJ may cut at anchors only when selection cannot guarantee timing (short slots, strict mode, or no track fits the window).') }}
                    </small>
                    <div class="form-check mt-2">
                        <input
                            :id="'scheduling_loop_once_'+index"
                            v-model="row.loop_once"
                            class="form-check-input"
                            type="checkbox"
                        >
                        <label
                            class="form-check-label"
                            :for="'scheduling_loop_once_'+index"
                        >
                            {{ $gettext('Loop Once') }}
                        </label>
                    </div>
                    <div class="form-check mt-2">
                        <input
                            :id="'scheduling_prevent_requests_'+index"
                            v-model="row.prevent_requests"
                            class="form-check-input"
                            type="checkbox"
                        >
                        <label
                            class="form-check-label"
                            :for="'scheduling_prevent_requests_'+index"
                        >
                            {{ $gettext('Block Request Queue While Active') }}
                        </label>
                    </div>
                    <small class="form-text text-muted d-block mt-1">
                        {{ $gettext('While this scheduled window is active, listener requests will not be played via the automatic request queue.') }}
                    </small>
                    <small class="form-text text-muted d-block mt-1">
                        {{ $gettext('Independent of Strict/Flexible above') }}
                    </small>
                </form-markup>

                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="col-md-12"
                    :field="r$.days"
                    :label="$gettext('Scheduled Play Days of Week')"
                    :description="daysOfWeekFieldDescription"
                    :options="dayOptions"
                    :required="!isMonthlyDatePattern"
                    :disabled="isMonthlyDatePattern"
                    stacked
                />

                <div class="col-12">
                    <hr class="my-3">
                    <h6 class="text-muted mb-2">
                        {{ $gettext('Repeat') }}
                    </h6>
                </div>
                <form-group-select
                    :id="'edit_form_recurrence_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_type"
                    :label="$gettext('Repeat')"
                    :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Custom = every N weeks; Monthly = by date or specific day of week.')"
                    :options="recurrenceTypeOptions"
                />
                <form-group-field
                    v-if="row.recurrence_type === 'custom'"
                    :id="'edit_form_recurrence_interval_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_interval"
                    input-type="number"
                    min="1"
                    max="52"
                    :label="$gettext('Every (weeks)')"
                    :description="$gettext('E.g. 3 = every 3 weeks. Set Start date for correct alignment.')"
                />
                <template v-if="row.recurrence_type === 'monthly'">
                    <form-group-select
                        :id="'edit_form_recurrence_monthly_pattern_'+index"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_pattern"
                        :label="$gettext('Monthly Pattern')"
                        :options="recurrenceMonthlyPatternOptions"
                    />
                    <form-group-field
                        v-if="row.recurrence_monthly_pattern === 'date'"
                        :id="'edit_form_recurrence_monthly_day_'+index"
                        class="col-md-4"
                        :field="r$.recurrence_monthly_day"
                        input-type="number"
                        min="1"
                        max="31"
                        :label="$gettext('Day of Month')"
                        :description="$gettext('Day of the month (1–31).')"
                    />
                    <template v-if="row.recurrence_monthly_pattern === 'day_of_week'">
                        <form-group-select
                            :id="'edit_form_recurrence_monthly_week_'+index"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_week"
                            :label="$gettext('Week of Month')"
                            :description="$gettext('For monthly specific day of week.')"
                            :options="recurrenceMonthlyWeekOptions"
                        />
                    </template>
                </template>
                <form-group-select
                    :id="'edit_form_recurrence_end_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_type"
                    :label="$gettext('Stop Recurrence')"
                    :description="$gettext('Optional: stop after a number of occurrences or use End date above.')"
                    :options="recurrenceEndTypeOptions"
                />
                <form-group-field
                    v-if="row.recurrence_end_type === 'after'"
                    :id="'edit_form_recurrence_end_after_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_after"
                    input-type="number"
                    min="1"
                    :label="$gettext('Stop After (occurrences)')"
                />
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import PlaylistTime from "~/components/Common/TimeCode.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from "@regle/rules";
import {computed, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import FormMarkup from "~/components/Form/FormMarkup.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import {useAppScopedRegle} from "~/vendor/regle.ts";
import IconIcRemove from "~icons/ic/baseline-remove";

interface PlaylistScheduleRow {
    start_time: number,
    end_time: number,
    start_date: string,
    end_date: string,
    days: number[],
    loop_once: boolean,
    prevent_requests: boolean,
    /** Playlist Flexible/Strict scheduling (independent of loop_once). */
    strict_start: boolean,
    recurrence_type: string | null,
    recurrence_interval: number,
    recurrence_monthly_pattern: string | null,
    recurrence_monthly_day: number | null,
    recurrence_monthly_week: number | null,
    recurrence_monthly_day_of_week: number | null,
    recurrence_end_type: string,
    recurrence_end_after: number | null,
    recurrence_end_date: string | null,
}

const props = defineProps<{
    index: number,
}>();

/** v-model:row from parent — required so recurrence and other fields mutate the form store on submit */
const row = defineModel<PlaylistScheduleRow>('row', {required: true});

const emit = defineEmits<{
    (e: 'remove'): void
}>();

const isMonthlyDatePattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => !isMonthlyDatePattern.value);

const {$gettext} = useTranslate();

/** Flexible/Strict controls strict_start; Loop Once is a separate checkbox on loop_once. */
const schedulingMode = computed({
    get: (): 'flexible' | 'strict' => {
        return row.value.strict_start ? 'strict' : 'flexible';
    },
    set: (mode: 'flexible' | 'strict') => {
        row.value.strict_start = mode === 'strict';
    },
});

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
    row,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => row.value.recurrence_end_type !== 'after'),
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_end_after: {
            required: requiredIf(() => row.value.recurrence_end_type === 'after'),
            minValue: minValue(1),
        },
        recurrence_monthly_day: {
            required: requiredIf(
                () => row.value.recurrence_type === 'monthly' && row.value.recurrence_monthly_pattern === 'date'
            ),
        },
    },
    {
        namespace: 'stations-playlists'
    }
);

watch(
    () => row.value.recurrence_type,
    (newType: string | null) => {
        if (newType === 'biweekly') {
            row.value.recurrence_interval = 2;
        } else if (newType === 'weekly') {
            row.value.recurrence_interval = 1;
        }
    }
);

watch(
    () => [row.value.recurrence_type, row.value.recurrence_monthly_pattern] as const,
    () => {
        if (isMonthlyDatePattern.value) {
            row.value.days = [];
        }
    }
);

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

const doRemove = () => {
    emit('remove');
};
</script>
