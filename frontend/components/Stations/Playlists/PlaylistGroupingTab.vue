<template>
    <tab
        ref="$tab"
        id="playlist_grouping"
        :label="$gettext('Playlist Grouping')"
    >
        <div class="card-body-flush">
            <div
                v-if="loading"
                class="p-5 text-center"
            >
                <div class="spinner-border" />
            </div>

            <div
                v-else
                class="row gx-1 pt-3 overflow-hidden"
            >
                <div class="col-6">
                    <h4 class="bg-primary text-bg-primary text-center p-3 mb-0 shadow">
                        {{ $gettext('Playlists') }}
                    </h4>

                    <nav
                        v-if="playlistBreadcrumbs.length"
                        style="--bs-breadcrumb-divider: '>';"
                        aria-label="breadcrumb"
                        class="border border-3 border-top-0 border-primary p-3 overflow-scroll"
                    >
                        <ol class="breadcrumb flex-nowrap m-0">
                            <li class="breadcrumb-item">
                                <a href="#" @click.prevent="navigateFromBreadcrumb()">
                                    <icon-ic-home />
                                </a>
                            </li>
                            <template
                                v-for="(breadcrumb, index) in playlistBreadcrumbs"
                                :key="breadcrumb.id"
                            >
                                <li
                                    v-if="index < playlistBreadcrumbs.length - 1"
                                    class="breadcrumb-item text-nowrap"
                                >
                                    <a
                                        href="#"
                                        class="text-nowrap"
                                        @click.prevent="navigateFromBreadcrumb(index + 1)"
                                    >
                                        {{ breadcrumb.name }}
                                    </a>
                                </li>
                                <li
                                    v-else
                                    class="breadcrumb-item text-nowrap active"
                                    aria-current="page"
                                >
                                    {{ breadcrumb.name }}
                                </li>
                            </template>
                        </ol>
                    </nav>

                    <ul class="list-group list-group-flush h-100 shadow">
                        <li
                            v-if="currentPlaylists.length === 0"
                            class="list-group-item p-5 text-center"
                        >
                            {{ $gettext('No playlists available') }}
                        </li>

                        <li
                            v-for="item in currentPlaylists"
                            :key="item.id"
                            class="list-group-item p-0"
                            :class="{active: selectedPlaylist?.id === item.id}"
                        >
                            <div class="d-flex align-items-center p-3 gap-2">
                                <button
                                    type="button"
                                    class="playlist-selection flex-grow-1 text-start border-0 bg-transparent p-0"
                                    :disabled="!isSelectable(item)"
                                    @click="selectedPlaylist = item"
                                >
                                    <span class="d-block fs-5">{{ item.name }}</span>
                                    <small class="text-muted">{{ item.description }}</small>
                                    <div class="mt-2 d-flex gap-2">
                                        <span class="badge text-bg-secondary">
                                            {{ sourceLabel(item.source) }}
                                        </span>
                                        <span
                                            v-if="item.source === PlaylistSources.Songs"
                                            class="badge bg-primary rounded-pill"
                                        >
                                            {{ item.num_songs }}
                                        </span>
                                        <span
                                            v-else-if="item.source === PlaylistSources.Playlists"
                                            class="badge bg-primary rounded-pill"
                                        >
                                            {{ item.playlists.length }}
                                        </span>
                                    </div>
                                </button>

                                <div class="btn-group-vertical">
                                    <button
                                        v-if="isAssignable(item)"
                                        type="button"
                                        class="btn btn-primary"
                                        :title="$gettext('Add to selected playlist group')"
                                        :disabled="saving"
                                        @click="doAssign(item)"
                                    >
                                        <icon-ic-drive-file-move />
                                    </button>

                                    <button
                                        v-if="item.source === PlaylistSources.Playlists"
                                        type="button"
                                        class="btn btn-secondary"
                                        :title="$gettext('Enter playlist group')"
                                        @click="enterPlaylistGroup(item)"
                                    >
                                        <icon-bi-folder />
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="col-6">
                    <h4 class="bg-primary text-bg-primary text-center p-3 mb-0 shadow">
                        {{ $gettext('Playlist Contents') }}
                    </h4>

                    <div
                        v-if="selectedPlaylist"
                        class="selected-playlist-details border border-3 border-top-0 border-primary p-3 shadow-lg"
                    >
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="fs-5">{{ selectedPlaylist.name }}</span>
                            <span
                                v-if="selectedPlaylist.source === PlaylistSources.Playlists"
                                class="badge bg-primary rounded-pill"
                            >
                                {{ playlistMembers.length }}
                            </span>
                        </div>
                        <div class="text-muted mt-1">
                            {{ selectedPlaylist.description }}
                        </div>
                    </div>

                    <ul class="list-group list-group-flush h-100 shadow">
                        <li
                            v-if="!selectedPlaylist"
                            class="list-group-item p-5 text-center"
                        >
                            {{ $gettext('No playlist selected') }}
                        </li>

                        <li
                            v-else-if="selectedPlaylist.source !== PlaylistSources.Playlists"
                            class="list-group-item p-5 text-center text-muted"
                        >
                            {{ $gettext('Select a Playlist Group to manage its member playlists.') }}
                        </li>

                        <li
                            v-else-if="playlistMembers.length === 0"
                            class="list-group-item p-5 text-center"
                        >
                            {{ $gettext('No playlists assigned') }}
                        </li>

                        <li
                            v-for="(member, index) in playlistMembers"
                            v-else
                            :key="`${selectedPlaylist.id}-${member.id}-${index}`"
                            class="list-group-item"
                        >
                            <div class="d-flex gap-3">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <span class="fs-5">{{ member.name }}</span>
                                            <div class="mt-1">
                                                <span class="badge text-bg-secondary">
                                                    {{ sourceLabel(member.source) }}
                                                </span>
                                                <span
                                                    v-if="!member.is_enabled"
                                                    class="badge text-bg-danger ms-1"
                                                >
                                                    {{ $gettext('Disabled') }}
                                                </span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            :title="$gettext('Remove from group')"
                                            :disabled="saving"
                                            @click="doRemove(index)"
                                        >
                                            <icon-ic-delete />
                                        </button>
                                    </div>

                                    <div class="row g-2 mt-2">
                                        <form-group-field
                                            :id="`consecutive-plays-${index}`"
                                            class="col-12 col-xl-6"
                                            :model-value="member.consecutive_plays"
                                            @update:model-value="doUpdateConsecutivePlays(index, $event)"
                                            input-type="number"
                                            :input-attrs="{min: '0', disabled: saving || member.play_full_cycle}"
                                            :label="$gettext('Consecutive Plays')"
                                        />

                                        <form-group-select
                                            :id="`allowed-requests-${index}`"
                                            class="col-12 col-xl-6"
                                            :model-value="member.allowed_requests"
                                            @update:model-value="doUpdateAllowedRequests(index, $event)"
                                            :options="getAllowedRequestsOptions(member)"
                                            :disabled="saving"
                                            :label="$gettext('Allowed Requests')"
                                        />
                                    </div>

                                    <form-group-checkbox
                                        v-if="isFullCyclePlayable(member)"
                                        :id="`play-full-cycle-${index}`"
                                        class="mt-2"
                                        :model-value="member.play_full_cycle"
                                        @update:model-value="doUpdatePlayFullCycle(index, $event)"
                                        :input-attrs="{disabled: saving}"
                                        :label="$gettext('Play fully before advancing')"
                                    />

                                    <div class="btn-group btn-group-sm mt-2">
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="saving || index === 0"
                                            :title="$gettext('Move to Top')"
                                            @click="doMoveToTop(index)"
                                        >
                                            <icon-bi-chevron-bar-up />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-primary"
                                            :disabled="saving || index === 0"
                                            :title="$gettext('Move Up')"
                                            @click="doMoveUp(index)"
                                        >
                                            <icon-bi-chevron-up />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-primary"
                                            :disabled="saving || index === playlistMembers.length - 1"
                                            :title="$gettext('Move Down')"
                                            @click="doMoveDown(index)"
                                        >
                                            <icon-bi-chevron-down />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="saving || index === playlistMembers.length - 1"
                                            :title="$gettext('Move to Bottom')"
                                            @click="doMoveToBottom(index)"
                                        >
                                            <icon-bi-chevron-bar-down />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </tab>
