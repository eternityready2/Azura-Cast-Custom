<template>
    <modal
        id="podcast_sync_log_modal"
        ref="$modal"
        size="lg"
        :title="$gettext('Podcast sync log')"
    >
        <p
            v-if="summary"
            class="text-muted small mb-2"
        >
            {{ summary }}
        </p>
        <div
            class="sync-log-viewer border rounded p-2 bg-dark text-light font-monospace small"
            style="max-height: 60vh; overflow-y: auto;"
        >
            <div
                v-for="(entry, idx) in entries"
                :key="idx"
                :class="lineClass(entry.level)"
                class="py-0 px-1"
            >
                {{ entry.message }}
            </div>
        </div>

        <template #modal-footer>
            <button
                class="btn btn-secondary"
                type="button"
                @click="hide"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                class="btn btn-primary"
                type="button"
                @click.prevent="doCopy"
            >
                {{ $gettext('Copy to Clipboard') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {ref, useTemplateRef} from "vue";
import {useClipboard} from "@vueuse/core";
import Modal from "~/components/Common/Modal.vue";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useTranslate} from "~/vendor/gettext";

export type SyncLogEntry = {
    level: string;
    message: string;
};

const entries = ref<SyncLogEntry[]>([]);
const summary = ref('');

const $modal = useTemplateRef('$modal');
const {show: showModal, hide} = useHasModal($modal);
const clipboard = useClipboard();
const {$gettext} = useTranslate();

const lineClass = (level: string): string => {
    switch (level) {
        case 'error':
            return 'text-danger';
        case 'warning':
            return 'text-warning';
        case 'debug':
            return 'text-secondary';
        default:
            return 'text-light';
    }
};

const show = (log: SyncLogEntry[], episodesAdded: number, success: boolean) => {
    entries.value = log.length > 0 ? log : [{level: 'info', message: $gettext('No log entries.')}];
    if (success) {
        summary.value = episodesAdded > 0
            ? $gettext('%{n} new episode(s) imported.', {n: String(episodesAdded)})
            : $gettext('No new episodes (all items were already imported or skipped).');
    } else {
        summary.value = $gettext('Sync finished with errors — see log below.');
    }
    showModal();
};

const doCopy = () => {
    const text = entries.value.map((e) => `[${e.level.toUpperCase()}] ${e.message}`).join('\n');
    void clipboard.copy(text);
};

defineExpose({
    show
});
</script>
