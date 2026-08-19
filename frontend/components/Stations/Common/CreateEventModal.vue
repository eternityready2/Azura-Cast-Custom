<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="modalTitle"
        :error="error"
        :disable-save-button="!isFormValid"
        @submit="doSave"
        @hidden="clearForm"
    >
        <!-- Source -->
        <div v-if="!isScopedMode" class="mb-3">
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
                <option value="smart_block">
                    {{ $gettext('Smart Block') }}
                </option>
                <option value="web_stream">
                    {{ $gettext('Web / Remote Stream') }}
                </option>
            </select>
        </div>

        <!-- Entity selection -->
        <div v-if="!isScopedMode" class="mb-3">
            <label class="form-label fw-semibold">
                {{
                    form.source === 'clock_wheel'
                        ? $gettext('Clock Wheel')
                        : form.source === 'smart_block'
                            ? $gettext('Smart Block')
                            : form.source === 'web_stream'
                                ? $gettext('Web / Remote Stream')
                                : $gettext('Playlist')
                }}
            </label>
            <select
                v-model="form.entity_id"
                class="form-select"
                :disabled="currentEntityOptions.length === 0"
                @change="onEntityChange"
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

        <div
            v-if="isPlaylistSchedule"
            class="mb-3"
        >
            <div class="form-check">
                <input
                    id="edit_form_is_emergency"
                    v-model="scheduleRow.is_emergency"
                    class="form-check-input"
                    type="checkbox"
                >
                <label class="form-check-label" for="edit_form_is_emergency">
                    {{ $gettext('Emergency override') }}
                </label>
            </div>
            <small class="form-text text-warning">
                {{ $gettext('While this schedule is active, clock wheel AutoDJ will not run. Use for breaking news or other must-play windows.') }}
            </small>
        </div>

        <!-- Schedule Row - Time section -->
        <div class="row g-3 mb-3">
            <form-group-field
                id="edit_form_start_time"
                class="col-md-4"
                :field="r$.start_time"
                :label="$gettext('Start Time')"
                :description="$gettext('To play once per day, set start and end to the same value.')"
            >
                <template #default="{id, model, fieldClass}">
                    <am-pm-time-input
                        :input-id="id"
                        v-model="model.$model"
                        :field-class="fieldClass"
                    />
                </template>
            </form-group-field>

            <form-group-field
                id="edit_form_end_time"
                class="col-md-4"
                :field="r$.end_time"
                :label="$gettext('End Time')"
                :description="$gettext('If end is before start, the event plays overnight. Back-to-back events are fine -- e.g. end this one at 2:00 PM and start the next at 2:00 PM.')"
            >
                <template #default="{id, model, fieldClass}">
                    <am-pm-time-input
                        :input-id="id"
                        v-model="model.$model"
                        :field-class="fieldClass"
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
                        @input="updateDurationFromHours"
                    >
                    <span class="input-group-text">:</span>
                    <input
                        v-model.number="durationMinutes"
                        type="number"
                        class="form-control"
                        min="0"
                        max="59"
                        placeholder="MM"
                        @input="updateDurationFromMinutes"
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
                :description="isRecurring ? $gettext('Required. First date this schedule becomes active; combine with End Date/Repeat below to control when it runs.') : $gettext('Required. This is a one-time event -- it plays only on this date.')"
            />

            <form-group-field
                id="edit_form_end_date"
                class="col-md-4"
                :field="r$.end_date"
                input-type="date"
                :label="$gettext('End Date')"
                :description="!isRecurring
                    ? $gettext('Locked to Start Date -- this is a one-time event. Check Recurring above to enable a different End Date.')
                    : (scheduleRow.recurrence_end_type === 'after'
                        ? $gettext('Not used when stopping after a number of occurrences (see below).')
                        : $gettext('Use with Start date to limit when the schedule runs. Recurrence uses this as the last day.'))"
                :required="isRecurring && scheduleRow.recurrence_end_type !== 'after'"
                :input-attrs="{ disabled: !isRecurring || scheduleRow.recurrence_end_type === 'after' }"
            />

            <div
                v-if="endDateSameAsStart"
                class="col-12"
            >
                <div class="alert alert-danger py-2 px-3 mb-0 small">
                    ⚠ {{ $gettext('End Date must be after Start Date for a recurring event. The event would end immediately and never repeat.') }}
                </div>
            </div>

            <form-markup
                v-if="isClockWheelSchedule"
                id="edit_form_clock_wheel_scheduling"
                class="col-md-4"
                :label="$gettext('Clock Wheel Timing')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="clock_wheel_scheduling_flexible"
                            v-model="clockWheelScheduleMode"
                            class="form-check-input"
                            type="radio"
                            value="flexible"
                        >
                        <label class="form-check-label" for="clock_wheel_scheduling_flexible">
                            {{ $gettext('Flexible') }}
                        </label>
                    </div>
                    <div class="form-check mb-0">
                        <input
                            id="clock_wheel_scheduling_strict"
                            v-model="clockWheelScheduleMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="clock_wheel_scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <small class="form-text text-muted d-block mt-2">
                    {{ $gettext('Flexible prefers full songs when they fit; AutoDJ may cut at anchors only when selection cannot guarantee timing (short slots, strict mode, or no track fits the window).') }}
                </small>
            </form-markup>

            <form-markup
                v-if="isPlaylistSchedule"
                id="edit_form_scheduling"
                class="col-md-4"
                :label="$gettext('Start Timing')"
            >
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check mb-0">
                        <input
                            id="scheduling_flexible"
                            v-model="startTimingMode"
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
                            v-model="startTimingMode"
                            class="form-check-input"
                            type="radio"
                            value="strict"
                        >
                        <label class="form-check-label" for="scheduling_strict">
                            {{ $gettext('Strict') }}
                        </label>
                    </div>
                </div>
                <small class="form-text text-muted d-block mt-2">
                    {{ $gettext('Flexible waits for the currently playing track to finish before starting. Strict cuts the current track to start exactly on time.') }}
                </small>
                <div class="form-check mt-3">
                    <input
                        id="scheduling_loop_once"
                        v-model="scheduleRow.loop_once"
                        class="form-check-input"
                        type="checkbox"
                    >
                    <label class="form-check-label" for="scheduling_loop_once">
                        {{ $gettext('Loop Once') }}
                    </label>
                </div>
                <small class="form-text text-muted d-block">
                    {{ $gettext('Independent of Start Timing above -- controls whether this playlist loops back through its media during its window, rather than playing through once.') }}
                </small>
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
                {{ $gettext('Check this to repeat the event on a schedule (weekly by default, or bi-weekly/monthly/custom below). Leave unchecked for a one-time event that only plays on its exact Start/End Date.') }}
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

        <template
            v-if="editingScheduleId !== null"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                :disabled="loading"
                @click="doDelete"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || !isFormValid"
                @click="doSave"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import AmPmTimeInput from '~/components/Common/AmPmTimeInput.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import FormGroupMultiCheck from '~/components/Form/FormGroupMultiCheck.vue';
