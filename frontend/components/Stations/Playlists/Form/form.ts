import {useAppScopedRegle} from "~/vendor/regle.ts";
import {useResettableRef} from "~/functions/useResettableRef.ts";
import {defineStore} from "pinia";
import {required} from "@regle/rules";
import {
    PlaylistOrders,
    PlaylistRemoteTypes,
    PlaylistSources,
    PlaylistTypes,
    StationPlaylist
} from "~/entities/ApiInterfaces.ts";

export type StationPlaylistsRecord = Required<
    Omit<
        StationPlaylist,
        | 'id'
        | 'podcasts'
    >
> & {
    id: number | null
}

export const useStationsPlaylistsForm = defineStore(
    'form-stations-playlists',
    () => {
        const {record: form, reset} = useResettableRef<StationPlaylistsRecord>({
            id: null,
            name: '',
            description: '',
            is_enabled: true,
            include_in_on_demand: false,
            weight: 3,
            type: PlaylistTypes.Standard,
            source: PlaylistSources.Songs,
            order: PlaylistOrders.Shuffle,
            remote_url: null,
            remote_type: PlaylistRemoteTypes.Stream,
            remote_buffer: 0,
            is_jingle: false,
            play_per_songs: 0,
            play_per_minutes: 0,
            play_per_hour_minute: 0,
            include_in_requests: true,
            avoid_duplicates: true,
            rotation_goal_days: null as number | null,
            crossfade_profile: null as string | null,
            backend_options: [],
            schedule_items: [],
            is_smart_block: false,
            smart_block_match_type: 'all',
            smart_block_limit: null as number | null,
            smart_block_limit_type: 'tracks'
        });

        const {r$} = useAppScopedRegle(
            form,
            {
                name: {required},
            },
            {
                namespace: 'stations-playlists',
                validationGroups: (fields) => ({
                    basicInfoTab: [
                        fields.name,
                        fields.is_enabled,
                        fields.include_in_on_demand,
                        fields.weight,
                        fields.type,
                        fields.source,
                        fields.order,
                        fields.remote_url,
                        fields.remote_type,
                        fields.remote_buffer,
                        fields.is_jingle,
                        fields.play_per_songs,
                        fields.play_per_minutes,
                        fields.play_per_hour_minute,
                        fields.include_in_requests,
                        fields.avoid_duplicates,
                        fields.rotation_goal_days,
                        fields.crossfade_profile,
                        fields.is_smart_block,
                        fields.smart_block_match_type,
                        fields.smart_block_limit,
                        fields.smart_block_limit_type,
                    ],
                    advancedTab: [
                        fields.backend_options
                    ]
                })
            }
        );

        const $reset = () => {
            reset();
            r$.$reset();
        }

        return {
            form,
            r$,
            $reset
        }
    }
);
