<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_schedule"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_schedule"
                        class="card-title"
                    >
                        {{ $gettext('Schedule') }}
                    </h2>
                </div>
                <div class="col-md-6 text-end">
                    <time-zone />
                </div>
            </div>
        </div>
        <div class="card-body">
            <schedule-calendar
                ref="$scheduleTab"
                :schedule-url="[scheduleUrl, clockWheelsScheduleUrl]"
                :show-create-button="true"
                @click="doCalendarClick"
                @create="doCreateEvent"
            />
        </div>
        <edit-modal
            ref="$editModal"
            :create-url="listUrl"
            @relist="relist"
        />
        <clock-wheel-edit-modal
            ref="$clockWheelEditModal"
            :create-url="clockWheelsListUrl"
            @relist="relist"
        />
        <create-event-modal
            ref="$createEventModal"
            @relist="relist"
        />
    </section>
</template>

<script setup lang="ts">
import ScheduleCalendar from "~/components/Stations/Common/ScheduleCalendar.vue";
import EditModal from "~/components/Stations/Playlists/EditModal.vue";
import ClockWheelEditModal from "~/components/Stations/ClockWheels/EditModal.vue";
import CreateEventModal from "~/components/Stations/Common/CreateEventModal.vue";
import TimeZone from "~/components/Stations/Common/TimeZone.vue";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useTemplateRef} from "vue";
import {EventImpl} from "@fullcalendar/core/internal";
import useHasEditModal from "~/functions/useHasEditModal";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();

const listUrl = getStationApiUrl('/playlists');
const clockWheelsListUrl = getStationApiUrl('/clock-wheels');
const scheduleUrl = getStationApiUrl('/playlists/schedule');
const clockWheelsScheduleUrl = getStationApiUrl('/clock-wheels/schedule');

const $editModal = useTemplateRef('$editModal');
const {doEdit} = useHasEditModal($editModal);

const $clockWheelEditModal = useTemplateRef('$clockWheelEditModal');
const {doEdit: doEditClockWheel} = useHasEditModal($clockWheelEditModal);

const $scheduleTab = useTemplateRef('$scheduleTab');
const $createEventModal = useTemplateRef('$createEventModal');

const doCalendarClick = (event: EventImpl) => {
    $createEventModal.value?.openForEdit(event);
};

const doCreateEvent = () => {
    $createEventModal.value?.open();
};

const relist = () => {
    $scheduleTab.value?.refresh();
};
</script>