import FormGroupSelect from '~/components/Form/FormGroupSelect.vue';
import FormMarkup from '~/components/Form/FormMarkup.vue';
import TimeZone from '~/components/Stations/Common/TimeZone.vue';
import {applyIf, minLength, minValue, required, requiredIf, withMessage} from '@regle/rules';
import {createRule} from '@regle/core';
import {useAppScopedRegle} from '~/vendor/regle.ts';
import {ref, computed, onMounted, watch, useTemplateRef} from 'vue';
import {toRefs} from '@vueuse/core';
import {DateTime} from 'luxon';
import {useStationData} from '~/functions/useStationQuery.ts';
import {useTranslate} from '~/vendor/gettext';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useDialog} from '~/components/Common/Dialogs/useDialog.ts';
import {
    type PlaylistScheduleRow,
    createScheduleItemDefaults,
} from '~/components/Stations/Common/scheduleItemDefaults.ts';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';
import {scheduleTimeWindowForHourOfDay} from '~/functions/amPmTime.ts';

const {$gettext} = useTranslate();
const {axios} = useAxios();

const stationData = useStationData();
const {timezone: stationTimezone} = toRefs(stationData);
const {getStationApiUrl} = useApiRouter();
const {notifySuccess} = useNotify();
const {confirmDelete} = useDialog();

const emit = defineEmits<{
    relist: [];
}>();

interface EntityOption {
    id: number;
    name: string;
    self_url: string;
    is_smart_block?: boolean;
    source?: string;
}

interface ClockWheelOption extends EntityOption {
    /** Set on daypart-generated hourly wheels (0–23). */
    hour_of_day: number | null;
}

const playlists = ref<EntityOption[]>([]);
const webStreams = ref<EntityOption[]>([]);
const clockWheels = ref<ClockWheelOption[]>([]);

onMounted(async () => {
    const [plResp, webResp, cwResp] = await Promise.all([
        axios.get(getStationApiUrl('/playlists').value),
        axios.get(getStationApiUrl('/playlists').value, {
            params: {rowCount: -1, include_remote_url: 1},
        }),
        axios.get(getStationApiUrl('/clock-wheels').value),
    ]);

    playlists.value = (plResp.data as Array<Record<string, unknown>>).map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
        is_smart_block: Boolean(p.is_smart_block),
    }));

    const webRows = Array.isArray(webResp.data)
        ? webResp.data
        : ((webResp.data as {rows?: Array<Record<string, unknown>>}).rows ?? []);

    webStreams.value = webRows.map((p) => ({
        id: p.id as number,
        name: p.name as string,
        self_url: (p.links as Record<string, string>).self,
        is_smart_block: false,
        source: p.source as string,
    }));

    clockWheels.value = (cwResp.data as Array<Record<string, unknown>>).map((cw) => ({
        id: cw.id as number,
        name: cw.name as string,
        self_url: (cw.links as Record<string, string>).self,
        hour_of_day: cw.hour_of_day != null ? Number(cw.hour_of_day) : null,
    }));
});

