<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_clock_wheel_schedule"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_clock_wheel_schedule"
                        class="card-title"
                    >
                        {{ $gettext('Clock Wheel Schedule') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <add-button
                        :text="$gettext('Add Event')"
                        @click="doCreate()"
                    />
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <schedule
                ref="$schedule"
                :options="calendarOptions"
            />
        </div>
    </section>

    <schedule-edit-modal
        ref="$editModal"
        @relist="refresh"
    />
</template>

<script setup lang="ts">
import {computed, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useStationData} from '~/functions/useStationQuery.ts';
import {toRefs} from '@vueuse/core';
import {CalendarOptions, EventClickArg} from '@fullcalendar/core';
import Schedule from '~/components/Common/ScheduleView.vue';
import AddButton from '~/components/Common/AddButton.vue';
import ScheduleEditModal from '~/components/Stations/ClockWheels/ScheduleEditModal.vue';

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();

const scheduleUrl = getStationApiUrl('/clock-wheel-events/schedule');

const stationData = useStationData();
const {timezone} = toRefs(stationData);

// ------------------------------------------------------------------
// Refs
// ------------------------------------------------------------------

const $schedule = useTemplateRef('$schedule');
const $editModal = useTemplateRef('$editModal');

// ------------------------------------------------------------------
// Helpers: convert Date to HH:MM
// ------------------------------------------------------------------

const dateToHHMM = (d: Date): string =>
    d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');

// ------------------------------------------------------------------
// Calendar options
// ------------------------------------------------------------------

const calendarOptions = computed<CalendarOptions>(() => ({
    headerToolbar: {
        left: 'prev,next',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
    },
    timeZone: timezone.value,
    events: scheduleUrl.value,

    // Allow clicking on empty time slots to create
    selectable: true,
    selectMirror: true,
    select: (selectInfo) => {
        $editModal.value?.create({
            start_time: dateToHHMM(selectInfo.start),
            end_time: dateToHHMM(selectInfo.end),
        });
        $schedule.value?.getCalendarApi().unselect();
    },

    // Click existing event to edit
    eventClick: (arg: EventClickArg) => {
        const eventId = arg.event.extendedProps.event_id as number | undefined;
        if (eventId !== undefined) {
            $editModal.value?.edit(eventId);
        }
    },

    // Style events with the clock wheel colour (already set in backgroundColor)
    eventContent: (arg) => ({html: `<div class="fc-event-title px-1 text-truncate">${arg.event.title}</div>`}),
}));

// ------------------------------------------------------------------
// Actions
// ------------------------------------------------------------------

const doCreate = () => {
    $editModal.value?.create();
};

const refresh = () => {
    $schedule.value?.getCalendarApi().refetchEvents();
};
</script>
