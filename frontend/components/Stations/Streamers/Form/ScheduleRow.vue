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
                />

                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="col-md-6"
                    :field="r$.days"
                    :options="dayOptions"
                    stacked
                    :label="$gettext('Scheduled Play Days of Week')"
                    :description="$gettext('Leave blank to play on every day of the week.')"
                />

                <div class="col-12">
                    <hr class="my-3">
                    <h6 class="text-muted mb-2">
                        {{ $gettext('Recurrence') }}
                    </h6>
                </div>
                <form-group-select
                    :id="'edit_form_recurrence_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_type"
                    :label="$gettext('Recurrence Type')"
                    :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Monthly = by date or weekday; Custom = every N weeks.')"
                    :options="recurrenceTypeOptions"
                />
                <form-group-field
                    v-if="row.recurrence_type === 'biweekly' || row.recurrence_type === 'custom'"
                    :id="'edit_form_recurrence_interval_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_interval"
                    input-type="number"
                    min="1"
                    max="52"
                    :label="$gettext('Repeat Every (weeks)')"
                    :description="$gettext('E.g. 2 = every 2 weeks, 3 = every 3 weeks.')"
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
                    />
                    <template v-if="row.recurrence_monthly_pattern === 'day_of_week'">
                        <form-group-select
                            :id="'edit_form_recurrence_monthly_week_'+index"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_week"
                            :label="$gettext('Week of Month')"
                            :options="recurrenceMonthlyWeekOptions"
                        />
                        <form-group-select
                            :id="'edit_form_recurrence_monthly_dow_'+index"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_day_of_week"
                            :label="$gettext('Day of Week')"
                            :options="dayOptions"
                        />
                    </template>
                </template>
                <form-group-select
                    :id="'edit_form_recurrence_end_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_type"
                    :label="$gettext('End Condition')"
                    :options="recurrenceEndTypeOptions"
                />
                <form-group-field
                    v-if="row.recurrence_end_type === 'after'"
                    :id="'edit_form_recurrence_end_after_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_after"
                    input-type="number"
                    min="1"
                    :label="$gettext('End After (occurrences)')"
                />
                <form-group-field
                    v-if="row.recurrence_end_type === 'on_date'"
                    :id="'edit_form_recurrence_end_date_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import PlaylistTime from "~/components/Common/TimeCode.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import {required} from "@regle/rules";
import {toRef} from "vue";
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
    row: PlaylistScheduleRow,
}>();

const emit = defineEmits<{
    (e: 'remove'): void
}>();

const {r$} = useAppScopedRegle(
    toRef(props, 'row'),
    {
        start_time: {required},
        end_time: {required},
    },
    {
        namespace: 'stations-streamers'
    }
);

const {$gettext} = useTranslate();

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
    {value: 'day_of_week', text: $gettext('On weekday (e.g. 3rd Monday)')}
];

const recurrenceMonthlyWeekOptions = [
    {value: 1, text: $gettext('1st')},
    {value: 2, text: $gettext('2nd')},
    {value: 3, text: $gettext('3rd')},
    {value: 4, text: $gettext('4th')},
    {value: 5, text: $gettext('Last')}
];

const recurrenceEndTypeOptions = [
    {value: 'never', text: $gettext('Never')},
    {value: 'after', text: $gettext('After number of occurrences')},
    {value: 'on_date', text: $gettext('On date')}
];

const doRemove = () => {
    emit('remove');
};
</script>
