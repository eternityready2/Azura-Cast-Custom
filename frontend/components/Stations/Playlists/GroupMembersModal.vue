<template>
    <modal-form
        ref="$modal"
        size="lg"
        :loading="loading"
        :title="$gettext('Manage Group Members')"
        :error="error"
        @submit="save"
        @hidden="clear"
    >
        <p class="text-muted">
            {{ $gettext('Add playlists in the order they should be selected by this group. The same playlist can be added more than once.') }}
            {{ $gettext('Each occurrence can play a fixed number of consecutive tracks or finish its current Sequential or Shuffle cycle.') }}
        </p>

        <div class="row g-2 align-items-end mb-3">
            <form-group-select
                id="group_member_playlist"
                v-model="selectedPlaylistId"
                class="col-md-9"
                :label="$gettext('Playlist')"
                :options="playlistOptions"
            />
            <div class="col-md-3 d-grid">
                <button
                    type="button"
                    class="btn btn-secondary"
                    :disabled="selectedPlaylistId === null"
                    @click="addMember"
                >
                    {{ $gettext('Add Member') }}
                </button>
            </div>
        </div>

        <div
            v-if="members.length === 0"
            class="alert alert-info mb-0"
        >
            {{ $gettext('This group has no members.') }}
        </div>

        <div
            v-else
            class="table-responsive"
        >
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th class="shrink">
                            {{ $gettext('Position') }}
                        </th>
                        <th>
                            {{ $gettext('Playlist') }}
                        </th>
                        <th class="shrink text-nowrap">
                            {{ $gettext('Playback Order') }}
                        </th>
                        <th class="shrink text-nowrap">
                            {{ $gettext('Consecutive Tracks') }}
                        </th>
                        <th class="shrink text-nowrap">
                            {{ $gettext('Full Cycle') }}
                        </th>
                        <th class="shrink">
                            {{ $gettext('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(member, index) in members"
                        :key="member.key"
                    >
                        <td>{{ index + 1 }}</td>
                        <td>
                            <router-link
                                :to="{
                                    name: 'stations:files:index',
                                    params: {
                                        path: 'playlist:'+member.playlist_id
                                    }
                                }"
                                :title="$gettext('View tracks in playlist')"
                            >
                                {{ member.name }}
                            </router-link>
                        </td>
                        <td>
                            <select
                                v-if="member.source === PlaylistSources.Songs"
                                :id="`group_member_order_${member.key}`"
                                v-model="member.order"
                                class="form-select form-select-sm"
                                :aria-label="$gettext('Playback Order')"
                                @change="changeMemberOrder(member)"
                            >
                                <option
                                    v-for="option in orderOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.text }}
                                </option>
                            </select>
                            <span v-else class="text-muted">
                                {{ $gettext('Not applicable') }}
                            </span>
                        </td>
                        <td>
                            <input
                                :id="`group_member_consecutive_${member.key}`"
                                v-model.number="member.consecutive_plays"
                                class="form-control form-control-sm"
                                type="number"
                                min="1"
                                max="65535"
                                :disabled="member.play_full_cycle"
                                :aria-label="$gettext('Consecutive Tracks')"
                            >
                        </td>
                        <td class="text-center">
                            <input
                                :id="`group_member_full_cycle_${member.key}`"
                                v-model="member.play_full_cycle"
                                class="form-check-input"
                                type="checkbox"
                                :disabled="!member.supports_full_cycle"
                                :title="fullCycleTitle(member)"
                                :aria-label="$gettext('Play Full Cycle')"
                            >
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :disabled="index === 0"
                                    :title="$gettext('Move Up')"
                                    @click="moveUp(index)"
                                >
                                    <icon-bi-chevron-up />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :disabled="index === members.length - 1"
                                    :title="$gettext('Move Down')"
                                    @click="moveDown(index)"
                                >
                                    <icon-bi-chevron-down />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    :title="$gettext('Remove')"
                                    @click="removeMember(index)"
                                >
                                    <icon-bi-trash />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </modal-form>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from "vue";
import type {AxiosError} from "axios";
import IconBiChevronDown from "~icons/bi/chevron-down";
import IconBiChevronUp from "~icons/bi/chevron-up";
import IconBiTrash from "~icons/bi/trash";
import ModalForm from "~/components/Common/ModalForm.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import {PlaylistOrders, PlaylistSources} from "~/entities/ApiInterfaces.ts";
import type {ApiError, StationPlaylist} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useTranslate} from "~/vendor/gettext";

type GroupMemberResponse = {
    id: number,
    playlist_id: number,
    name: string,
    position: number,
    source: PlaylistSources,
    order: PlaylistOrders,
    consecutive_plays: number,
    play_full_cycle: boolean,
    supports_full_cycle: boolean
}

type GroupMember = GroupMemberResponse & {
    key: string
}

type PlaylistListItem = Required<Pick<StationPlaylist, 'id' | 'name' | 'source' | 'order'>>

const props = defineProps<{
    playlistsUrl: string
}>();

const emit = defineEmits<{
    (e: 'saved'): void
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const $modal = useTemplateRef('$modal');
const loading = ref(false);
const error = ref<string | null>(null);
const membersUrl = ref<string | null>(null);
const members = ref<GroupMember[]>([]);
const playlists = ref<PlaylistListItem[]>([]);
const selectedPlaylistId = ref<number | null>(null);
let nextKey = 0;

const playlistOptions = computed(() => [
    {
        value: null,
        text: $gettext('Select a playlist')
    },
    ...playlists.value.map((playlist) => ({
        value: playlist.id,
        text: playlist.name
    }))
]);

const orderOptions = [
    {value: PlaylistOrders.Sequential, text: $gettext('Sequential')},
    {value: PlaylistOrders.Shuffle, text: $gettext('Shuffle')},
    {value: PlaylistOrders.Random, text: $gettext('Random')}
];

const normalizeOrder = (order: PlaylistOrders): PlaylistOrders => {
    return order === PlaylistOrders.SmartShuffle
        ? PlaylistOrders.Shuffle
        : order;
};

const clear = () => {
    loading.value = false;
    error.value = null;
    membersUrl.value = null;
    members.value = [];
    playlists.value = [];
    selectedPlaylistId.value = null;
    nextKey = 0;
};

const open = async (newMembersUrl: string) => {
    clear();
    membersUrl.value = newMembersUrl;
    loading.value = true;
    $modal.value?.show();

    try {
        const [membersResponse, playlistsResponse] = await Promise.all([
            axios.get<GroupMemberResponse[]>(newMembersUrl),
            axios.get<{rows: PlaylistListItem[]}>(props.playlistsUrl, {
                params: {rowCount: 0}
            })
        ]);

        members.value = membersResponse.data.map((member) => {
            const order = normalizeOrder(member.order);
            return {
                ...member,
                order,
                supports_full_cycle: member.source === PlaylistSources.Songs
                    && order !== PlaylistOrders.Random,
                key: `existing-${member.id}`
            };
        });
        playlists.value = (playlistsResponse.data.rows ?? []).filter(
            (playlist) => playlist.source !== PlaylistSources.Group
        );
    } catch (err) {
        const axiosError = err as AxiosError<ApiError>;
        error.value = axiosError.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

const addMember = () => {
    if (selectedPlaylistId.value === null) {
        return;
    }

    const playlist = playlists.value.find(
        (item) => item.id === selectedPlaylistId.value
    );
    if (!playlist) {
        return;
    }

    const existingMember = members.value.find(
        (member) => member.playlist_id === playlist.id
    );
    const order = existingMember?.order ?? normalizeOrder(playlist.order);

    nextKey++;
    members.value.push({
        id: 0,
        playlist_id: playlist.id,
        name: playlist.name,
        position: members.value.length,
        source: playlist.source,
        order,
        consecutive_plays: 1,
        play_full_cycle: false,
        supports_full_cycle: playlist.source === PlaylistSources.Songs
            && order !== PlaylistOrders.Random,
        key: `new-${nextKey}`
    });
};

const moveUp = (index: number) => {
    if (index <= 0) {
        return;
    }

    const member = members.value.splice(index, 1)[0];
    members.value.splice(index - 1, 0, member);
};

const moveDown = (index: number) => {
    if (index >= members.value.length - 1) {
        return;
    }

    const member = members.value.splice(index, 1)[0];
    members.value.splice(index + 1, 0, member);
};

const removeMember = (index: number) => {
    members.value.splice(index, 1);
};

const changeMemberOrder = (changedMember: GroupMember) => {
    const order = normalizeOrder(changedMember.order);

    members.value.forEach((member) => {
        if (member.playlist_id !== changedMember.playlist_id) {
            return;
        }

        member.order = order;
        member.supports_full_cycle = member.source === PlaylistSources.Songs
            && order !== PlaylistOrders.Random;
        if (!member.supports_full_cycle) {
            member.play_full_cycle = false;
        }
    });
};

const fullCycleTitle = (member: GroupMember): string => {
    if (member.supports_full_cycle) {
        return $gettext('Play one complete playlist cycle before continuing to the next member.');
    }

    return $gettext('Full-cycle playback is only available for Sequential or Shuffle song playlists.');
};

const save = async () => {
    if (!membersUrl.value) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        const {data} = await axios.put<GroupMemberResponse[]>(membersUrl.value, {
            members: members.value.map((member) => ({
                playlist_id: member.playlist_id,
                order: member.source === PlaylistSources.Songs
                    ? normalizeOrder(member.order)
                    : undefined,
                consecutive_plays: member.consecutive_plays,
                play_full_cycle: member.play_full_cycle
            }))
        });

        members.value = data.map((member) => ({
            ...member,
            key: `existing-${member.id}`
        }));
        notifySuccess();
        emit('saved');
        $modal.value?.hide();
    } catch (err) {
        const axiosError = err as AxiosError<ApiError>;
        error.value = axiosError.response?.data?.message ?? $gettext('An error occurred.');
    } finally {
        loading.value = false;
    }
};

defineExpose({
    open
});
</script>
