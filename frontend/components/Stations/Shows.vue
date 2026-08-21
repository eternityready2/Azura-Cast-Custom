<template>
    <div class="shows-page">
        <header class="shows-hero">
            <div class="shows-hero-icon">
                <icon-event />
            </div>
            <div class="shows-hero-copy">
                <h1>{{ $gettext('Shows') }}</h1>
                <p>{{ $gettext('Structure a time slot with a repeating sequence of segments - jingles, tracks from folders, playlists, and remote audio.') }}</p>
            </div>
            <router-link class="btn add-show-button" :to="{name: 'stations:shows:new'}">
                <icon-add class="me-1" />
                {{ $gettext('Add Show') }}
            </router-link>
        </header>

        <div class="view-switch">
            <button
                type="button"
                class="view-button"
                :class="{'is-active': 'list' === viewMode}"
                @click="viewMode = 'list'"
            >
                <icon-list class="me-1" />
                {{ $gettext('All Shows') }}
            </button>
            <button
                type="button"
                class="view-button"
                :class="{'is-active': 'schedule' === viewMode}"
                @click="viewMode = 'schedule'"
            >
                <icon-event class="me-1" />
                {{ $gettext('Schedule View') }}
            </button>
        </div>

        <section v-if="'schedule' === viewMode" class="schedule-panel">
            <div class="schedule-toolbar">
                <div class="schedule-nav">
                    <button type="button" class="calendar-button square" @click="shiftWeek(-1)">
                        <icon-chevron-left />
                    </button>
                    <button type="button" class="calendar-button square" @click="shiftWeek(1)">
                        <icon-chevron-right />
                    </button>
                    <button type="button" class="calendar-button today-button" @click="goToday">
                        {{ $gettext('TODAY') }}
                    </button>
                </div>

                <div class="week-label">{{ weekLabel }}</div>

                <div class="calendar-mode">
                    <button type="button" class="calendar-button mode-active">{{ $gettext('WEEK') }}</button>
                    <button type="button" class="calendar-button">{{ $gettext('DAY') }}</button>
                </div>
            </div>

            <div class="calendar-shell">
                <div class="calendar-grid">
                    <div class="calendar-corner"></div>

                    <div
                        v-for="day in weekDays"
                        :key="day.iso"
                        class="calendar-day-header"
                        :class="{'is-today': day.isToday}"
                    >
                        <span>{{ day.weekday }}</span>
                        <strong>{{ day.monthDay }}</strong>
                        <small v-if="day.isToday" class="today-badge">{{ $gettext('TODAY') }}</small>
                    </div>

                    <template v-for="hour in visibleHours" :key="hour">
                        <div class="calendar-time">
                            {{ formatHour(hour) }}
                        </div>

                        <div
                            v-for="day in weekDays"
                            :key="`${day.iso}-${hour}`"
                            class="calendar-cell"
                            :class="{'is-today': day.isToday}"
                        >
                            <div
                                v-if="isCurrentTimeCell(day.iso, hour)"
                                class="current-time-marker"
                                :style="{top: currentMinutePosition}"
                            >
                                <span>{{ currentTimeLabel }}</span>
                            </div>

                            <router-link
                                v-for="event in eventsFor(day.iso, hour)"
                                :key="`${event.show.id}-${event.scheduleIndex}`"
                                class="show-event"
                                :style="eventStyle(event.show.color)"
                                :to="{name: 'stations:shows:edit', params: {show_id: event.show.id}}"
                            >
                                <strong>{{ event.show.name }}</strong>
                                <span>{{ event.schedule.start_time }} - {{ event.schedule.end_time }}</span>
                                <small>{{ priorityLabel(event.show.priority) }}</small>
                            </router-link>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <section v-else class="shows-list-panel">
            <div class="shows-table-toolbar">
                <span class="page-pill">1</span>

                <div class="shows-table-tools">
                    <div class="search-box">
                        <icon-search />
                        <input
                            v-model.trim="search"
                            type="search"
                            :placeholder="$gettext('Search')"
                            :aria-label="$gettext('Search shows')"
                        >
                    </div>

                    <button type="button" class="refresh-button" @click="load">
                        <icon-refresh />
                    </button>

                    <select v-model.number="pageSize" class="page-size-select">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                    </select>
                </div>
            </div>

            <div v-if="0 === shows.length" class="empty-state">
                <div class="empty-state-icon"><icon-event /></div>
                <h2>{{ $gettext('No Shows Yet') }}</h2>
                <p>{{ $gettext('Create a show to build a structured programme from playlists, folders, tracks, or remote audio.') }}</p>
                <router-link class="btn add-show-button" :to="{name: 'stations:shows:new'}">
                    <icon-add class="me-1" />
                    {{ $gettext('Add Show') }}
                </router-link>
            </div>

            <div v-else class="table-responsive">
                <table class="table show-table mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Show') }}</th>
                            <th>{{ $gettext('Segments') }}</th>
                            <th>{{ $gettext('Scheduling') }}</th>
                            <th>{{ $gettext('Priority') }}</th>
                            <th class="text-end">{{ $gettext('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="0 === visibleShows.length">
                            <td colspan="5" class="empty-row">
                                {{ $gettext('No records.') }}
                            </td>
                        </tr>

                        <tr v-for="show in visibleShows" :key="show.id">
                            <td>
                                <div class="show-name-wrap">
                                    <span class="show-color" :style="{backgroundColor: show.color}" />
                                    <div>
                                        <strong>{{ show.name }}</strong>
                                        <div v-if="show.description" class="show-description">
                                            {{ show.description }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ show.segments.length }}</td>
                            <td>{{ show.schedules.length }}</td>
                            <td>{{ priorityLabel(show.priority) }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <router-link
                                        class="btn btn-outline-light"
                                        :to="{name: 'stations:shows:edit', params: {show_id: show.id}}"
                                    >
                                        {{ $gettext('Edit') }}
                                    </router-link>
                                    <button type="button" class="btn btn-outline-danger" @click="remove(show.id)">
                                        {{ $gettext('Delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="shows-table-footer">
                <span class="page-pill">1</span>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from "vue";
import IconAdd from "~icons/ic/baseline-add";
import IconChevronLeft from "~icons/ic/baseline-chevron-left";
import IconChevronRight from "~icons/ic/baseline-chevron-right";
import IconEvent from "~icons/ic/baseline-event";
import IconList from "~icons/ic/baseline-list";
import IconRefresh from "~icons/ic/baseline-refresh";
import IconSearch from "~icons/ic/baseline-search";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

type ShowSchedule = {
    start_time: string,
    end_time: string,
    days: number[],
    start_date?: string,
    end_date?: string,
    loop_once?: boolean
};

type ShowSegment = {
    type: string,
    value: string
};

type StationShow = {
    id: string,
    name: string,
    description: string,
    enabled: boolean,
    color: string,
    priority: string,
    segments: ShowSegment[],
    schedules: ShowSchedule[]
};

type CalendarEvent = {
    show: StationShow,
    schedule: ShowSchedule,
    scheduleIndex: number
};

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();

const apiUrl = getStationApiUrl("/features/shows");
const shows = ref<StationShow[]>([]);
const viewMode = ref<"list" | "schedule">("schedule");
const search = ref("");
const pageSize = ref(10);
const anchorDate = ref(new Date());
const visibleHours = Array.from({length: 24}, (_, index) => index);
const now = ref(new Date());
let nowTimer: number | undefined;

const currentTimeLabel = computed(() => now.value.toLocaleTimeString([], {hour: "numeric", minute: "2-digit"}));
const currentMinutePosition = computed(() => `${(now.value.getMinutes() / 60) * 100}%`);

const isCurrentTimeCell = (dateIso: string, hour: number): boolean => {
    return localDate(now.value) === dateIso && now.value.getHours() === hour;
};

const visibleShows = computed(() => {
    const query = search.value.toLowerCase();

    const filtered = query
        ? shows.value.filter((show) => {
            return [
                show.name,
                show.description,
                priorityLabel(show.priority)
            ].some((value) => value?.toLowerCase().includes(query));
        })
        : shows.value;

    return filtered.slice(0, pageSize.value);
});

const localDate = (date: Date): string => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
};

const startOfWeek = computed(() => {
    const date = new Date(anchorDate.value);
    const weekday = date.getDay();
    const offset = 0 === weekday ? -6 : 1 - weekday;

    date.setHours(0, 0, 0, 0);
    date.setDate(date.getDate() + offset);

    return date;
});

const weekDays = computed(() => {
    const today = localDate(new Date());

    return Array.from({length: 7}, (_, index) => {
        const date = new Date(startOfWeek.value);
        date.setDate(date.getDate() + index);

        const iso = localDate(date);

        return {
            date,
            iso,
            weekday: date.toLocaleDateString(undefined, {weekday: "short"}).toUpperCase(),
            monthDay: date.toLocaleDateString(undefined, {month: "numeric", day: "numeric"}),
            isoWeekday: index + 1,
            isToday: iso === today
        };
    });
});

const weekLabel = computed(() => {
    const first = weekDays.value[0].date;
    const last = weekDays.value[6].date;

    return `${first.toLocaleDateString(undefined, {month: "short", day: "numeric"})} - ${last.toLocaleDateString(undefined, {month: "short", day: "numeric", year: "numeric"})}`;
});

const formatHour = (hour: number): string => {
    return new Date(2000, 0, 1, hour).toLocaleTimeString([], {hour: "numeric"});
};

const scheduleApplies = (
    schedule: ShowSchedule,
    dateIso: string,
    isoWeekday: number
): boolean => {
    if (schedule.start_date && dateIso < schedule.start_date) {
        return false;
    }

    if (schedule.end_date && dateIso > schedule.end_date) {
        return false;
    }

    return !(schedule.days?.length && !schedule.days.includes(isoWeekday));
};

const eventsFor = (dateIso: string, hour: number): CalendarEvent[] => {
    const day = weekDays.value.find((weekDay) => weekDay.iso === dateIso);
    if (!day) {
        return [];
    }

    const events: CalendarEvent[] = [];

    for (const show of shows.value) {
        if (!show.enabled) {
            continue;
        }

        show.schedules.forEach((schedule, scheduleIndex) => {
            if (!scheduleApplies(schedule, dateIso, day.isoWeekday)) {
                return;
            }

            if (Number(schedule.start_time?.slice(0, 2) ?? -1) === hour) {
                events.push({show, schedule, scheduleIndex});
            }
        });
    }

    return events;
};

const eventStyle = (color: string): Record<string, string> => {
    const safeColor = /^#[0-9a-f]{6}$/i.test(color) ? color : "#1e88e5";

    return {
        borderColor: safeColor,
        background: `linear-gradient(90deg, ${safeColor}55, ${safeColor}28)`
    };
};

const priorityLabel = (priority: string): string => {
    if ("priority" === priority) {
        return $gettext("Priority / News");
    }

    if ("rotation" === priority) {
        return $gettext("Rotation");
    }

    return $gettext("Programme");
};

const shiftWeek = (amount: number) => {
    const next = new Date(anchorDate.value);
    next.setDate(next.getDate() + amount * 7);
    anchorDate.value = next;
};

const goToday = () => {
    anchorDate.value = new Date();
};

const load = async () => {
    const response = await axios.get<StationShow[]>(apiUrl.value);
    shows.value = response.data;
};

const remove = async (id: string) => {
    if (!window.confirm($gettext("Delete this show?"))) {
        return;
    }

    await axios.delete(`${apiUrl.value}/${id}`);
    await load();
};

onMounted(() => {
    void load();
    now.value = new Date();
    nowTimer = window.setInterval(() => {
        now.value = new Date();
    }, 30000);
});

onUnmounted(() => {
    if (nowTimer !== undefined) {
        window.clearInterval(nowTimer);
    }
});
</script>

<style scoped>
.shows-page {
    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
    color: #e8edf8;
}

.shows-hero {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    min-height: 108px;
    margin-bottom: 1.7rem;
    padding: 1.25rem 1.6rem;
    border-radius: 1rem;
    background: linear-gradient(90deg, #0a6fc2 0%, #2196f3 100%);
    box-shadow: 0 .7rem 1.6rem rgba(0, 0, 0, .24);
}

.shows-hero-icon {
    width: 64px;
    height: 64px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 1rem;
    background: rgba(154, 117, 214, .38);
    color: #fff;
}

.shows-hero-icon :deep(svg) {
    width: 31px;
    height: 31px;
}

.shows-hero-copy {
    min-width: 0;
    flex: 1;
}

.shows-hero h1 {
    margin: 0;
    color: #fff;
    font-size: 1.7rem;
    font-weight: 750;
}

.shows-hero p {
    margin: .35rem 0 0;
    color: #dbeeff;
    font-size: .93rem;
}

.add-show-button {
    min-width: 134px;
    padding: .65rem 1.1rem;
    border: 1px solid rgba(215, 224, 255, .55);
    border-radius: .65rem;
    background: rgba(255, 255, 255, .08);
    color: #fff;
    font-weight: 650;
}

.add-show-button:hover,
.add-show-button:focus {
    border-color: #fff;
    background: rgba(255, 255, 255, .16);
    color: #fff;
}

.view-switch {
    width: fit-content;
    display: flex;
    gap: .25rem;
    margin-bottom: 1rem;
    padding: .22rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .7rem;
    background: var(--bs-body-bg);
}

.view-button {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: .45rem .95rem;
    border: 0;
    border-radius: .5rem;
    background: transparent;
    color: #a8b5d3;
    font-size: .83rem;
}

.view-button.is-active {
    background: linear-gradient(90deg, #1976d2, #2196f3);
    color: #fff;
    box-shadow: 0 .2rem .7rem rgba(111, 84, 212, .28);
}

.schedule-panel,
.shows-list-panel {
    overflow: hidden;
    border: 1px solid var(--bs-border-color);
    border-radius: .9rem;
    background: var(--bs-tertiary-bg);
    box-shadow: 0 .45rem 1.1rem rgba(0, 0, 0, .2);
}

.schedule-toolbar {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 1rem;
    min-height: 80px;
    padding: 1rem 1.2rem;
    background: var(--bs-tertiary-bg);
}

.schedule-nav,
.calendar-mode {
    display: flex;
    align-items: center;
    gap: .35rem;
}

.calendar-mode {
    justify-self: end;
}

.calendar-button {
    min-height: 36px;
    padding: .45rem .75rem;
    border: 1px solid transparent;
    border-radius: .4rem;
    background: linear-gradient(90deg, #1976d2, #2196f3);
    color: #fff;
    font-size: .75rem;
    font-weight: 650;
}

.calendar-button.square {
    width: 38px;
    padding: .35rem;
}

.today-button {
    margin-left: .15rem;
    background: #1565c0;
}

.calendar-button.mode-active {
    background: linear-gradient(90deg, #1976d2, #2196f3);
}

.week-label {
    color: #fff;
    font-size: .9rem;
    font-weight: 700;
    text-align: center;
}

.calendar-shell {
    overflow: auto;
    padding: 0 1rem 1rem;
}

.calendar-grid {
    min-width: 1040px;
    display: grid;
    grid-template-columns: 58px repeat(7, minmax(135px, 1fr));
    border: 1px solid #dce4f4;
    background: var(--bs-tertiary-bg);
}

.calendar-corner,
.calendar-day-header {
    min-height: 58px;
    border-right: 1px solid var(--bs-border-color);
    border-bottom: 1px solid #dce4f4;
    background: var(--bs-secondary-bg);
}

.calendar-corner {
    background: #485157;
}

.calendar-day-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .25rem;
    color: #a9b9d7;
    font-size: .69rem;
    letter-spacing: .035em;
}

.calendar-day-header strong {
    color: #8196be;
    font-weight: 650;
}

.calendar-day-header.is-today {
    position: relative;
    background: linear-gradient(180deg, #17263d 0%, #132238 100%);
    box-shadow: inset 0 -2px 0 #e34b5d;
}

.today-badge {
    display: inline-flex;
    align-items: center;
    margin-left: .25rem;
    padding: .08rem .28rem;
    border-radius: .25rem;
    background: rgba(227, 75, 93, .18);
    color: #ff7180;
    font-size: .5rem;
    font-weight: 800;
    letter-spacing: .04em;
}

.calendar-time {
    min-height: 56px;
    padding: .45rem .25rem;
    border-right: 1px solid var(--bs-border-color);
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    color: #6f86aa;
    font-size: .64rem;
}

.calendar-cell {
    position: relative;
    min-height: 56px;
    padding: .15rem;
    border-right: 1px solid var(--bs-border-color);
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.calendar-cell.is-today {
    background: color-mix(in srgb, var(--bs-primary) 5%, var(--bs-tertiary-bg));
}

.current-time-marker {
    position: absolute;
    z-index: 5;
    left: 0;
    right: 0;
    height: 2px;
    background: #f04455;
    box-shadow: 0 0 .35rem rgba(240, 68, 85, .5);
    pointer-events: none;
}

.current-time-marker::before {
    content: "";
    position: absolute;
    left: -3px;
    top: -3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #f04455;
}

.current-time-marker span {
    position: absolute;
    right: .2rem;
    top: -.7rem;
    padding: .05rem .28rem;
    border-radius: .22rem;
    background: #b92f40;
    color: #fff;
    font-size: .5rem;
    font-weight: 750;
    line-height: 1.25;
}

.show-event {
    display: block;
    margin: .05rem;
    padding: .35rem .4rem;
    border: 1px solid;
    border-left-width: 4px;
    border-radius: .35rem;
    color: #fff;
    text-decoration: none;
    box-shadow: 0 .15rem .35rem rgba(0, 0, 0, .15);
}

.show-event strong,
.show-event span,
.show-event small {
    display: block;
}

.show-event strong {
    font-size: .7rem;
}

.show-event span {
    margin-top: .1rem;
    color: #d7def0;
    font-size: .62rem;
}

.show-event small {
    margin-top: .08rem;
    color: #b5c1db;
    font-size: .58rem;
}

.empty-state {
    padding: 5rem 1.5rem;
    text-align: center;
}

.empty-state-icon {
    width: 58px;
    height: 58px;
    display: grid;
    place-items: center;
    margin: 0 auto .9rem;
    border-radius: .8rem;
    background: linear-gradient(135deg, #1976d2, #2196f3);
    color: #fff;
}

.empty-state h2 {
    color: #fff;
    font-size: 1.2rem;
}

.empty-state p {
    max-width: 540px;
    margin: 0 auto 1rem;
    color: #aab8d1;
}

.show-table {
    --bs-table-bg: transparent;
    --bs-table-color: #e7edf8;
    --bs-table-border-color: var(--bs-border-color);
}

.show-table thead th {
    padding: .85rem .9rem;
    background: var(--bs-tertiary-bg);
    color: #c6d2e8;
    border-color: var(--bs-border-color);
    font-size: .73rem;
    text-transform: uppercase;
}

.show-table td {
    padding: .9rem;
    color: #e7edf8;
    border-color: var(--bs-border-color);
}

.show-name-wrap {
    display: flex;
    align-items: center;
    gap: .7rem;
}

.show-color {
    width: .7rem;
    height: 2.4rem;
    flex: 0 0 auto;
    border-radius: .25rem;
}

.show-description {
    margin-top: .15rem;
    color: #a9b6cf;
    font-size: .75rem;
}


.shows-table-toolbar,
.shows-table-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .7rem .85rem;
    background: var(--bs-secondary-bg);
}

.shows-table-tools {
    display: flex;
    align-items: stretch;
    gap: .55rem;
    margin-left: auto;
}

.page-pill {
    display: inline-grid;
    place-items: center;
    min-width: 28px;
    height: 28px;
    padding: 0 .45rem;
    border-radius: .35rem;
    background: linear-gradient(135deg, #1976d2, #2196f3);
    color: #fff;
    font-size: .76rem;
    font-weight: 700;
}

.search-box {
    display: flex;
    align-items: center;
    min-width: 330px;
    border: 1px solid var(--bs-border-color);
    border-radius: .35rem;
    background: var(--bs-secondary-bg);
    color: #7f8da8;
    overflow: hidden;
}

.search-box :deep(svg) {
    width: 18px;
    height: 18px;
    margin: 0 .55rem;
}

.search-box input {
    width: 100%;
    min-height: 36px;
    border: 0;
    outline: 0;
    background: transparent;
    color: #e8edf8;
}

.search-box input::placeholder {
    color: #8f9bb2;
}

.refresh-button,
.page-size-select {
    min-height: 36px;
    border: 1px solid var(--bs-border-color);
    border-radius: .35rem;
    background: #505d6d;
    color: #fff;
}

.refresh-button {
    width: 40px;
    display: grid;
    place-items: center;
}

.page-size-select {
    min-width: 64px;
    padding: 0 .45rem;
}

.empty-row {
    height: 58px;
    color: #d4dced !important;
    text-align: left;
    vertical-align: middle;
}

.shows-table-footer {
    min-height: 48px;
    border-top: 1px solid var(--bs-border-color);
}

@media (max-width: 767px) {
    .shows-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .add-show-button {
        width: 100%;
    }

    .schedule-toolbar {
        grid-template-columns: 1fr;
    }

    .shows-table-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .shows-table-tools {
        width: 100%;
        margin-left: 0;
    }

    .search-box {
        min-width: 0;
        flex: 1;
    }

    .calendar-mode {
        justify-self: start;
    }

    .week-label {
        text-align: left;
    }
}
</style>