</template>

<script setup lang="ts">
import {useDebounceFn} from "@vueuse/core";
import {ref, useTemplateRef, watch} from "vue";
import Tab from "~/components/Common/Tab.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import {
    PlaylistGroupAllowedRequests,
    PlaylistOrders,
    PlaylistSources,
} from "~/entities/ApiInterfaces.ts";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import IconBiChevronBarDown from "~icons/bi/chevron-bar-down";
import IconBiChevronBarUp from "~icons/bi/chevron-bar-up";
import IconBiChevronDown from "~icons/bi/chevron-down";
import IconBiChevronUp from "~icons/bi/chevron-up";
import IconBiFolder from "~icons/bi/folder";
import IconIcDelete from "~icons/ic/baseline-delete";
import IconIcDriveFileMove from "~icons/ic/baseline-drive-file-move";
import IconIcHome from "~icons/ic/baseline-home";

type PlaylistBreadcrumb = {id: number; name: string};

type GroupMember = {
    id: number;
    name: string;
    weight: number;
    consecutive_plays: number;
    play_full_cycle: boolean;
    allowed_requests: PlaylistGroupAllowedRequests;
    source: PlaylistSources | string;
    order: PlaylistOrders | string;
    num_songs: number;
    is_enabled: boolean;
    playlists: GroupMember[];
};