const blankForm = () => ({
    source: 'clock_wheel' as 'playlist' | 'smart_block' | 'clock_wheel' | 'web_stream',
    entity_id: null as number | null,
});

const form = ref(blankForm());

/** Both 'playlist' and 'smart_block' sources ultimately POST to the same /playlist/{id}
 *  entity API endpoint -- a Smart Block is just a playlist under the hood. This keeps
 *  every one of the (several) places below that need to map source -> API entity type
 *  in agreement, instead of repeating the ternary and risking one getting missed. */
const apiEntityType = (source: string): 'playlist' | 'clock-wheel' =>
    'clock_wheel' === source ? 'clock-wheel' : 'playlist';

const startTimingMode = ref<'flexible' | 'strict'>('flexible');
const clockWheelScheduleMode = ref<'flexible' | 'strict'>('flexible');

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

// When loading an existing schedule item, don't treat the programmatic
// isRecurring assignment like the user just toggled Recurring on/off.
// Without this guard, the watcher below clears the loaded end_date.
const isHydratingExistingSchedule = ref(false);

// Checking "Recurring" requires an explicit repeat pattern (defaults to Weekly)
// rather than silently staying null. Unchecking clears it back to plain,
// non-recurring behavior (event only plays on its exact Start/End Date).
watch(isRecurring, (recurring) => {
    if (isHydratingExistingSchedule.value) {
        return;
    }
    if (recurring) {
        if (!scheduleRow.value.recurrence_type) {
            scheduleRow.value.recurrence_type = 'weekly';
        }
        // Clear end_date when switching to recurring — user must deliberately
        // pick an end date, so it can't accidentally save as the same day as start.
        scheduleRow.value.end_date = '';
    } else {
        scheduleRow.value.recurrence_type = null;
        scheduleRow.value.recurrence_monthly_pattern = null;
        scheduleRow.value.recurrence_monthly_day = null;
        scheduleRow.value.recurrence_monthly_week = null;
        scheduleRow.value.recurrence_monthly_day_of_week = null;
        scheduleRow.value.recurrence_end_type = 'never';
        scheduleRow.value.recurrence_end_after = null;
        scheduleRow.value.days = [];
        scheduleRow.value.end_date = scheduleRow.value.start_date;
    }
}, {flush: 'sync'});

// Keep End Date locked to Start Date for one-time (non-recurring) events at all
// times -- not just at the moment Recurring is unchecked -- so later changing
// Start Date can never leave a stale End Date that predates it.
watch(
    () => scheduleRow.value.start_date,
    (newStartDate) => {
        if (!isRecurring.value) {
            scheduleRow.value.end_date = newStartDate;
        }
    }
);

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
    const newEndTime = endHours * 100 + endMinutes;

    // Write through r$.end_time.$model (the same path the End Time field itself
    // writes to) rather than the raw scheduleRow object directly -- the field's
    // validation wrapper may not reactively pick up a direct object mutation.
    r$.end_time.$model = newEndTime;
    scheduleRow.value.end_time = newEndTime;
};

const updateDurationFromHours = () => updateDuration();
const updateDurationFromMinutes = () => updateDuration();

// Compute Duration display (hours/minutes) from the actual loaded start/end times,
// so editing an existing event shows its real duration instead of the 1:00 default.
const syncDurationFromTimes = () => {
    const startTime = scheduleRow.value.start_time;
    const endTime = scheduleRow.value.end_time;
    const startTotalMinutes = Math.floor(startTime / 100) * 60 + (startTime % 100);
    let endTotalMinutes = Math.floor(endTime / 100) * 60 + (endTime % 100);

    if (endTotalMinutes < startTotalMinutes) {
        endTotalMinutes += 24 * 60;
    }

    const diffMinutes = endTotalMinutes - startTotalMinutes;
    durationHours.value = Math.floor(diffMinutes / 60);
    durationMinutes.value = diffMinutes % 60;
};

const applyClockWheelHourToSchedule = (entityId: number | null) => {
    if (entityId == null) {
        return;
    }

    const wheel = clockWheels.value.find((w) => w.id === entityId);
    if (wheel?.hour_of_day == null) {
        return;
    }

    const {start_time, end_time} = scheduleTimeWindowForHourOfDay(wheel.hour_of_day);
    scheduleRow.value.start_time = start_time;
    scheduleRow.value.end_time = end_time;
    durationHours.value = 1;
    durationMinutes.value = 0;
};

