<template>
    <modal
        id="download_from_url"
        ref="$modal"
        centered
        @hidden="reset"
    >
        <template #modal-header>
            <div class="download-header">
                <div class="download-icon"><icon-cloud-download /></div>
                <div>
                    <h1>{{ $gettext('Download from URL') }}</h1>
                    <p>{{ $gettext('Download an audio file directly into your station media library.') }}</p>
                </div>
            </div>
        </template>

        <form class="download-form" @submit.prevent="download">
            <div class="mb-3">
                <label for="download_url" class="form-label d-flex align-items-center gap-2">
                    <icon-link />
                    {{ $gettext('Audio File URL') }}
                    <span class="text-danger">*</span>
                </label>
                <input
                    id="download_url"
                    v-model="url"
                    class="form-control form-control-lg"
                    type="url"
                    placeholder="https://example.com/audio.mp3"
                    required
                >
                <div class="form-text">
                    {{ $gettext('Enter a direct link to an audio file such as MP3, FLAC, OGG or another format accepted by your station.') }}
                </div>
            </div>

            <div class="mb-1">
                <label for="download_filename" class="form-label d-flex align-items-center gap-2">
                    <icon-description />
                    {{ $gettext('Custom Filename') }}
                </label>
                <input
                    id="download_filename"
                    v-model="filename"
                    class="form-control form-control-lg"
                    type="text"
                    :placeholder="$gettext('Auto-detect from URL')"
                >
                <div class="form-text">
                    {{ $gettext('Leave blank to use the filename supplied by the remote URL.') }}
                </div>
            </div>

            <invisible-submit-button />
        </form>

        <template #modal-footer>
            <button
                class="btn btn-outline-secondary"
                type="button"
                :disabled="busy"
                @click="hide"
            >
                {{ $gettext('Cancel') }}
            </button>
            <button
                class="btn btn-primary download-button"
                type="button"
                :disabled="busy || '' === url.trim()"
                @click="download"
            >
                <icon-cloud-download class="me-1" />
                {{ busy ? $gettext('Downloading...') : $gettext('Download File') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {ref, useTemplateRef} from "vue";
import IconCloudDownload from "~icons/ic/baseline-cloud-download";
import IconDescription from "~icons/ic/baseline-description";
import IconLink from "~icons/ic/baseline-link";
import InvisibleSubmitButton from "~/components/Common/InvisibleSubmitButton.vue";
import Modal from "~/components/Common/Modal.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify";
import {useApiRouter} from "~/functions/useApiRouter";
import {useHasModal} from "~/functions/useHasModal";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    currentDirectory: string,
}>();

const emit = defineEmits<{
    (e: "relist"): void,
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {getStationApiUrl} = useApiRouter();

const url = ref("");
const filename = ref("");
const busy = ref(false);
const apiUrl = getStationApiUrl("/files/download-from-url");

const $modal = useTemplateRef("$modal");
const {show: open, hide} = useHasModal($modal);

const reset = () => {
    url.value = "";
    filename.value = "";
};

const download = async () => {
    if ("" === url.value.trim()) {
        return;
    }

    busy.value = true;
    try {
        await axios.post(apiUrl.value, {
            url: url.value.trim(),
            filename: filename.value.trim(),
            directory: props.currentDirectory,
        });
        notifySuccess($gettext("File downloaded."));
        emit("relist");
        hide();
    } finally {
        busy.value = false;
    }
};

defineExpose({open});
</script>

<style scoped>
.download-header {
    display: flex;
    align-items: center;
    gap: .9rem;
    width: 100%;
    min-width: 0;
    margin: 0;
    padding: 0;
    color: #fff;
    background: transparent;
}

.download-header h1 {
    margin: 0;
    color: #fff;
    font-size: 1.35rem;
    font-weight: 750;
}

.download-header p {
    margin: .2rem 0 0;
    color: rgba(255,255,255,.88);
    font-size: .84rem;
}

.download-icon {
    width: 2.9rem;
    height: 2.9rem;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border: 1px solid rgba(255,255,255,.24);
    border-radius: .75rem;
    background: rgba(255,255,255,.16);
}

.download-icon :deep(svg) {
    width: 1.45rem;
    height: 1.45rem;
}

.download-form {
    padding: .15rem .1rem;
    color: var(--bs-body-color);
}

.download-form .form-label {
    color: var(--bs-body-color);
    font-weight: 650;
}

.download-form .form-label :deep(svg) {
    width: 1rem;
    height: 1rem;
    color: #6f65db;
}

.download-form .form-control {
    border-color: color-mix(in srgb, var(--bs-border-color) 70%, #6d63d9 30%);
    background: color-mix(in srgb, var(--bs-body-bg) 92%, var(--bs-secondary-bg) 8%);
    color: var(--bs-body-color);
}

.download-form .form-control:focus {
    border-color: #6d63d9;
    box-shadow: 0 0 0 .18rem rgba(109,99,217,.18);
}

.download-form .form-text {
    color: color-mix(in srgb, var(--bs-body-color) 72%, transparent);
}

.download-button {
    min-width: 150px;
    background: linear-gradient(100deg, #596ee3, #8451cf);
    border-color: transparent;
}

.download-button :deep(svg) {
    width: 1rem;
    height: 1rem;
}
</style>

