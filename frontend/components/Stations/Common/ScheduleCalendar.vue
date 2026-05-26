<template>
    <div
        class="card-body-flush"
        style="position: relative;"
    >
        <schedule
            ref="$schedule"
            :options="calendarOptions"
        />
        <div
            v-if="showCreateButton"
            style="position: absolute; bottom: 1.25rem; right: 1.25rem; z-index: 10;"
        >
            <button
                type="button"
                class="btn btn-primary btn-lg rounded-pill shadow"
                @click="emit('create')"
            >
                <icon-ic-add />
                {{ $gettext('Create Event') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import Schedule from "~/components/Common/ScheduleView.vue";
import IconIcAdd from "~icons/ic/baseline-add";
import {Calendar, EventClickArg} from "@fullcalendar/core";
import {EventImpl} from "@fullcalendar/core/internal";
import {computed, useTemplateRef, toValue} from "vue";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";

const props = withDefaults(defineProps<{
    scheduleUrl: string | string[],
    showCreateButton?: boolean,
}>(), {
    showCreateButton: false,
});

const emit = defineEmits<{
    click: [event: EventImpl],
    create: [],
}>();

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const calendarOptions = computed(() => {
    const rawUrls = props.scheduleUrl;
    const urls = Array.isArray(rawUrls)
        ? rawUrls.map(u => toValue(u))
        : [toValue(rawUrls)];
    return {
        headerToolbar: {
            left: 'prev,next',
            center: 'title',
            right: 'timeGridWeek,timeGridDay'
        },
        timeZone: timezone.value,
        eventSources: urls,
        eventClick: onClick
    };
});

const onClick = (arg: EventClickArg) => {
    emit('click', arg.event);
}

const $schedule = useTemplateRef('$schedule');

const getCalendarApi = (): Calendar | undefined => {
    return $schedule.value?.getCalendarApi();
};

const refresh = () => getCalendarApi()?.refetchEvents();

defineExpose({
    getCalendarApi,
    refresh
});
</script>