const onEntityChange = () => {
    if (isClockWheelSchedule.value) {
        applyClockWheelHourToSchedule(form.value.entity_id);
    }
};

const currentEntityOptions = computed(() => {
    if (form.value.source === 'clock_wheel') {
        return clockWheels.value;
    }
    if (form.value.source === 'web_stream') {
        return webStreams.value;
    }
    // 'playlist' shows regular playlists; 'smart_block' shows just the Smart Blocks --
    // two focused pickers over the same underlying /playlists collection.
    return playlists.value.filter(
        (p) => Boolean(p.is_smart_block) === (form.value.source === 'smart_block')
    );
});

const isPlaylistSchedule = computed(() =>
    form.value.source === 'playlist'
    || form.value.source === 'smart_block'
    || form.value.source === 'web_stream'
);
const isClockWheelSchedule = computed(() => form.value.source === 'clock_wheel');

// Auto-select first entity whenever options change or source changes
watch(currentEntityOptions, (opts) => {
    if (opts.length > 0 && (form.value.entity_id === null || !opts.find(e => e.id === form.value.entity_id))) {
        form.value.entity_id = opts[0].id;
    }
}, {immediate: true});

watch(
    () => form.value.entity_id,
    (entityId, previousId) => {
        if (!isClockWheelSchedule.value || entityId == null) {
            return;
        }

        // Editing an existing event: only adjust times when the user picks another wheel.
        if (editingScheduleId.value !== null && previousId == null) {
            return;
        }

        applyClockWheelHourToSchedule(entityId);
    }
);

watch(startTimingMode, (mode) => {
    if (!isPlaylistSchedule.value) {
        return;
    }
    scheduleRow.value.strict_start = mode === 'strict';
});

watch(
    () => form.value.source,
    (source) => {
        if (source === 'clock_wheel') {
            scheduleRow.value.loop_once = false;
            scheduleRow.value.strict_start = false;
            scheduleRow.value.clock_wheel_mode = clockWheelScheduleMode.value;
            scheduleRow.value.is_emergency = false;
        }
    }
);

watch(clockWheelScheduleMode, (mode) => {
    if (isClockWheelSchedule.value) {
        scheduleRow.value.clock_wheel_mode = mode;
    }
});

// Regle validation for schedule row
const isMonthlyDatePattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'date'
);

const isMonthlyDayOfWeekPattern = computed(
    () => scheduleRow.value.recurrence_type === 'monthly' && scheduleRow.value.recurrence_monthly_pattern === 'day_of_week'
);

const requiresDaysOfWeek = computed(() => isRecurring.value && !isMonthlyDatePattern.value);

const daysOfWeekFieldDescription = computed(() => {
    if (isMonthlyDatePattern.value) {
        return $gettext('Not used when monthly pattern is "On day of month" — pick the calendar day below instead.');
    }
    if (isMonthlyDayOfWeekPattern.value) {
        return $gettext('For monthly "specific day of week", select one or more days; each gets that week-of-month (e.g. 1st + Mon–Wed).');
    }
    return $gettext('Select at least one day of the week.');
});

// Custom rule: for recurring events, end date must be strictly after start date.
// Using createRule so the validator function has access to external reactive state
// (isRecurring, scheduleRow.start_date) without being limited to just the field value.
const endDateAfterStart = createRule({
    validator: (endDate: unknown) => {
        if (!isRecurring.value) return true;
        const end = String(endDate ?? '').trim();
        const start = (scheduleRow.value.start_date ?? '').trim();
        if (!end || !start) return true; // required validator handles the empty case
        // YYYY-MM-DD string comparison is safe for ISO dates
        return end > start;
    },
    message: () => $gettext('End Date must be after Start Date for recurring events.'),
});