type PlaylistRow = {
    id: number;
    name: string;
    description: string | null;
    source: PlaylistSources | string;
    order: PlaylistOrders | string;
    num_songs: number;
    is_enabled: boolean;
    playlists: GroupMember[];
    links: {self: string; members?: string};
};

const props = defineProps<{listUrl: string}>();
const $tab = useTemplateRef<InstanceType<typeof Tab>>("$tab");

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();

const loading = ref(true);
const saving = ref(false);
const playlists = ref<PlaylistRow[]>([]);
const currentPlaylists = ref<PlaylistRow[]>([]);
const playlistBreadcrumbs = ref<PlaylistBreadcrumb[]>([]);
const selectedPlaylist = ref<PlaylistRow>();
const playlistMembers = ref<GroupMember[]>([]);

watch(
    () => $tab.value?.isActive,
    (isActive) => {
        if (isActive) {
            void loadPlaylists();
        }
    }
);

watch(selectedPlaylist, (playlist) => {
    playlistMembers.value = playlist?.source === PlaylistSources.Playlists
        ? [...(playlist.playlists ?? [])]
        : [];
});

const normalizeRow = (row: Record<string, any>): PlaylistRow => ({
    ...row,
    description: row.description ?? '',
    num_songs: Number(row.num_songs ?? 0),
    is_enabled: Boolean(row.is_enabled),
    playlists: Array.isArray(row.playlists)
        ? row.playlists.map((member: Record<string, any>) => ({
            id: Number(member.id),
            name: String(member.name ?? ''),
            weight: Number(member.weight ?? 0),
            consecutive_plays: Number(member.consecutive_plays ?? 0),
            play_full_cycle: Boolean(member.play_full_cycle),
            allowed_requests: (member.allowed_requests ?? PlaylistGroupAllowedRequests.Any) as PlaylistGroupAllowedRequests,
            source: PlaylistSources.Songs,
            order: PlaylistOrders.Shuffle,
            num_songs: 0,
            is_enabled: true,
            playlists: [],
        }))
        : [],
    links: row.links ?? {self: ''},
});

const buildTree = (raw: PlaylistRow[]): PlaylistRow[] => {
    const map = new Map(raw.map((playlist) => [playlist.id, playlist]));

    for (const playlist of raw) {
        if (playlist.source !== PlaylistSources.Playlists) {
            continue;
        }

        playlist.playlists = playlist.playlists
            .map((member) => {
                const full = map.get(member.id);
                return full
                    ? {
                        ...member,
                        source: full.source,
                        order: full.order,
                        num_songs: full.num_songs,
                        is_enabled: full.is_enabled,
                        playlists: full.playlists,
                    }
                    : member;
            })
            .sort((a, b) => a.weight - b.weight);
    }

    return raw;
};

