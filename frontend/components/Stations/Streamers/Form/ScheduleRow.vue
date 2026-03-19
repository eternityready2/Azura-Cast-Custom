<template>
    <div class="card mb-3">
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
                >
                    <template #label>
                        {{ $gettext('Start Time') }}
                    </template>
                    <template #default="{id, model}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                        />
                    </template>
                </form-group-field>

                <form-group-field
                    :id="'edit_form_end_time_'+index"
                    class="col-md-4"
                    :field="r$.end_time"
                >
                    <template #label>
                        {{ $gettext('End Time') }}
                    </template>
                    <template #description>
                        {{
                            $gettext('If the end time is before the start time, the schedule entry will continue overnight.')
                        }}
                    </template>
                    <template #default="{id, model}">
                        <playlist-time
                            :id="id"
                            v-model="model.$model"
                        />
                    </template>
                </form-group-field>

                <form-markup
                    id="station_time_zone"
                    class="col-md-4"
                >
                    <template #label>
                        {{ $gettext('Station Time Zone') }}
                    </template>

                    <time-zone />
                </form-markup>

                <form-group-field
                    :id="'edit_form_start_date_'+index"
                    class="col-md-4"
                    :field="r$.start_date"
                    input-type="date"
                    :label="$gettext('Start Date')"
                    :description="$gettext('To set this schedule to run only within a certain date range, specify a start and end date.')"
                />

                <form-group-field
                    :id="'edit_form_end_date_'+index"
                    class="col-md-4"
                    :field="r$.end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                    :description="$gettext('Use with Start date to limit when the schedule runs.')"
                />

                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="col-md-6"
                    :field="r$.days"
                    :options="dayOptions"
                    stacked
                    :label="$gettext('Scheduled Play Days of Week')"
                    :description="daysOfWeekFieldDescription"
                    :disabled="isMonthlyDatePattern"
                />

                <div class="col-12">
                    <hr class="my-3">
                    <h6 class="text-muted mb-2">
                        {{ $gettext('Repeat') }}
                    </h6>
                    <p class="text-muted small mb-2">
                        {{ $gettext('How often this time slot repeats. Use Start date above for bi-weekly or custom. Use End date above to stop on a specific date.') }}
                    </p>
                </div>
                <form-group-select
                    :id="'edit_form_recurrence_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_type"
                    :label="$gettext('Repeat')"
                    :description="$gettext('Weekly, Bi-weekly, Custom (every N weeks), or Monthly (by date or specific day of week).')"
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
                        :description="$gettext('Days of week above are disabled for this pattern.')"
                    />
                    <template v-if="row.recurrence_monthly_pattern === 'day_of_week'">
                        <form-group-select
                            :id="'edit_form_recurrence_monthly_week_'+index"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_week"
                            :label="$gettext('Week of Month')"
                            :description="$gettext('Use Scheduled Play Days of Week above: each selected day gets that week-of-month slot (e.g. 1st + Tue+Wed = 1st Tuesday and 1st Wednesday each month).')"
                            :options="recurrenceMonthlyWeekOptions"
                        />
                    </template>
                </template>
                <form-group-select
                    :id="'edit_form_recurrence_end_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_type"
                    :label="$gettext('Stop Recurrence')"
                    :description="$gettext('Optional: stop after N occurrences. Otherwise use End date above.')"
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
    </div>
</template>

<script setup lang="ts">
import PlaylistTime from "~/components/Common/TimeCode.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import {required} from "@regle/rules";
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

const {$gettext} = useTranslate();

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used when monthly pattern is "On day of month" — pick the calendar day below instead.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For monthly "specific day of week", select one or more days; each gets that week-of-month (e.g. 1st + Mon–Wed). For other repeat types, leave blank for every day.');
    }
    return $gettext('Leave blank to play on every day of the week.');
});

const {r$} = useAppScopedRegle(
    row,
    {
        start_time: {required},
        end_time: {required},
    },
    {
        namespace: 'stations-streamers'
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