const {r$} = useAppScopedRegle(
    scheduleRow,
    {
        start_time: {required},
        end_time: {required},
        start_date: {required},
        end_date: {
            required: requiredIf(() => isRecurring.value && scheduleRow.value.recurrence_end_type !== 'after'),
            endDateAfterStart,
        },
        days: {
            minLength: withMessage(
                applyIf(requiresDaysOfWeek, minLength(1)),
                () => $gettext('Select at least one day of the week.')
            ),
        },
        recurrence_type: {
            required: requiredIf(() => isRecurring.value),
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

// Keep Duration display live and consistent: whenever Start Time or End Time
// is edited directly (not via the Duration boxes themselves), recalculate
// Duration to reflect the new gap. Duration only ever drives End Time in the
// other direction (see updateDuration above) -- this just mirrors that so
// editing any one of the three fields keeps the other two in sync.
watch(() => scheduleRow.value.start_time, () => {
    syncDurationFromTimes();
});
watch(() => scheduleRow.value.end_time, () => {
    syncDurationFromTimes();
});

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

// True when end_date is set for a recurring event but is NOT after start_date.
// Checked both here (to disable Save) and in the template (to show a warning).
const endDateSameAsStart = computed(() =>
    isRecurring.value
    && !!scheduleRow.value.end_date
    && !!scheduleRow.value.start_date
    && scheduleRow.value.end_date <= scheduleRow.value.start_date
);

const isFormValid = computed(() =>
    form.value.entity_id !== null &&
    !r$.$invalid &&
    !endDateSameAsStart.value
);

const onSourceChange = () => {
    form.value.entity_id = null;
    if (form.value.source === 'clock_wheel') {
        scheduleRow.value.loop_once = false;
        scheduleRow.value.strict_start = false;
        clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
    }
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

const editingScheduleId = ref<number | null>(null);

const modalTitle = computed(() =>
    editingScheduleId.value !== null
        ? $gettext('Edit Event')
        : $gettext('Create Event')
);

const applyCalendarTimesToRow = (start: Date, end?: Date) => {
    // IMPORTANT: the calendar displays (and drag-drop positions events) in the
    // STATION's configured timezone, not necessarily the browser's local timezone.
    // Reading .getHours()/.getMinutes() directly off the JS Date object returns
    // components in the *browser's* local time, and .toISOString() returns the
    // date in UTC -- mixing those two was the bug that caused a dropped/clicked
    // slot to end up saved at a different time than where it visually landed
    // whenever the station's timezone differs from the visitor's own. Converting
    // explicitly into the station's timezone first keeps date and time consistent
    // with what was actually clicked/dropped on the calendar.
    const startInStationTz = DateTime.fromJSDate(start, {zone: stationTimezone.value});

    scheduleRow.value.start_date = startInStationTz.toFormat('yyyy-MM-dd');
    scheduleRow.value.end_date = startInStationTz.toFormat('yyyy-MM-dd');
    scheduleRow.value.start_time = Number(startInStationTz.toFormat('HHmm'));

    if (end) {
        const endInStationTz = DateTime.fromJSDate(end, {zone: stationTimezone.value});
        scheduleRow.value.end_time = Number(endInStationTz.toFormat('HHmm'));
    }
};

const apiScheduleItemToRow = (item: Record<string, unknown>): PlaylistScheduleRow => {
    const endType = (item.recurrence_end_type as string | undefined) ?? 'never';
    const recurrenceType = item.recurrence_type as string | null | undefined;

    const row: PlaylistScheduleRow = {
        start_time: Number(item.start_time),
        end_time: Number(item.end_time),
        start_date: String(item.start_date ?? ''),
        end_date: String(item.end_date ?? ''),
        days: normalizeStationScheduleDays(item.days),
        loop_once: Boolean(item.loop_once),
        is_emergency: Boolean(item.is_emergency),
        strict_start: Boolean(item.strict_start),
        clock_wheel_mode: (item.clock_wheel_mode === 'strict' ? 'strict' : 'flexible') as 'flexible' | 'strict',
        recurrence_type: recurrenceType ?? null,
        recurrence_interval: Number(item.recurrence_interval ?? 1),
        recurrence_monthly_pattern: (item.recurrence_monthly_pattern as string | null) ?? null,
        recurrence_monthly_day: item.recurrence_monthly_day != null ? Number(item.recurrence_monthly_day) : null,
        recurrence_monthly_week: item.recurrence_monthly_week != null ? Number(item.recurrence_monthly_week) : null,
        recurrence_monthly_day_of_week: item.recurrence_monthly_day_of_week != null
            ? Number(item.recurrence_monthly_day_of_week)
            : null,
        recurrence_end_type: endType === 'on_date' ? 'never' : endType,
        recurrence_end_after: endType === 'after' && item.recurrence_end_after != null
            ? Number(item.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (
        row.recurrence_type === 'monthly'
        && row.recurrence_monthly_pattern === 'day_of_week'
        && row.recurrence_monthly_day_of_week != null
        && row.days.length === 0
    ) {
        row.days = [row.recurrence_monthly_day_of_week];
    }

    return row;
};

const buildSchedulePayload = (
    row: PlaylistScheduleRow,
    scheduleId?: number
): PlaylistScheduleRow & {id?: number} => {
    const out: PlaylistScheduleRow & {id?: number} = {
        ...row,
        end_date: row.end_date || row.start_date,
        recurrence_type: row.recurrence_type,
        recurrence_interval: (row.recurrence_type === 'biweekly' ? 2 : Number(row.recurrence_interval)) || 1,
        recurrence_end_type: row.recurrence_end_type ?? 'never',
        recurrence_end_after: (row.recurrence_end_type === 'after' && row.recurrence_end_after != null)
            ? Number(row.recurrence_end_after)
            : null,
        recurrence_end_date: null,
    };

    if (out.recurrence_end_type === 'after') {
        out.end_date = '';
    }

    const normalizedDays = normalizeStationScheduleDays(row.days);
    if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date') {
        out.days = [];
    } else {
        out.days = normalizedDays;
    }

    if (
        out.recurrence_type === 'monthly'
        && out.recurrence_monthly_pattern === 'day_of_week'
        && normalizedDays.length > 0
    ) {
        out.recurrence_monthly_day_of_week = normalizedDays[0];
    }

    if (scheduleId !== undefined) {
        out.id = scheduleId;
    }

    return out;
};

const isScopedMode = ref(false);

const clearForm = () => {
    form.value = blankForm();
    startTimingMode.value = 'flexible';
    clockWheelScheduleMode.value = 'flexible';
    scheduleRow.value = createScheduleItemDefaults();
    isRecurring.value = false;
    error.value = null;
    editingScheduleId.value = null;
    isScopedMode.value = false;
};

const open = () => {
    clearForm();
    // If options are already loaded, auto-select the first one (watch won't re-fire if options didn't change)
    if (currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }
    ($modal.value as any)?.show();
};

const openForEdit = async (event: EventImpl) => {
    clearForm();

    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;
    editingScheduleId.value = Number.isFinite(scheduleId) ? scheduleId : null;

    if (editUrl?.includes('/clock-wheel/')) {
        form.value.source = 'clock_wheel';
    } else if (event.extendedProps.source === 'remote_url') {
        form.value.source = 'web_stream';
    } else {
        form.value.source = 'playlist';
    }

    if (editUrl) {
        const m = editUrl.match(/\/(playlist|clock-wheel)\/(\d+)/);
        if (m?.[2]) {
            form.value.entity_id = Number(m[2]);

            // If this is actually a Smart Block (not a regular playlist), reflect that
            // in the Source dropdown too, rather than showing it as a generic Playlist.
            if (form.value.source === 'playlist') {
                const matchedPlaylist = playlists.value.find((p) => p.id === form.value.entity_id);
                if (matchedPlaylist?.is_smart_block) {
                    form.value.source = 'smart_block';
                }
            }
        }
    }

    if (!form.value.entity_id && currentEntityOptions.value.length > 0) {
        form.value.entity_id = currentEntityOptions.value[0].id;
    }

    ($modal.value as any)?.show();

    const start = event.start;
    const end = event.end ?? undefined;

    if (form.value.entity_id && editingScheduleId.value !== null) {
        loading.value = true;
        error.value = null;

        try {
            const entityType = apiEntityType(form.value.source);
            const entityApiUrl = getStationApiUrl(`/${entityType}/${form.value.entity_id}`).value;
            const {data: entityData} = await axios.get(entityApiUrl);
            const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
            const existing = items.find((row) => Number(row.id) === editingScheduleId.value);

            if (existing) {
                isHydratingExistingSchedule.value = true;
                scheduleRow.value = apiScheduleItemToRow(existing);
                isRecurring.value = existing.recurrence_type != null && existing.recurrence_type !== '';
                isHydratingExistingSchedule.value = false;

                syncDurationFromTimes();

                if (form.value.source === 'clock_wheel') {
                    scheduleRow.value.loop_once = false;
                    clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
                } else {
                    startTimingMode.value = scheduleRow.value.strict_start ? 'strict' : 'flexible';
                }
            } else if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } catch (e: unknown) {
            const err = e as {response?: {data?: {message?: string}}};
            error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
            if (start) {
                applyCalendarTimesToRow(start, end);
                syncDurationFromTimes();
            }
        } finally {
            loading.value = false;
        }
    } else if (start) {
        applyCalendarTimesToRow(start, end);
    }
};

// Used from the Playlist/Clock Wheel edit modals' own "Schedule" tab, where
// we already know exactly which item we're scheduling -- no FullCalendar
// event object involved, so no Source/Entity dropdown needed at all.
const openScopedForCreate = (
    source: 'playlist' | 'clock_wheel' | 'web_stream',
    entityId: number
) => {
    clearForm();
    isScopedMode.value = true;
    form.value.source = source;
    form.value.entity_id = entityId;
    ($modal.value as any)?.show();
};

const openScopedForEdit = async (
    source: 'playlist' | 'clock_wheel' | 'web_stream',
    entityId: number,
    scheduleId: number,
) => {
    clearForm();
    isScopedMode.value = true;
    form.value.source = source;
    form.value.entity_id = entityId;
    editingScheduleId.value = scheduleId;

    ($modal.value as any)?.show();

    loading.value = true;
    error.value = null;

    try {
        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;
        const {data: entityData} = await axios.get(entityApiUrl);
        const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
        const existing = items.find((row) => Number(row.id) === scheduleId);

        if (existing) {
            isHydratingExistingSchedule.value = true;
            scheduleRow.value = apiScheduleItemToRow(existing);
            isRecurring.value = existing.recurrence_type != null && existing.recurrence_type !== '';
            isHydratingExistingSchedule.value = false;

            syncDurationFromTimes();

            if (source === 'clock_wheel') {
                scheduleRow.value.loop_once = false;
                clockWheelScheduleMode.value = scheduleRow.value.clock_wheel_mode ?? 'flexible';
            } else {
                startTimingMode.value = scheduleRow.value.strict_start ? 'strict' : 'flexible';
            }
        }
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const doSave = async () => {
    if (!form.value.entity_id) return;

    loading.value = true;
    error.value = null;

    try {
        // Build URL using getStationApiUrl to avoid Docker-internal host issues
        // Note: individual endpoints use singular: /playlist/{id} and /clock-wheel/{id}
        const entityType = apiEntityType(form.value.source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${form.value.entity_id}`).value;

        // Fetch current entity data
        const {data: entityData} = await axios.get(entityApiUrl);

        const newScheduleItem = buildSchedulePayload(
            scheduleRow.value,
            editingScheduleId.value ?? undefined
        );
        if (form.value.source === 'clock_wheel') {
            newScheduleItem.loop_once = false;
            newScheduleItem.strict_start = false;
            newScheduleItem.clock_wheel_mode = scheduleRow.value.clock_wheel_mode ?? 'flexible';
        }

        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        let updatedScheduleItems: unknown[];
        if (editingScheduleId.value !== null) {
            let replaced = false;
            updatedScheduleItems = existingScheduleItems.map((row: any) => {
                if (row?.id === editingScheduleId.value) {
                    replaced = true;
                    return newScheduleItem;
                }
                return row;
            });

            if (!replaced) {
                updatedScheduleItems = [...updatedScheduleItems, newScheduleItem];
            }
        } else {
            updatedScheduleItems = [...existingScheduleItems, newScheduleItem];
        }

        // Only send schedule_items — a full entity PUT includes relation arrays (e.g. podcasts)
        // that the serializer cannot denormalize back into Doctrine collections.
        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess(editingScheduleId.value !== null ? $gettext('Event updated.') : $gettext('Event created.'));
        ($modal.value as any)?.hide();
        emit('relist');
    } catch (e: unknown) {
        const err = e as {response?: {data?: {message?: string}}};
        error.value = err?.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const close = () => {
    ($modal.value as any)?.hide();
};

const doDelete = async () => {
    if (!form.value.entity_id || editingScheduleId.value === null) {
        return;
    }

    // Capture these before closing the modal -- @hidden triggers clearForm(),
    // which resets the reactive form/editingScheduleId back to blank.
    const entityId = form.value.entity_id;
    const source = form.value.source;
    const scheduleId = editingScheduleId.value;

    // Close first, matching the pattern used by MediaCategories/EditModal.vue --
    // opening the confirm dialog while this modal is still open leaves it
    // stacked behind the modal (same z-index, this modal painted later) until
    // this modal is dismissed.
    close();

    const {value} = await confirmDelete({
        title: $gettext('Delete this scheduled event?'),
    });

    if (!value) {
        return;
    }

    try {
        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;

        const {data: entityData} = await axios.get(entityApiUrl);
        const existingScheduleItems = (entityData.schedule_items as unknown[]) ?? [];

        const updatedScheduleItems = existingScheduleItems.filter(
            (row: any) => row?.id !== scheduleId
        );

        await axios.put(entityApiUrl, {
            schedule_items: updatedScheduleItems,
        });

        notifySuccess($gettext('Event deleted.'));
        emit('relist');
    } catch {
        // Errors are already surfaced globally via the axios response interceptor.
    }
};

const openForDrop = (
    entityId: number,
    start: Date,
    end?: Date,
    source: 'playlist' | 'smart_block' | 'clock_wheel' = 'playlist',
) => {
    clearForm();
    form.value.source = source;
    form.value.entity_id = entityId;
    applyCalendarTimesToRow(start, end);
    syncDurationFromTimes();
    ($modal.value as any)?.show();
};

/**
 * Auto-save a dropped item directly to the API without opening the modal.
 * Creates a one-time event at the dropped slot (same defaults openForDrop uses)
 * and immediately PUTs it. Returns an undo function the caller can wire to a toast.
 */
const autoSaveFromDrop = async (
    entityId: number,
    start: Date,
    durationMinutes: number,
    recurrenceType: string | null,
    days: number[],
    source: 'playlist' | 'smart_block' | 'clock_wheel' = 'playlist',
): Promise<{success: boolean}> => {
    try {
        const startInTz = DateTime.fromJSDate(start, {zone: stationTimezone.value});
        const endInTz = startInTz.plus({minutes: durationMinutes});

        const row = createScheduleItemDefaults();
        row.start_date = startInTz.toFormat('yyyy-MM-dd');
        row.end_date = recurrenceType ? '' : startInTz.toFormat('yyyy-MM-dd');
        row.start_time = Number(startInTz.toFormat('HHmm'));
        row.end_time = Number(endInTz.toFormat('HHmm'));
        row.recurrence_type = recurrenceType ?? null;
        row.days = days;

        const entityType = apiEntityType(source);
        const entityApiUrl = getStationApiUrl(`/${entityType}/${entityId}`).value;

        const {data: entityData} = await axios.get(entityApiUrl);
        const existing = (entityData.schedule_items as unknown[]) ?? [];

        const newItem = buildSchedulePayload(row);
        if (source === 'clock_wheel') {
            (newItem as any).loop_once = false;
            (newItem as any).clock_wheel_mode = 'flexible';
        }

        await axios.put(entityApiUrl, {
            schedule_items: [...existing, newItem],
        });

        emit('relist');
        return {success: true};
    } catch {
        return {success: false};
    }
};

/**
 * Auto-save a drag-to-move/resize operation for an existing schedule item.
 * Fetches the entity, updates start/end times in the matching item, and PUTs.
 * For one-time events also updates start_date/end_date. Recurring events only
 * get new start_time/end_time — the recurrence boundary dates stay untouched.
 * Returns {success} so the caller can revert the calendar event on failure.
 */
const autoSaveMove = async (
    event: EventImpl,
    newStart: Date,
    newEnd: Date | null,
): Promise<{success: boolean}> => {
    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;

    if (!editUrl || !Number.isFinite(scheduleId)) return {success: false};

    try {
        const baseUrl = editUrl.replace(/\/schedule\/\d+$/, '');
        const {data: entityData} = await axios.get(baseUrl);
        const existingItems = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];

        const targetItem = existingItems.find((row: any) => Number(row.id) === scheduleId) as Record<string, unknown> | undefined;
        if (!targetItem) return {success: false};

        const startInTz = DateTime.fromJSDate(newStart, {zone: stationTimezone.value});
        const startTime = Number(startInTz.toFormat('HHmm'));
        const startDate = startInTz.toFormat('yyyy-MM-dd');

        let endTime: number;
        let endDate: string | undefined;

        if (newEnd) {
            const endInTz = DateTime.fromJSDate(newEnd, {zone: stationTimezone.value});
            endTime = Number(endInTz.toFormat('HHmm'));
            endDate = endInTz.toFormat('yyyy-MM-dd');
        } else {
            endTime = Number(targetItem.end_time ?? 0);
        }

        const isRecurringItem = targetItem.recurrence_type != null && targetItem.recurrence_type !== '';

        const updatedItem = {
            ...targetItem,
            start_time: startTime,
            end_time: endTime,
            start_date: startDate,
            // Don't overwrite the recurrence boundary end_date for recurring events
            ...(!isRecurringItem && endDate !== undefined ? {end_date: endDate} : {}),
        };

        const updatedItems = existingItems.map((row: any) =>
            Number(row.id) === scheduleId ? updatedItem : row
        );

        await axios.put(baseUrl, {schedule_items: updatedItems});
        emit('relist');
        return {success: true};
    } catch {
        return {success: false};
    }
};

/** Check whether a calendar event's schedule item is recurring, without opening the modal. */
const checkIsRecurring = async (event: EventImpl): Promise<{isRecurring: boolean}> => {
    const editUrl = event.extendedProps.edit_url as string | undefined;
    const scheduleIdRaw = event.extendedProps.schedule_id as number | string | undefined;
    const scheduleId = scheduleIdRaw !== undefined ? Number(scheduleIdRaw) : NaN;

    if (!editUrl || !Number.isFinite(scheduleId)) return {isRecurring: false};

    try {
        const baseUrl = editUrl.replace(/\/schedule\/\d+$/, '');
        const {data: entityData} = await axios.get(baseUrl);
        const items = (entityData.schedule_items as Record<string, unknown>[] | undefined) ?? [];
        const target = items.find((r: any) => Number(r?.id) === scheduleId) as any;
        return {isRecurring: !!(target?.recurrence_type)};
    } catch {
        return {isRecurring: false};
    }
};

/** Called when user clicks an empty calendar slot — opens the modal with time pre-filled. */
const openAtTime = (date: Date) => {
    clearForm();
    applyCalendarTimesToRow(date);
    syncDurationFromTimes();
    ($modal.value as any)?.show();
};

defineExpose({open, openForEdit, openScopedForCreate, openScopedForEdit, openForDrop, autoSaveFromDrop, autoSaveMove, checkIsRecurring, openAtTime});
</script>
