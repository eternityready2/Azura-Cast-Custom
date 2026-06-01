<template>
    <div class="clock-wheel-live">
        <div
            v-if="conflictMessage.active"
            class="alert alert-warning d-flex align-items-start gap-2 mb-3"
            role="alert"
        >
            <icon-ic-warning class="flex-shrink-0 mt-1" />
            <div>
                <strong>{{ $gettext('Schedule conflict') }}</strong>
                <div class="small mb-0">
                    {{
                        $gettext(
                            'A playlist or streamer schedule entry is active now and may override the clock wheel.'
                        )
                    }}
                    <span v-if="conflictMessage.detail"> ({{ conflictMessage.detail }})</span>
                </div>
            </div>
        </div>

        <div
            v-if="np.live?.is_live"
            class="alert alert-info mb-3"
            role="status"
        >
            <icon-ic-mic class="me-1" />
            {{ $gettext('Live streamer on air') }}: {{ np.live.streamer_name }}
        </div>

        <loading :loading="isLoading" lazy>
            <div class="row g-4 mb-4">
                <div class="col-lg-5 d-flex flex-column align-items-center">
                    <div
                        class="clock-wheel-live__face"
                        role="img"
                        :aria-label="clockAriaLabel"
                    >
                        <div
                            class="clock-wheel-live__hand"
                            :style="{ transform: `rotate(${handDegrees}deg)` }"
                        />
                        <span
                            v-for="(item, idx) in slotsWithTracks"
                            :key="'slot-' + idx"
                            class="clock-wheel-live__marker"
                            :class="{
                                'clock-wheel-live__marker--past': item.isPast,
                                'clock-wheel-live__marker--current': item.isCurrent,
                            }"
                            :style="markerStyle(item.slot.position_seconds)"
                            :title="formatPosition(item.slot.position_seconds)"
                        />
                    </div>
                    <div class="text-muted small mt-2">
                        {{ stationTimeLabel }}
                        <span class="mx-1">·</span>
                        {{ timezone }}
                    </div>
                </div>

                <div class="col-lg-7">
                    <h3 class="h5 mb-3">
                        {{ $gettext('Now Playing') }}
                    </h3>
                    <div
                        v-if="np.now_playing?.song"
                        class="mb-2"
                    >
                        <div class="fw-semibold">
                            {{ np.now_playing.song.title || np.now_playing.song.text }}
                        </div>
                        <div
                            v-if="np.now_playing.song.artist"
                            class="text-muted"
                        >
                            {{ np.now_playing.song.artist }}
                        </div>
                        <div
                            v-if="np.now_playing.clock_wheel"
                            class="small text-muted mt-1"
                        >
                            {{ $gettext('Clock Wheel') }}: {{ np.now_playing.clock_wheel }}
                        </div>
                        <div
                            v-else-if="np.now_playing.playlist"
                            class="small text-muted mt-1"
                        >
                            {{ $gettext('Playlist') }}: {{ np.now_playing.playlist }}
                        </div>
                    </div>
                    <div
                        v-else
                        class="text-muted"
                    >
                        {{ $gettext('No track on air.') }}
                    </div>

                    <template v-if="activeWheel">
                        <hr>
                        <h3 class="h6 text-muted mb-2">
                            {{ $gettext('Active wheel') }}:
                            <span
                                class="d-inline-block rounded align-middle ms-1"
                                style="width: 0.85rem; height: 0.85rem;"
                                :style="{ backgroundColor: activeWheel.color }"
                            />
                            {{ activeWheel.name }}
                        </h3>
                    </template>
                    <p
                        v-else-if="!isLoading"
                        class="text-muted small mb-0"
                    >
                        {{ $gettext('No clock wheel is scheduled for the current hour.') }}
                    </p>
                </div>
            </div>

            <template v-if="activeWheel && slotsWithTracks.length > 0">
                <h3 class="h5 mb-2">
                    {{ $gettext('This hour — anchors & queue') }}
                </h3>
                <p class="small text-muted">
                    {{
                        $gettext(
                            'Track names come from the AutoDJ queue for this wheel (planned playback, not a simulator).'
                        )
                    }}
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ $gettext('Position') }}</th>
                                <th>{{ $gettext('Type') }}</th>
                                <th>{{ $gettext('Queued track') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(item, idx) in slotsWithTracks"
                                :key="idx"
                                :class="{ 'table-primary': item.isCurrent }"
                            >
                                <td class="text-nowrap">
                                    {{ formatPosition(item.slot.position_seconds) }}
                                </td>
                                <td>{{ formatMediaType(item.slot.type, $gettext) }}</td>
                                <td>
                                    <span v-if="item.trackLabel">{{ item.trackLabel }}</span>
                                    <span
                                        v-else
                                        class="text-muted fst-italic"
                                    >{{ $gettext('Not yet queued') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-if="upcomingWheelEvents.length > 0">
                <h3 class="h5 mt-4 mb-2">
                    {{ $gettext('Upcoming clock wheels') }}
                </h3>
                <ul class="list-group list-group-flush">
                    <li
                        v-for="(event, idx) in upcomingWheelEvents"
                        :key="idx"
                        class="list-group-item px-0 d-flex justify-content-between gap-2"
                    >
                        <span>{{ event.title ?? event.name }}</span>
                        <span class="text-muted small text-nowrap">
                            {{ formatIsoAsDateTime(event.start) }}
                        </span>
                    </li>
                </ul>
            </template>
        </loading>
    </div>
</template>

<script setup lang="ts">
import {computed} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import Loading from '~/components/Common/Loading.vue';
import useClockWheelLiveData from '~/functions/useClockWheelLiveData.ts';
import useNowPlaying from '~/functions/useNowPlaying.ts';
import {useProfilePropsQuery} from '~/components/Stations/Profile/useProfileQuery.ts';
import {toRefs} from '@vueuse/core';
import type {ApiNowPlayingVueProps} from '~/entities/ApiInterfaces.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import {formatClockWheelPosition, CLOCK_WHEEL_HOUR_SECONDS} from '~/functions/clockWheelPosition.ts';
import {formatMediaType} from '~/functions/mediaTypes.ts';
import IconIcWarning from '~icons/ic/baseline-warning';
import IconIcMic from '~icons/ic/baseline-mic';

const props = defineProps<{
    active: boolean;
}>();

const {$gettext} = useTranslate();

const {
    activeWheel,
    slotsWithTracks,
    handDegrees,
    stationTimeLabel,
    conflictMessage,
    upcomingWheelEvents,
    isLoading,
    stationData,
} = useClockWheelLiveData(() => props.active);

const {timezone} = toRefs(stationData);

const profileQuery = useProfilePropsQuery();

const nowPlayingProps = computed<ApiNowPlayingVueProps>(() => {
    const fromProfile = profileQuery.data.value?.nowPlayingProps;
    if (fromProfile?.stationShortName) {
        return fromProfile;
    }
    return {
        stationShortName: stationData.value.shortName,
        useStatic: false,
        useSse: true,
    };
});

const {np} = useNowPlaying(nowPlayingProps);

const {formatIsoAsDateTime} = useStationDateTimeFormatter();

const formatPosition = formatClockWheelPosition;

const markerStyle = (positionSeconds: number) => {
    const pct = Math.min(CLOCK_WHEEL_HOUR_SECONDS - 1, Math.max(0, positionSeconds)) / CLOCK_WHEEL_HOUR_SECONDS;
    const angle = pct * 2 * Math.PI - Math.PI / 2;
    const radius = 42;
    return {
        left: `${50 + radius * Math.cos(angle)}%`,
        top: `${50 + radius * Math.sin(angle)}%`,
    };
};

const clockAriaLabel = computed(() =>
    $gettext('Station clock showing current hour with wheel anchor markers')
);

</script>

<style lang="scss" scoped>
.clock-wheel-live__face {
    position: relative;
    width: min(280px, 80vw);
    aspect-ratio: 1;
    border-radius: 50%;
    border: 3px solid var(--bs-border-color);
    background: radial-gradient(circle at 50% 45%, var(--bs-body-bg) 0%, var(--bs-secondary-bg) 100%);
    box-shadow: inset 0 0 12px rgba(0, 0, 0, 0.15);
}

.clock-wheel-live__hand {
    position: absolute;
    left: 50%;
    bottom: 50%;
    width: 3px;
    height: 42%;
    margin-left: -1.5px;
    background: var(--bs-danger);
    border-radius: 2px;
    transform-origin: bottom center;
    z-index: 2;
    transition: transform 0.4s ease;
}

.clock-wheel-live__marker {
    position: absolute;
    width: 0.55rem;
    height: 0.55rem;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    background: var(--bs-primary);
    opacity: 0.75;
    z-index: 1;

    &--past {
        opacity: 0.45;
    }

    &--current {
        background: var(--bs-success);
        opacity: 1;
        box-shadow: 0 0 0 3px rgba(var(--bs-success-rgb), 0.35);
    }
}
</style>
