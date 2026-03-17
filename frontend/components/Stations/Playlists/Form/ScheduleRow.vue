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
                    :description="$gettext('To play once per day, set the start and end times to the same value.')"
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
                    :description="$gettext('If the end time is before the start time, the playlist will play overnight.')"
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
                    :description="$gettext('To set this schedule to run only within a certain date range, specify a start and end date.')"
                />

                <form-group-field
                    :id="'edit_form_end_date_'+index"
                    class="col-md-4"
                    :field="r$.end_date"
                    input-type="date"
                    :label="$gettext('End Date')"
                    :description="$gettext('Use this and Start date to limit when the schedule runs. Recurrence (e.g. bi-weekly) still uses this as the last day.')"
                />

                <form-group-checkbox
                    :id="'edit_form_loop_once_'+index"
                    class="col-md-4"
                    :field="r$.loop_once"
                    :label="$gettext('Loop Once')"
                    :description="$gettext('Only loop through playlist once.')"
                />

                <form-group-multi-check
                    :id="'edit_form_days_'+index"
                    class="col-md-6"
                    :field="r$.days"
                    :label="$gettext('Scheduled Play Days of Week')"
                    :description="$gettext('Leave blank to play on every day of the week.')"
                    :options="dayOptions"
                    stacked
                />

                <div class="col-12">
                    <hr class="my-3">
                    <h6 class="text-muted mb-2">
                        {{ $gettext('Repeat') }}
                    </h6>
                    <p class="text-muted small mb-2">
                        {{ $gettext('How often this time slot repeats. Use Start date above for bi-weekly or custom so the pattern aligns correctly. Use End date above to stop the schedule on a specific date.') }}
                    </p>
                </div>
                <form-group-select
                    :id="'edit_form_recurrence_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_type"
                    :label="$gettext('Repeat')"
                    :description="$gettext('Weekly = every week; Bi-weekly = every 2 weeks; Custom = every N weeks; Monthly = by date or weekday (e.g. 1st Monday).')"
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
                        :description="$gettext('Day of the month (1–31). E.g. 15 = on the 15th of each month. Scheduled Play Days of Week above are ignored for this pattern.')"
                    />
                    <template v-if="row.recurrence_monthly_pattern === 'day_of_week'">
                        <form-group-select
                            :id="'edit_form_recurrence_monthly_week_'+index"
                            class="col-md-4"
                            :field="r$.recurrence_monthly_week"
                            :label="$gettext('Week of Month')"
                            :description="$gettext('Use the Scheduled Play Days of Week checkboxes above to pick the day (e.g. Monday for 3rd Monday).')"
                            :options="recurrenceMonthlyWeekOptions"
                        />
                    </template>
                </template>
                <form-group-select
                    :id="'edit_form_recurrence_end_type_'+index"
                    class="col-md-4"
                    :field="r$.recurrence_end_type"
                    :label="$gettext('Stop Recurrence')"
                    :description="$gettext('Optional: stop after a number of occurrences. Otherwise use End date above to limit the range.')"
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
import {required} from "@regle/rules";
import {toRef} from "vue";
import {useTranslate} from "~/vendor/gettext";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
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
    row: PlaylistScheduleRow
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
        namespace: 'stations-playlists'
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
    {value: 'never', text: $gettext('Never (use End date above to limit range)')},
    {value: 'after', text: $gettext('After number of occurrences')}
];

const doRemove = () => {
    emit('remove');
};
</script>
