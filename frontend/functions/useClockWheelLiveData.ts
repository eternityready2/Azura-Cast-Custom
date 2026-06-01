import {computed, type MaybeRefOrGetter, toValue} from 'vue';
import {useQuery} from '@tanstack/vue-query';
import {useAxios} from '~/vendor/axios.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {
    ApiNowPlayingStationQueue,
    ApiStationQueueDetailed,
    ApiStationSchedule,
} from '~/entities/ApiInterfaces.ts';
import {useStationData} from '~/functions/useStationQuery.ts';
import useStationDateTimeFormatter from '~/functions/useStationDateTimeFormatter.ts';
import type {MediaTypeValue} from '~/functions/mediaTypes.ts';

const POLL_MS = 10_000;

export interface ClockWheelListItem {
    id: number;
    name: string;
    color: string;
    is_active: boolean;
    links: {self: string};
}

export interface ClockWheelSlotRow {
    type: MediaTypeValue | string;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
}

export interface ClockWheelDetail {
    id: number;
    name: string;
    color: string;
    is_active: boolean;
    slots: ClockWheelSlotRow[];
}

export type QueueRow = ApiNowPlayingStationQueue & ApiStationQueueDetailed;

function songLabel(row: QueueRow): string {
    if (row.autodj_custom_uri) {
        return row.autodj_custom_uri;
    }
    if (row.song?.title) {
        const artist = row.song.artist ? ` — ${row.song.artist}` : '';
        return `${row.song.title}${artist}`;
    }
    return row.song?.text ?? '';
}

export default function useClockWheelLiveData(enabled: MaybeRefOrGetter<boolean> = true) {
    const {axios, axiosSilent} = useAxios();
    const {getStationApiUrl} = useApiRouter();
    const stationData = useStationData();
    const {now} = useStationDateTimeFormatter();

    const scheduleUrl = getStationApiUrl('/schedule');
    const queueUrl = getStationApiUrl('/queue');
    const wheelsUrl = getStationApiUrl('/clock-wheels');

    const queryEnabled = computed(() => toValue(enabled) && stationData.value.id > 0);

    const scheduleQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationPlaylists, 'schedule_live']),
        queryFn: async ({signal}) => {
            const {data} = await axios.get<ApiStationSchedule[]>(scheduleUrl.value, {
                signal,
                params: {rows: 40},
            });
            return data;
        },
        enabled: queryEnabled,
        refetchInterval: POLL_MS,
    });

    const wheelsQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheels_list']),
        queryFn: async ({signal}) => {
            const {data} = await axios.get<{rows: ClockWheelListItem[]}>(wheelsUrl.value, {
                signal,
                params: {internal: true, rowCount: 0},
            });
            return data.rows ?? [];
        },
        enabled: queryEnabled,
        staleTime: 5 * 60 * 1000,
        refetchInterval: POLL_MS,
    });

    const queueQuery = useQuery({
        queryKey: queryKeyWithStation([QueryKeys.StationQueue, 'live_tab']),
        queryFn: async ({signal}) => {
            const {data} = await axiosSilent.get<{rows: QueueRow[]}>(queueUrl.value, {
                signal,
                params: {internal: true, rowCount: 0},
            });
            return data.rows ?? [];
        },
        enabled: queryEnabled,
        refetchInterval: POLL_MS,
    });

    const ongoingEvents = computed(() =>
        (scheduleQuery.data.value ?? []).filter((e) => e.is_now)
    );

    const activeClockWheelEvent = computed(() =>
        ongoingEvents.value.find((e) => e.type === 'clock_wheel')
    );

    const activeWheelMeta = computed(() => {
        const event = activeClockWheelEvent.value;
        if (!event?.name) {
            return null;
        }
        const wheels = wheelsQuery.data.value ?? [];
        return wheels.find((w) => w.name === event.name && w.is_active) ?? null;
    });

    const wheelDetailQuery = useQuery({
        queryKey: computed(() =>
            queryKeyWithStation(
                [QueryKeys.StationPlaylists, 'clock_wheel_detail', activeWheelMeta.value?.id],
            )
        ),
        queryFn: async ({signal}) => {
            const meta = activeWheelMeta.value;
            if (!meta) {
                return null;
            }
            const {data} = await axios.get<ClockWheelDetail>(meta.links.self, {signal});
            return data;
        },
        enabled: computed(() => queryEnabled.value && activeWheelMeta.value !== null),
        refetchInterval: POLL_MS,
    });

    const activeWheel = computed(() => wheelDetailQuery.data.value ?? null);

    const secondsIntoHour = computed(() => {
        const dt = now();
        return dt.minute * 60 + dt.second;
    });

    const handDegrees = computed(() => (secondsIntoHour.value / 3600) * 360);

    const stationTimeLabel = computed(() => {
        const dt = now();
        return dt.toLocaleString({
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
        });
    });

    const wheelQueueRows = computed(() => {
        const wheelName = activeWheel.value?.name;
        if (!wheelName) {
            return [];
        }
        return (queueQuery.data.value ?? [])
            .filter((row) => row.clock_wheel === wheelName)
            .sort((a, b) => (a.played_at ?? 0) - (b.played_at ?? 0));
    });

    const slotsWithTracks = computed(() => {
        const slots = [...(activeWheel.value?.slots ?? [])].sort(
            (a, b) => a.position_seconds - b.position_seconds
        );
        const tracks = wheelQueueRows.value;
        return slots.map((slot, index) => ({
            slot,
            trackLabel: tracks[index] ? songLabel(tracks[index]) : null,
            isPast: slot.position_seconds <= secondsIntoHour.value,
            isCurrent: (() => {
                const next = slots[index + 1];
                const start = slot.position_seconds;
                const end = next?.position_seconds ?? 3600;
                return secondsIntoHour.value >= start && secondsIntoHour.value < end;
            })(),
        }));
    });

    const conflictMessage = computed(() => {
        const ongoing = ongoingEvents.value;
        const hasPlaylistOrStreamer = ongoing.some(
            (e) => e.type === 'playlist' || e.type === 'streamer'
        );
        if (hasPlaylistOrStreamer) {
            const names = ongoing
                .filter((e) => e.type === 'playlist' || e.type === 'streamer')
                .map((e) => e.description ?? e.title ?? e.name)
                .join(', ');
            return {active: true, detail: names};
        }
        return {active: false, detail: ''};
    });

    const upcomingWheelEvents = computed(() => {
        const stationNow = now().toSeconds();
        return (scheduleQuery.data.value ?? [])
            .filter((e) => e.type === 'clock_wheel' && !e.is_now && (e.start_timestamp ?? 0) > stationNow)
            .sort((a, b) => (a.start_timestamp ?? 0) - (b.start_timestamp ?? 0))
            .slice(0, 8);
    });

    const isLoading = computed(
        () =>
            scheduleQuery.isPending.value
            || wheelsQuery.isPending.value
            || (activeWheelMeta.value !== null && wheelDetailQuery.isPending.value)
    );

    return {
        stationData,
        activeWheel,
        activeClockWheelEvent,
        slotsWithTracks,
        wheelQueueRows,
        secondsIntoHour,
        handDegrees,
        stationTimeLabel,
        conflictMessage,
        upcomingWheelEvents,
        isLoading,
        now,
    };
}