const fetchAndBuildPlaylists = async () => {
    const {data} = await axios.get(props.listUrl, {params: {rowCount: -1}});
    const rows = Array.isArray(data.rows) ? data.rows : (Array.isArray(data) ? data : []);
    playlists.value = buildTree(rows.map(normalizeRow));
};

const resolveCurrentPlaylistsByBreadcrumbs = (breadcrumbs: PlaylistBreadcrumb[]): PlaylistRow[] => {
    if (breadcrumbs.length === 0) {
        return [...playlists.value];
    }

    let current = playlists.value;
    for (const breadcrumb of breadcrumbs) {
        const group = current.find((playlist) => playlist.id === breadcrumb.id);
        if (!group || group.source !== PlaylistSources.Playlists) {
            continue;
        }

        const resolved = group.playlists
            .map((member) => playlists.value.find((playlist) => playlist.id === member.id))
            .filter((playlist): playlist is PlaylistRow => Boolean(playlist));
        current = resolved;
    }

    return current;
};

const loadPlaylists = async () => {
    loading.value = true;
    selectedPlaylist.value = undefined;
    playlistBreadcrumbs.value = [];

    try {
        await fetchAndBuildPlaylists();
        currentPlaylists.value = [...playlists.value];
    } catch {
        notifyError($gettext('Failed to load playlists.'));
    } finally {
        loading.value = false;
    }
};

const navigateFromBreadcrumb = (breadcrumbIndex = 0) => {
    playlistBreadcrumbs.value.splice(breadcrumbIndex);
    currentPlaylists.value = resolveCurrentPlaylistsByBreadcrumbs(playlistBreadcrumbs.value);
};

const enterPlaylistGroup = (playlist: PlaylistRow) => {
    currentPlaylists.value = playlist.playlists
        .map((member) => playlists.value.find((row) => row.id === member.id))
        .filter((row): row is PlaylistRow => Boolean(row));

    playlistBreadcrumbs.value.push({id: playlist.id, name: playlist.name});
};

const isSelectable = (playlist: PlaylistRow) =>
    [PlaylistSources.Songs, PlaylistSources.Playlists].includes(playlist.source as PlaylistSources);

const isAssignable = (playlist: PlaylistRow) => {
    if (selectedPlaylist.value?.source !== PlaylistSources.Playlists) {
        return false;
    }

    if (![PlaylistSources.Songs, PlaylistSources.Requests, PlaylistSources.Playlists].includes(playlist.source as PlaylistSources)) {
        return false;
    }

    if (playlist.id === selectedPlaylist.value.id) {
        return false;
    }

    return !playlistBreadcrumbs.value.some((breadcrumb) => breadcrumb.id === selectedPlaylist.value?.id);
};

const saveMembersForSelected = async (members: GroupMember[]) => {
    const group = selectedPlaylist.value;
    if (!group?.links.members) {
        return;
    }

    saving.value = true;
    try {
        await axios.put(group.links.members, {
            members: members.map((member, index) => ({
                id: member.id,
                weight: index + 1,
                consecutive_plays: member.consecutive_plays ?? 0,
                play_full_cycle: member.play_full_cycle ?? false,
                allowed_requests: member.allowed_requests ?? PlaylistGroupAllowedRequests.Any,
            })),
        });

        const selectedId = group.id;
        const breadcrumbs = [...playlistBreadcrumbs.value];
        await fetchAndBuildPlaylists();
        currentPlaylists.value = resolveCurrentPlaylistsByBreadcrumbs(breadcrumbs);
        selectedPlaylist.value = playlists.value.find((playlist) => playlist.id === selectedId);
        notifySuccess($gettext('Playlist group updated.'));
    } catch {
        notifyError($gettext('Failed to update playlist group.'));
    } finally {
        saving.value = false;
    }
};

