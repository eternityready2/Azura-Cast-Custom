<template>
    <div
        id="app-toolbar"
        class="media-toolbar"
    >
        <div class="media-toolbar-utilities d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="media-toolbar-title">
                <span class="media-toolbar-title-mark" />
                <span>{{ $gettext('Library') }}</span>
            </div>

            <div class="media-toolbar-utility-actions d-flex flex-wrap align-items-center gap-2">
                <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    @click="downloadFromUrl"
                >
                    <icon-ic-cloud-download/>
                    <span>
                        {{ $gettext('Download from URL') }}
                    </span>
                </button>

                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    @click="createDirectory"
                >
                    <icon-ic-folder/>
                    <span>
                        {{ $gettext('New Folder') }}
                    </span>
                </button>
            </div>
        </div>

        <div class="media-toolbar-bulk">
            <div class="media-toolbar-section media-toolbar-classify">
                <div class="media-toolbar-section-heading">
                    <span class="media-toolbar-label">
                        {{ $gettext('With selected:') }}
                    </span>
                    <span
                        v-if="selectedItems.all.length > 0"
                        class="media-toolbar-count"
                    >
                        {{ selectedItems.all.length }}
                    </span>
                </div>

                <div class="media-toolbar-controls d-flex flex-wrap align-items-center gap-2">
                    <select
                        id="bulk_media_type"
                        class="form-select form-select-sm bulk-classify-select"
                        :disabled="!hasSelectedClassifyItems || classifyPending"
                        :title="$gettext('Set type on selected files')"
                        @change="onBulkTypeChange"
                    >
                        <option
                            value=""
                            selected
                            disabled
                            hidden
                        >
                            {{ $gettext('Set type…') }}
                        </option>
                        <option
                            v-for="opt in mediaTypeOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.shortLabel }}
                        </option>
                    </select>

                    <select
                        id="bulk_media_category"
                        class="form-select form-select-sm bulk-classify-select"
                        :disabled="!hasSelectedClassifyItems || classifyPending"
                        :title="$gettext('Set category on selected files')"
                        @change="onBulkCategoryChange"
                    >
                        <option
                            value=""
                            selected
                            disabled
                            hidden
                        >
                            {{ $gettext('Set category…') }}
                        </option>
                        <option value="none">
                            {{ $gettext('No category') }}
                        </option>
                        <option
                            v-for="cat in mediaCategories"
                            :key="cat.id"
                            :value="String(cat.id)"
                        >
                            {{ cat.name }}
                        </option>
                    </select>

                    <div class="input-group input-group-sm bulk-genre-group">
                        <input
                            id="bulk_media_genre"
                            v-model="bulkGenre"
                            type="text"
                            class="form-control"
                            :disabled="!hasSelectedClassifyItems || classifyPending"
                            :title="$gettext('Set genre on selected files')"
                            :placeholder="$gettext('Set genre…')"
                            @keyup.enter="onBulkGenreApply"
                        >

                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!hasSelectedClassifyItems || classifyPending || bulkGenre.trim() === ''"
                            @click="onBulkGenreApply"
                        >
                            {{ $gettext('Apply Genre') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="media-toolbar-section media-toolbar-actions">
                <div class="media-toolbar-section-heading">
                    <span class="media-toolbar-label">
                        {{ $gettext('Actions') }}
                    </span>
                </div>

                <div class="media-toolbar-action-controls d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm dropdown allow-focus">
                        <div class="dropdown">
                            <button
                                ref="$playlistDropdown"
                                class="btn btn-sm btn-primary dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                data-bs-auto-close="outside"
                                aria-expanded="false"
                                :disabled="!hasSelectedItems"
                            >
                                <icon-ic-clear-all/>

                                <span>
                                    {{ $gettext('Playlists') }}
                                </span>
                                <span class="caret" />
                            </button>
                            <div
                                class="dropdown-menu"
                                style="min-width: 300px;"
                            >
                                <form
                                    class="px-4 py-3"
                                    @submit.prevent="setPlaylists"
                                >
                                    <div
                                        v-for="playlist in playlists"
                                        :key="playlist.id"
                                        class="form-group"
                                    >
                                        <div class="custom-control custom-checkbox">
                                            <input
                                                :id="'chk_playlist_' + playlist.id"
                                                v-model="checkedPlaylists"
                                                type="checkbox"
                                                class="custom-control-input"
                                                name="playlists[]"
                                                :value="playlist.id"
                                            >
                                            <label
                                                class="custom-control-label"
                                                :for="'chk_playlist_'+playlist.id"
                                            >
                                                {{ playlist.name }}
                                            </label>
                                        </div>
                                    </div>

                                    <hr class="dropdown-divider">

                                    <div class="form-group mt-3 mb-4">
                                        <div class="input-group custom-control custom-checkbox">
                                            <div class="input-group-text">
                                                <input
                                                    id="chk_playlist_new"
                                                    v-model="checkedPlaylists"
                                                    type="checkbox"
                                                    class="custom-control-input"
                                                    value="new"
                                                >
                                                <label
                                                    class="custom-control-label"
                                                    for="chk_playlist_new"
                                                />
                                            </div>

                                            <input
                                                id="new_playlist_name"
                                                v-model="newPlaylist"
                                                type="text"
                                                class="form-control p-2"
                                                name="new_playlist_name"
                                                style="min-width: 150px;"
                                                :placeholder="$gettext('New Playlist')"
                                            >
                                        </div>
                                    </div>

                                    <div class="buttons">
                                        <button
                                            class="btn btn-primary"
                                            type="submit"
                                        >
                                            {{ $gettext('Save') }}
                                        </button>
                                        <button
                                            class="btn btn-warning"
                                            type="button"
                                            @click="clearPlaylists()"
                                        >
                                            {{ $gettext('Clear') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-secondary"
                        :disabled="!hasSelectedItems"
                        @click="moveFiles"
                    >
                        <icon-ic-open-with/>

                        <span>
                            {{ $gettext('Move') }}
                        </span>
                    </button>

                    <div class="btn-group btn-group-sm dropdown allow-focus">
                        <div class="dropdown">
                            <button
                                class="btn btn-sm btn-secondary dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                :disabled="!hasSelectedItems"
                            >
                                <icon-ic-more-horiz/>
                                <span>
                                    {{ $gettext('More') }}
                                </span>
                                <span class="caret" />
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <button
                                        type="button"
                                        :title="$gettext('Queue the selected media to play next')"
                                        class="dropdown-item"
                                        @click="doQueue"
                                    >
                                        {{ $gettext('Queue') }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        v-if="supportsImmediateQueue"
                                        type="button"
                                        class="dropdown-item"
                                        :title="$gettext('Make the selected media play immediately, interrupting existing media')"
                                        @click="doImmediateQueue"
                                    >
                                        {{ $gettext('Play Now') }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item"
                                        :title="$gettext('Analyze and reprocess the selected media')"
                                        @click="doReprocess"
                                    >
                                        {{ $gettext('Reprocess') }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item"
                                        :title="$gettext('Remove any extra metadata (fade points, cue points, etc.) from the selected media')"
                                        @click="doClearExtra"
                                    >
                                        {{ $gettext('Clear Extra Metadata') }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item"
                                        :disabled="!hasSelectedClassifyItems"
                                        :title="$gettext('Create or populate playlists from the MP3 genre of selected files')"
                                        @click="createGenrePlaylists"
                                    >
                                        {{ $gettext('Create Playlists from Genre') }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-danger"
                        :disabled="!hasSelectedItems"
                        @click="doDelete"
                    >
                        <icon-ic-delete/>

                        <span>
                            {{ $gettext('Delete') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <genre-playlists-modal
        ref="$genrePlaylistsModal"
        :selected-items="selectedItems"
        :current-directory="currentDirectory"
        :batch-url="batchUrl"
        @relist="emit('relist')"
        @add-playlist="(playlist) => emit('add-playlist', playlist)"
    />
</template>

<script setup lang="ts">
import {Dropdown} from "bootstrap";
import {filter, intersection, map} from "es-toolkit/compat";
import {computed, ref, toRef, useTemplateRef, watch} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import useHandleBatchResponse from "~/components/Stations/Media/useHandleBatchResponse.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import type {MediaInitialPlaylist, MediaSelectedItems} from "~/components/Stations/Media.vue";
import {ApiStationMediaPlaylist} from "~/entities/ApiInterfaces";
import IconIcClearAll from "~icons/ic/baseline-clear-all";
import IconIcCloudDownload from "~icons/ic/baseline-cloud-download";
import IconIcDelete from "~icons/ic/baseline-delete";
import IconIcFolder from "~icons/ic/baseline-folder";
import IconIcMoreHoriz from "~icons/ic/baseline-more-horiz";
import IconIcOpenWith from "~icons/ic/baseline-open-with";
import {formatMediaType, getMediaTypeOptions} from "~/functions/mediaTypes.ts";
import GenrePlaylistsModal from "~/components/Stations/Media/GenrePlaylistsModal.vue";

const props = defineProps<{
    currentDirectory: string,
    selectedItems: MediaSelectedItems,
    playlists?: MediaInitialPlaylist[],
    batchUrl: string,
    supportsImmediateQueue: boolean,
    mediaCategories?: {id: number; name: string}[],
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
    (e: 'add-playlist', playlist: any): void,
    (e: 'move-files'): void,
    (e: 'create-directory'): void,
    (e: 'download-from-url'): void
}>();

const {$gettext} = useTranslate();

const selectedItems = toRef(props, 'selectedItems');

const hasSelectedItems = computed(() => {
    return selectedItems.value.all.length > 0;
});

const hasSelectedClassifyItems = computed(
    () => selectedItems.value.files.length > 0 || selectedItems.value.directories.length > 0
);

const mediaCategories = computed(() => props.mediaCategories ?? []);
const mediaTypeOptions = computed(() =>
    getMediaTypeOptions($gettext).map((opt) => ({
        ...opt,
        shortLabel: formatMediaType(opt.value, $gettext),
    }))
);

const classifyPending = ref(false);
const bulkGenre = ref('');

const checkedPlaylists = ref<(number | string)[]>([]);
const newPlaylist = ref('');

watch(selectedItems, (items) => {
    if (items.all.length === 0) {
        checkedPlaylists.value = [];
        return;
    }

    // Get all playlists that are active on ALL selected items.
    const playlistsForItems = map(items.all, (item) => {
        const itemPlaylists = (item.dir?.playlists ?? item.media?.playlists ?? []) as Required<ApiStationMediaPlaylist>[];

        return map(
            filter(
                itemPlaylists,
                (row) => row.folder === null
            ),
            'id'
        );
    });

    // Check the checkboxes for those playlists.
    checkedPlaylists.value = intersection(...playlistsForItems);
});

watch(newPlaylist, (text: string) => {
    if (text !== '') {
        if (!checkedPlaylists.value.includes('new')) {
            checkedPlaylists.value.push('new');
        }
    }
});

const {axios} = useAxios();

const {handleBatchResponse} = useHandleBatchResponse();

const {notifyError} = useNotify();

const notifyNoFiles = () => {
    notifyError($gettext('No files selected.'));
}

const doBatch = async (
    action: string,
    successMessage: string,
    errorMessage: string,
    extraPayload: Record<string, unknown> = {},
) => {
    if (hasSelectedItems.value) {
        const {data} = await axios.put(props.batchUrl, {
            'do': action,
            'current_directory': props.currentDirectory,
            'files': selectedItems.value.files,
            'dirs': selectedItems.value.directories,
            ...extraPayload,
        });

        handleBatchResponse(data, successMessage, errorMessage);
        emit('relist');
    } else {
        notifyNoFiles();
    }
};

const applyClassify = async (payload: Record<string, unknown>) => {
    if (!hasSelectedClassifyItems.value) {
        notifyNoFiles();
        return;
    }

    classifyPending.value = true;

    try {
        await doBatch(
            'classify',
            $gettext('Updated metadata for files:'),
            $gettext('Error updating files:'),
            payload,
        );
    } finally {
        classifyPending.value = false;
    }
};

const resetBulkSelect = (select: HTMLSelectElement) => {
    select.selectedIndex = 0;
};

const onBulkTypeChange = async (event: Event) => {
    const select = event.currentTarget as HTMLSelectElement;
    const type = select.value;
    resetBulkSelect(select);

    if (!type || classifyPending.value) {
        return;
    }

    await applyClassify({media_type: type});
};

const onBulkCategoryChange = async (event: Event) => {
    const select = event.currentTarget as HTMLSelectElement;
    const category = select.value;
    resetBulkSelect(select);

    if (!category || classifyPending.value) {
        return;
    }

    await applyClassify({
        category_id: category === 'none' ? null : Number(category),
    });
};

const onBulkGenreApply = async () => {
    const genre = bulkGenre.value.trim();

    if (!genre || classifyPending.value) {
        return;
    }

    await applyClassify({genre});
    bulkGenre.value = '';
};

const doImmediateQueue = () => {
    void doBatch(
        'immediate',
        $gettext('Files played immediately:'),
        $gettext('Error queueing files:')
    );
};

const doQueue = () => {
    void doBatch(
        'queue',
        $gettext('Files queued for playback:'),
        $gettext('Error queueing files:')
    );
};

const doReprocess = () => {
    void doBatch(
        'reprocess',
        $gettext('Files marked for reprocessing:'),
        $gettext('Error reprocessing files:')
    );
};

const doClearExtra = () => {
    void doBatch(
        'clear-extra',
        $gettext('Extra metadata cleared for files:'),
        $gettext('Error reprocessing files:')
    );
};

const {confirmDelete} = useDialog();

const doDelete = async () => {
    const numFiles = selectedItems.value.all.length;
    const buttonConfirmText = $gettext(
        'Delete %{num} media files?',
        {num: String(numFiles)}
    );

    const {value} = await confirmDelete({
        title: buttonConfirmText,
        confirmButtonText: $gettext('Delete')
    });

    if (!value) {
        return;
    }

    await doBatch(
        'delete',
        $gettext('Files removed:'),
        $gettext('Error removing files:')
    );
};

const $playlistDropdown = useTemplateRef('$playlistDropdown');

const setPlaylists = async () => {
    if ($playlistDropdown.value) {
        Dropdown.getInstance($playlistDropdown.value)?.hide();
    }

    if (hasSelectedItems.value) {
        const {data} = await axios.put(props.batchUrl, {
            'do': 'playlist',
            'playlists': checkedPlaylists.value,
            'new_playlist_name': newPlaylist.value,
            'currentDirectory': props.currentDirectory,
            'files': selectedItems.value.files,
            'dirs': selectedItems.value.directories
        });

        handleBatchResponse(
            data,
            (checkedPlaylists.value.length > 0)
                ? $gettext('Playlists updated for selected files:')
                : $gettext('Playlists cleared for selected files:'),
            $gettext('Error updating playlists:')
        );

        if (data.success) {
            if (data.record) {
                emit('add-playlist', data.record);
            }

            checkedPlaylists.value = [];
            newPlaylist.value = '';
        }

        emit('relist');
    } else {
        notifyNoFiles();
    }
};

const clearPlaylists = () => {
    checkedPlaylists.value = [];
    newPlaylist.value = '';

    void setPlaylists();
};

const moveFiles = () => {
    emit('move-files');
}

const createDirectory = () => {
    emit('create-directory');
}

const downloadFromUrl = () => {
    emit('download-from-url');
}

const $genrePlaylistsModal = useTemplateRef('$genrePlaylistsModal');

const createGenrePlaylists = () => {
    void $genrePlaylistsModal.value?.open();
};
</script>

<style lang="scss" scoped>
.media-toolbar {
    overflow: visible;
    margin-bottom: 1.6rem;
    padding: 0.55rem;
    border: 0;
    border-radius: 1.1rem;
    background:
        linear-gradient(135deg, rgba(var(--bs-primary-rgb), 0.14), transparent 42%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.025), transparent 55%),
        var(--bs-secondary-bg);
    box-shadow:
        0 1rem 2.5rem rgba(0, 0, 0, 0.16),
        inset 0 1px 0 rgba(255, 255, 255, 0.045);
}

.media-toolbar-utilities {
    min-height: 3.15rem;
    padding: 0.35rem 0.45rem 0.7rem;
}

.media-toolbar-title {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding-left: 0.2rem;
    color: var(--bs-secondary-color);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}

.media-toolbar-title-mark {
    display: inline-block;
    width: 0.3rem;
    height: 1.15rem;
    border-radius: 999px;
    background: linear-gradient(180deg, var(--bs-primary), rgba(var(--bs-primary-rgb), 0.42));
    box-shadow: 0 0 0.8rem rgba(var(--bs-primary-rgb), 0.32);
}

.media-toolbar-bulk {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(20rem, 0.85fr);
    gap: 0.65rem;
}

.media-toolbar-section {
    min-width: 0;
    padding: 0.72rem 0.78rem 0.78rem;
    border-radius: 0.85rem;
    background: var(--bs-body-bg);
    box-shadow:
        0 0.3rem 0.9rem rgba(0, 0, 0, 0.09),
        inset 0 1px 0 rgba(255, 255, 255, 0.035);
}

.media-toolbar-classify {
    background:
        linear-gradient(120deg, rgba(var(--bs-primary-rgb), 0.065), transparent 48%),
        var(--bs-body-bg);
}

.media-toolbar-section-heading {
    display: flex;
    min-height: 1.15rem;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.55rem;
}

.media-toolbar-label {
    color: var(--bs-secondary-color);
    font-size: 0.69rem;
    font-weight: 700;
    letter-spacing: 0.075em;
    text-transform: uppercase;
}

.media-toolbar-count {
    display: inline-flex;
    min-width: 1.3rem;
    height: 1.3rem;
    align-items: center;
    justify-content: center;
    padding: 0 0.38rem;
    border-radius: 999px;
    background: rgba(var(--bs-primary-rgb), 0.16);
    color: var(--bs-primary-text-emphasis);
    font-size: 0.67rem;
    font-weight: 700;
}

.media-toolbar-controls,
.media-toolbar-action-controls {
    min-height: 2.15rem;
}

.media-toolbar .form-select,
.media-toolbar .form-control {
    min-height: 2.15rem;
    border-color: transparent;
    border-radius: 0.58rem;
    background-color: var(--bs-tertiary-bg);
    box-shadow: inset 0 0 0 1px var(--bs-border-color-translucent);
    transition:
        box-shadow 130ms ease,
        background-color 130ms ease;
}

.media-toolbar .form-select:focus,
.media-toolbar .form-control:focus {
    border-color: transparent;
    box-shadow:
        inset 0 0 0 1px rgba(var(--bs-primary-rgb), 0.72),
        0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.12);
}

.media-toolbar .btn {
    min-height: 2.15rem;
    border-radius: 0.58rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    box-shadow: 0 0.22rem 0.55rem rgba(0, 0, 0, 0.11);
    transition:
        transform 120ms ease,
        box-shadow 120ms ease,
        filter 120ms ease;
}

.media-toolbar .btn:not(:disabled):hover {
    transform: translateY(-1px);
    box-shadow: 0 0.4rem 0.9rem rgba(0, 0, 0, 0.14);
    filter: brightness(1.035);
}

.media-toolbar .btn:disabled {
    box-shadow: none;
}

.media-toolbar .btn-primary:not(:disabled) {
    box-shadow: 0 0.3rem 0.85rem rgba(var(--bs-primary-rgb), 0.2);
}

.media-toolbar .btn-danger:not(:disabled) {
    box-shadow: 0 0.3rem 0.8rem rgba(var(--bs-danger-rgb), 0.16);
}

.bulk-classify-select {
    width: auto;
    min-width: 8.25rem;
    max-width: 10.75rem;
}

.bulk-genre-group {
    width: auto;
    min-width: 15rem;
    max-width: 18rem;
}

.bulk-genre-group > .form-control {
    border-radius: 0.58rem 0 0 0.58rem;
}

.bulk-genre-group > .btn {
    border-radius: 0 0.58rem 0.58rem 0;
}

@media (max-width: 1199.98px) {
    .media-toolbar-bulk {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .media-toolbar {
        padding: 0.45rem;
        border-radius: 0.9rem;
    }

    .media-toolbar-utilities {
        align-items: flex-start !important;
        padding: 0.35rem 0.35rem 0.65rem;
    }

    .media-toolbar-title,
    .media-toolbar-utility-actions,
    .media-toolbar-controls,
    .media-toolbar-action-controls {
        width: 100%;
    }

    .media-toolbar-utility-actions {
        justify-content: flex-start;
    }

    .media-toolbar-section {
        padding: 0.68rem;
    }

    .bulk-classify-select,
    .bulk-genre-group {
        flex: 1 1 100%;
        width: 100%;
        max-width: none;
    }
}
</style>
