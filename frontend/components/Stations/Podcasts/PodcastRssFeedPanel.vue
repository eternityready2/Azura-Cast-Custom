<template>
    <div class="card mb-4">
        <div class="card-header text-bg-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h3 class="card-title mb-0">
                {{ $gettext('RSS feed catalog') }}
            </h3>
            <div class="btn-group btn-group-sm">
                <button
                    type="button"
                    class="btn btn-light"
                    :disabled="loading"
                    @click="loadFeed"
                >
                    {{ loading ? $gettext('Loading…') : $gettext('Refresh feed list') }}
                </button>
                <button
                    type="button"
                    class="btn btn-success"
                    :disabled="selectedKeys.length === 0 || importing"
                    @click="importSelected"
                >
                    {{ importing ? $gettext('Importing…') : $gettext('Import selected') }}
                </button>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                {{
                    $gettext('All episodes listed in the RSS feed. A checkmark means the audio file exists on disk for that episode. Check the box and import to download, or re-import to replace media.')
                }}
            </p>
            <div
                v-if="errorMessage"
                class="alert alert-warning"
            >
                {{ errorMessage }}
            </div>
            <div
                v-else-if="!loading && items.length === 0"
                class="text-muted"
            >
                {{ $gettext('No items in feed.') }}
            </div>
            <div
                v-else
                class="table-responsive"
                style="max-height: 420px; overflow-y: auto;"
            >
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th
                                class="shrink"
                                scope="col"
                            />
                            <th
                                class="shrink"
                                scope="col"
                            />
                            <th scope="col">
                                {{ $gettext('Episode') }}
                            </th>
                            <th scope="col">
                                {{ $gettext('Published') }}
                            </th>
                            <th scope="col">
                                {{ $gettext('Type') }}
                            </th>
                            <th
                                class="shrink"
                                scope="col"
                            />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in items"
                            :key="row.key"
                        >
                            <td>
                                <input
                                    v-model="selectedKeys"
                                    type="checkbox"
                                    class="form-check-input"
                                    :value="row.key"
                                    :disabled="row.no_audio"
                                >
                            </td>
                            <td class="text-center">
                                <span
                                    v-if="row.has_media"
                                    class="text-success fw-bold"
                                    :title="$gettext('Audio file found in storage — use Import to download or replace from feed')"
                                >&#10003;</span>
                                <span
                                    v-else-if="row.imported"
                                    class="text-warning"
                                    :title="$gettext('Episode row exists but no media file')"
                                >!</span>
                            </td>
                            <td>
                                <span
                                    v-if="row.no_audio"
                                    class="text-muted"
                                >{{ row.title }}</span>
                                <span v-else>{{ row.title }}</span>
                                <span
                                    v-if="row.no_audio"
                                    class="badge text-bg-secondary ms-1"
                                >{{ $gettext('No audio') }}</span>
                            </td>
                            <td>
                                {{ formatTimestampAsDateTime(row.published_at) }}
                            </td>
                            <td>
                                <small class="text-muted">{{ row.enclosure_type || '—' }}</small>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    :disabled="row.no_audio || importing"
                                    @click="importKeys([row.key])"
                                >
                                    {{ $gettext('Import') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <podcast-sync-log-modal ref="$syncLogModal"/>
</template>

<script setup lang="ts">
import {useTranslate} from "~/vendor/gettext";
import {onMounted, ref, watch} from "vue";
import {useAxios} from "~/vendor/axios.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import PodcastSyncLogModal, {SyncLogEntry} from "~/components/Stations/Podcasts/PodcastSyncLogModal.vue";
import {useTemplateRef} from "vue";

export type FeedCatalogItem = {
    key: string;
    title: string;
    published_at: number;
    enclosure_url: string | null;
    enclosure_type: string | null;
    no_audio: boolean;
    imported: boolean;
    episode_id: string | null;
    has_media: boolean;
};

const props = defineProps<{
    podcastId: string;
}>();

const emit = defineEmits<{
    imported: [];
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {formatTimestampAsDateTime} = useStationDateTimeFormatter();
const {notifySuccess, notifyError} = useNotify();

const loading = ref(false);
const importing = ref(false);
const items = ref<FeedCatalogItem[]>([]);
const errorMessage = ref('');
const selectedKeys = ref<string[]>([]);
const $syncLogModal = useTemplateRef<InstanceType<typeof PodcastSyncLogModal>>('$syncLogModal');

const feedUrl = () => getStationApiUrl(`/podcast/${props.podcastId}/feed-items`).value;
const importUrl = () => getStationApiUrl(`/podcast/${props.podcastId}/import-selected`).value;

const loadFeed = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const {data} = await axios.get<{
            success: boolean;
            message?: string | null;
            items: FeedCatalogItem[];
        }>(feedUrl());
        if (!data.success) {
            errorMessage.value = data.message ?? $gettext('Could not load feed.');
            items.value = [];
        } else {
            items.value = data.items ?? [];
        }
    } catch {
        errorMessage.value = $gettext('Failed to load feed.');
        items.value = [];
    } finally {
        loading.value = false;
    }
};

const importKeys = async (keys: string[]) => {
    if (keys.length === 0) {
        return;
    }
    importing.value = true;
    try {
        const {data} = await axios.post<{
            success: boolean;
            episodes_added: number;
            log: SyncLogEntry[];
        }>(importUrl(), {keys});
        $syncLogModal.value?.show(
            data.log ?? [],
            data.episodes_added ?? 0,
            data.success !== false
        );
        if (data.success) {
            if ((data.episodes_added ?? 0) > 0) {
                notifySuccess($gettext('Episode(s) imported.'));
            }
            emit('imported');
            await loadFeed();
            selectedKeys.value = selectedKeys.value.filter((k) => !keys.includes(k));
        }
    } catch {
        notifyError($gettext('Import failed.'));
    } finally {
        importing.value = false;
    }
};

const importSelected = () => {
    void importKeys([...selectedKeys.value]);
};

onMounted(() => {
    void loadFeed();
});

watch(
    () => props.podcastId,
    () => {
        selectedKeys.value = [];
        void loadFeed();
    }
);
</script>