const debouncedSaveMembers = useDebounceFn(
    (members: GroupMember[]) => saveMembersForSelected(members),
    1200
);

const doAssign = async (playlist: PlaylistRow) => {
    const member: GroupMember = {
        id: playlist.id,
        name: playlist.name,
        weight: playlistMembers.value.length + 1,
        consecutive_plays: 0,
        play_full_cycle: false,
        allowed_requests: PlaylistGroupAllowedRequests.Any,
        source: playlist.source,
        order: playlist.order,
        num_songs: playlist.num_songs,
        is_enabled: playlist.is_enabled,
        playlists: playlist.playlists,
    };
    await saveMembersForSelected([...playlistMembers.value, member]);
};

const doRemove = async (index: number) => {
    const updated = [...playlistMembers.value];
    updated.splice(index, 1);
    await saveMembersForSelected(updated);
};

const move = async (index: number, newIndex: number) => {
    const updated = [...playlistMembers.value];
    const [item] = updated.splice(index, 1);
    updated.splice(newIndex, 0, item);
    await saveMembersForSelected(updated);
};

const doMoveUp = (index: number) => move(index, index - 1);
const doMoveDown = (index: number) => move(index, index + 1);
const doMoveToTop = (index: number) => move(index, 0);
const doMoveToBottom = (index: number) => move(index, playlistMembers.value.length - 1);

const doUpdateConsecutivePlays = (index: number, value: number | null) => {
    const updated = [...playlistMembers.value];
    updated[index] = {...updated[index], consecutive_plays: Math.max(0, value ?? 0)};
    playlistMembers.value = updated;
    void debouncedSaveMembers(updated);
};

const isFullCyclePlayable = (member: GroupMember) =>
    member.source === PlaylistSources.Songs
    && [PlaylistOrders.Sequential, PlaylistOrders.Shuffle].includes(member.order as PlaylistOrders);

const doUpdatePlayFullCycle = (index: number, value: boolean | null) => {
    const updated = [...playlistMembers.value];
    updated[index] = {
        ...updated[index],
        play_full_cycle: value ?? false,
        consecutive_plays: value ? 0 : updated[index].consecutive_plays,
    };
    playlistMembers.value = updated;
    void debouncedSaveMembers(updated);
};

const getAllowedRequestsOptions = (member: GroupMember) => {
    const options: Record<string, string> = {
        [PlaylistGroupAllowedRequests.Any]: $gettext('Any (Default)'),
    };

    if ([PlaylistSources.Songs, PlaylistSources.Playlists].includes(member.source as PlaylistSources)) {
        options[PlaylistGroupAllowedRequests.Playlist] = $gettext('Playlist Media Only');
    }

    options[PlaylistGroupAllowedRequests.None] = $gettext('None');
    return options;
};

const doUpdateAllowedRequests = (index: number, value: string | null) => {
    const updated = [...playlistMembers.value];
    updated[index] = {
        ...updated[index],
        allowed_requests: (value ?? PlaylistGroupAllowedRequests.Any) as PlaylistGroupAllowedRequests,
    };
    playlistMembers.value = updated;
    void debouncedSaveMembers(updated);
};

const sourceLabel = (source: PlaylistSources | string) => {
    switch (source) {
        case PlaylistSources.Songs:
            return $gettext('Song-based');
        case PlaylistSources.Playlists:
            return $gettext('Playlist Group');
        case PlaylistSources.Requests:
            return $gettext('Request Queue');
        default:
            return $gettext('Remote URL');
    }
};
</script>

<style lang="scss" scoped>
.selected-playlist-details {
    background-color: var(--bs-secondary-bg);
}

.list-group-item.active {
    background-color: var(--bs-secondary-bg);
    background-image: linear-gradient(to bottom, rgba(0, 0, 0, .12), rgba(0, 0, 0, .12));
    border-color: var(--bs-list-group-border-color);
    color: var(--bs-heading-color);
}

.playlist-selection:enabled {
    cursor: pointer;
}

.min-w-0 {
    min-width: 0;
}
</style>
