<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="!isValid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <div class="row g-3">

            <!-- Name -->
            <div class="col-12">
                <label class="form-label" for="webstream_name">
                    {{ $gettext('Stream Name') }} <span class="text-danger">*</span>
                </label>
                <input
                    id="webstream_name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :class="{'is-invalid': touched.name && !form.name}"
                    @blur="touched.name = true"
                />
                <div v-if="touched.name && !form.name" class="invalid-feedback">
                    {{ $gettext('Name is required.') }}
                </div>
            </div>

            <!-- URL -->
            <div class="col-md-7">
                <label class="form-label" for="webstream_remote_url">
                    {{ $gettext('Stream URL') }} <span class="text-danger">*</span>
                </label>
                <input
                    id="webstream_remote_url"
                    v-model="form.remote_url"
                    type="url"
                    class="form-control"
                    :class="{'is-invalid': touched.remote_url && !form.remote_url}"
                    placeholder="https://stream.example.com/live"
                    @blur="touched.remote_url = true"
                />
                <div v-if="touched.remote_url && !form.remote_url" class="invalid-feedback">
                    {{ $gettext('Stream URL is required.') }}
                </div>
                <div class="form-text">
                    {{ $gettext('Full URL of the stream, playlist file, or audio file.') }}
                </div>
            </div>

            <!-- URL Type -->
            <div class="col-md-5">
                <label class="form-label">{{ $gettext('URL Type') }}</label>
                <div v-for="opt in remoteTypeOptions" :key="opt.value" class="form-check">
                    <input
                        :id="'webstream_type_' + opt.value"
                        v-model="form.remote_type"
                        class="form-check-input"
                        type="radio"
                        :value="opt.value"
                    />
                    <label :for="'webstream_type_' + opt.value" class="form-check-label">
                        {{ opt.text }}
                    </label>
                </div>
            </div>

            <!-- Buffer -->
            <div class="col-md-4">
                <label class="form-label" for="webstream_buffer">
                    {{ $gettext('Playback Buffer (Seconds)') }}
                </label>
                <input
                    id="webstream_buffer"
                    v-model.number="form.remote_buffer"
                    type="number"
                    class="form-control"
                    min="0"
                    max="120"
                />
                <div class="form-text">
                    {{ $gettext('Higher = more stable on slow connections. 0 is usually fine for reliable streams.') }}
                </div>
            </div>

            <!-- Enabled -->
            <div class="col-md-8 d-flex align-items-end pb-1">
                <div class="form-check">
                    <input
                        id="webstream_enabled"
                        v-model="form.is_enabled"
                        class="form-check-input"
                        type="checkbox"
                    />
                    <label for="webstream_enabled" class="form-check-label fw-semibold">
                        {{ $gettext('Enabled') }}
                    </label>
                </div>
            </div>

            <!-- Divider -->
            <div class="col-12"><hr class="my-1" /></div>

            <!-- AutoDJ Behaviour -->
            <div class="col-12">
                <label class="form-label fw-semibold">{{ $gettext('AutoDJ Behaviour') }}</label>
                <div class="text-muted small mb-2">
                    {{ $gettext('Control how AutoDJ handles this stream at its scheduled time.') }}
                </div>
                <div class="form-check mb-1">
                    <input
                        id="webstream_opt_interrupt"
                        v-model="form.backend_options"
                        class="form-check-input"
                        type="checkbox"
                        value="interrupt"
                    />
                    <label for="webstream_opt_interrupt" class="form-check-label">
                        {{ $gettext('Interrupt other songs to play at scheduled time') }}
                    </label>
                </div>
                <div class="form-check">
                    <input
                        id="webstream_opt_prioritize"
                        v-model="form.backend_options"
                        class="form-check-input"
                        type="checkbox"
                        value="prioritize"
                    />
                    <label for="webstream_opt_prioritize" class="form-check-label">
                        {{ $gettext('Prioritize over listener requests') }}
                    </label>
                </div>
            </div>

        </div>
    </modal-form>
</template>

<script setup lang="ts">
import {computed, reactive, ref, useTemplateRef} from "vue";
import ModalForm from "~/components/Common/ModalForm.vue";
import {useTranslate} from "~/vendor/gettext";
import {useAxios} from "~/vendor/axios";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";

const props = defineProps<{createUrl: string}>();

const emit = defineEmits<{
    relist: [],
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess} = useNotify();

const defaultForm = () => ({
    name: '',
    remote_url: '',
    remote_type: 'stream' as 'stream' | 'playlist' | 'other',
    remote_buffer: 0,
    is_enabled: true,
    backend_options: [] as string[],
});

const form = ref(defaultForm());
const touched = reactive({name: false, remote_url: false});
const loading = ref(false);
const error = ref<string | null>(null);
const editUrl = ref<string | null>(null);

const isEditMode = computed(() => editUrl.value !== null);
const isValid = computed(() => !!form.value.name && !!form.value.remote_url);

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Web Stream') : $gettext('Add Web Stream')
);

const remoteTypeOptions = [
    {value: 'stream',   text: $gettext('Icecast/Shoutcast Stream URL')},
    {value: 'playlist', text: $gettext('Playlist (M3U/PLS) URL')},
    {value: 'other',    text: $gettext('Other URL (File, HLS, etc.)')},
];

const $modal = useTemplateRef('$modal');

const clearContents = () => {
    form.value = defaultForm();
    touched.name = false;
    touched.remote_url = false;
    editUrl.value = null;
    error.value = null;
};

const create = () => {
    clearContents();
    ($modal.value as any)?.show();
};

const edit = async (selfUrl: string) => {
    clearContents();
    editUrl.value = selfUrl;
    loading.value = true;
    ($modal.value as any)?.show();

    try {
        const {data} = await axios.get(selfUrl);
        form.value = {
            name: data.name ?? '',
            remote_url: data.remote_url ?? '',
            remote_type: data.remote_type ?? 'stream',
            remote_buffer: data.remote_buffer ?? 0,
            is_enabled: data.is_enabled ?? true,
            backend_options: Array.isArray(data.backend_options) ? data.backend_options : [],
        };
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? $gettext('Could not load stream data.');
    } finally {
        loading.value = false;
    }
};

const doSubmit = async () => {
    touched.name = true;
    touched.remote_url = true;
    if (!isValid.value) return;

    loading.value = true;
    error.value = null;

    try {
        const payload = {
            ...form.value,
            source: 'remote_url',
        };

        if (isEditMode.value) {
            await axios.put(editUrl.value!, payload);
        } else {
            await axios.post(props.createUrl, payload);
        }

        notifySuccess();
        ($modal.value as any)?.hide();
        emit('relist');
    } catch (e: any) {
        error.value = e?.response?.data?.message ?? $gettext('An error occurred saving the stream.');
    } finally {
        loading.value = false;
    }
};

defineExpose({create, edit});
</script>
