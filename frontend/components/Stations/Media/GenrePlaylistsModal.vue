<template>
    <modal
        id="genre_playlists"
        ref="$modal"
        size="lg"
        centered
        :busy="loading"
        :title="$gettext('Create Playlists from Genre')"
        @hidden="reset"
    >
        <template v-if="preview">
            <div
                v-if="preview.entries.length === 0"
                class="alert alert-info"
            >
                {{ $gettext('No selected MP3 files contain a genre.') }}
            </div>

            <div
                v-else
                class="table-responsive"
            >
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ $gettext('Playlist') }}</th>
                            <th>{{ $gettext('Files') }}</th>
                            <th>{{ $gettext('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in preview.entries"
                            :key="entry.name"
                        >
                            <td>{{ entry.name }}</td>
                            <td>{{ entry.media_count }}</td>
                            <td>
                                <span
                                    v-if="entry.status === 'create'"
                                    class="badge text-bg-success"
                                >
                                    {{ $gettext('Create') }}
                                </span>
                                <span
                                    v-else-if="entry.status === 'reuse'"
                                    class="badge text-bg-info"
                                >
                                    {{ $gettext('Reuse') }}
                                </span>
                                <span
                                    v-else
                                    class="badge text-bg-danger"
                                >
                                    {{ conflictLabel(entry) }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details
                v-if="preview.skipped_count > 0"
                class="mt-3"
            >
                <summary class="text-muted">
                    {{ $gettext(
                        '%{count} file(s) without a genre will be skipped.',
                        {count: String(preview.skipped_count)}
                    ) }}
                </summary>
                <ul class="mb-0 mt-2">
                    <li
                        v-for="file in preview.skipped_files"
                        :key="file"
                    >
                        {{ file }}
                    </li>
                </ul>
            </details>
        </template>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="loading"
                @click="hide"
            >
                {{ $gettext('Cancel') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading || actionableCount === 0"
                @click="execute"
            >
                {{ $gettext('Create and Populate Playlists') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {computed, ref, useTemplateRef} from "vue";
import Modal from "~/components/Common/Modal.vue";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useTranslate} from "~/vendor/gettext.ts";
import {useAxios} from "~/vendor/axios.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import type {MediaInitialPlaylist, MediaSelectedItems} from "~/components/Stations/Media.vue";

type GenrePlaylistEntry = {
    name: string,
    media_ids: number[],
    files: string[],
    media_count: number,
    status: 'create' | 'reuse' | 'conflict',
    playlist_id: number | null,
    conflict_source: string | null,
};

type GenrePlaylistPreview = {
    entries: GenrePlaylistEntry[],
    skipped_files: string[],
    skipped_count: number,
};

type GenrePlaylistResult = GenrePlaylistPreview & {
    summary: {
        created: number,
        reused: number,
        added: number,
        already_present: number,
        skipped: number,
        conflicted: number,
    },
};

type GenrePlaylistBatchResponse<T> = {
    success: boolean,
    errors: string[],
    record: T,
};

const props = defineProps<{
    selectedItems: MediaSelectedItems,
    currentDirectory: string,
    batchUrl: string,
}>();

const emit = defineEmits<{
    (e: 'relist'): void,
    (e: 'add-playlist', playlist: MediaInitialPlaylist): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifyError, notifySuccess} = useNotify();

const loading = ref(false);
const preview = ref<GenrePlaylistPreview | null>(null);

const actionableCount = computed(
    () => preview.value?.entries.filter((entry) => entry.status !== 'conflict').length ?? 0
);

const payload = (action: string) => ({
    do: action,
    current_directory: props.currentDirectory,
    files: props.selectedItems.files,
    dirs: props.selectedItems.directories,
});

const $modal = useTemplateRef('$modal');
const {show, hide} = useHasModal($modal);

const reset = () => {
    preview.value = null;
    loading.value = false;
};

const conflictLabel = (entry: GenrePlaylistEntry): string => {
    return $gettext(
        'Conflict: %{source}',
        {source: entry.conflict_source ?? $gettext('Unknown')}
    );
};

const open = async () => {
    show();
    loading.value = true;

    try {
        const {data} = await axios.put<GenrePlaylistBatchResponse<GenrePlaylistPreview>>(
            props.batchUrl,
            payload('genre-playlists-preview')
        );
        preview.value = data.record;
    } catch {
        notifyError($gettext('Could not preview playlists from genre.'));
        hide();
    } finally {
        loading.value = false;
    }
};

const execute = async () => {
    loading.value = true;

    try {
        const {data} = await axios.put<GenrePlaylistBatchResponse<GenrePlaylistResult>>(
            props.batchUrl,
            payload('genre-playlists')
        );
        const result = data.record;

        for (const entry of result.entries) {
            if (entry.status === 'create' && entry.playlist_id !== null) {
                emit('add-playlist', {
                    id: entry.playlist_id,
                    name: entry.name,
                });
            }
        }

        notifySuccess(
            $gettext(
                '%{created} playlist(s) created, %{reused} reused, %{added} file(s) added, %{present} already present, %{skipped} skipped, %{conflicted} conflict(s).',
                {
                    created: String(result.summary.created),
                    reused: String(result.summary.reused),
                    added: String(result.summary.added),
                    present: String(result.summary.already_present),
                    skipped: String(result.summary.skipped),
                    conflicted: String(result.summary.conflicted),
                }
            )
        );

        hide();
        emit('relist');
    } catch {
        notifyError($gettext('Could not create playlists from genre.'));
    } finally {
        loading.value = false;
    }
};

defineExpose({open});
</script>
