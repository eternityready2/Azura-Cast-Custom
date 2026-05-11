<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <!-- Title -->
        <div class="mb-3">
            <form-group-field
                id="name"
                :field="r$.name"
                :label="$gettext('Title')"
            />
        </div>

        <!-- Color swatch -->
        <div class="mb-4">
            <label class="form-label fw-semibold">{{ $gettext('Color') }} *</label>
            <div>
                <input
                    id="color"
                    v-model="form.color"
                    type="color"
                    class="color-swatch-input"
                    style="width: 3rem; height: 3rem; padding: 0.15rem; border: 2px solid #555; border-radius: 6px; cursor: pointer; background: none;"
                />
            </div>
        </div>

        <!-- Entries section -->
        <div class="mb-1">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-semibold">
                    {{ $gettext('Clockwheel entries') }} ({{ entries.length }})
                </span>
            </div>

            <table class="table table-sm table-bordered mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase small">{{ $gettext('Type or Category') }}</th>
                        <th class="text-uppercase small">{{ $gettext('Algorithm') }}</th>
                        <th
                            class="text-uppercase small text-center"
                            style="width: 60px;"
                        >
                            {{ $gettext('Del') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0">
                        <td
                            colspan="3"
                            class="text-center text-muted py-3"
                        >
                            {{ $gettext('No Clockwheel Entries found.') }}
                        </td>
                    </tr>
                    <tr
                        v-for="(entry, index) in entries"
                        :key="index"
                    >
                        <td>
                            <select
                                v-model="entry.slot_value"
                                class="form-select form-select-sm"
                            >
                                <optgroup :label="$gettext('Types')">
                                    <option value="type:music">{{ $gettext('Music (music and copyrighted material)') }}</option>
                                    <option value="type:talk">{{ $gettext('Talk (sermons, speeches, and live recordings)') }}</option>
                                    <option value="type:id">{{ $gettext('ID (station identification such as sweepers and jingles)') }}</option>
                                    <option value="type:promo">{{ $gettext('Promo (station promotion that is not considered an ID)') }}</option>
                                    <option value="type:ad">{{ $gettext('Ad (advert replacement files)') }}</option>
                                </optgroup>
                                <optgroup
                                    v-if="categories.length > 0"
                                    :label="$gettext('Categories')"
                                >
                                    <option
                                        v-for="cat in categories"
                                        :key="cat.id"
                                        :value="'cat:' + cat.id"
                                    >
                                        {{ cat.name }}
                                    </option>
                                </optgroup>
                            </select>
                        </td>
                        <td>
                            <select
                                v-model="entry.algorithm"
                                class="form-select form-select-sm"
                            >
                                <option value="random">{{ $gettext('Random') }}</option>
                                <option value="oldest_album">{{ $gettext('Oldest Album') }}</option>
                                <option value="oldest_artist">{{ $gettext('Oldest Artist') }}</option>
                                <option value="oldest_track">{{ $gettext('Oldest Track') }}</option>
                                <option value="most_recent_album">{{ $gettext('Most Recent Album') }}</option>
                                <option value="most_recent_artist">{{ $gettext('Most Recent Artist') }}</option>
                            </select>
                        </td>
                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                @click="removeEntry(index)"
                            >
                                &times;
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                class="btn btn-secondary w-100 mt-2"
                @click="addEntry"
            >
                {{ $gettext('Add Clockwheel Entry') }}
            </button>
        </div>

        <template
            v-if="isEditMode"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                @click="doDeleteFromModal"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="r$.$invalid"
                @click="doSubmit"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, onMounted, reactive, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';

interface ClockWheelEntry {
    slot_value: string;  // "type:music" | "type:talk" | ... | "cat:5"
    algorithm: string;
}

/** Convert a raw slot from the API (type + category_id) to a combined slot_value. */
function slotToValue(slot: {type?: string | null; category_id?: number | null}): string {
    if (slot.category_id != null) {
        return 'cat:' + slot.category_id;
    }
    return 'type:' + (slot.type ?? 'music');
}

/** Convert a slot_value back to { type, category_id } for the API payload. */
function valueToSlot(slot_value: string): {type: string | null; category_id: number | null} {
    if (slot_value.startsWith('cat:')) {
        return {type: null, category_id: parseInt(slot_value.slice(4), 10)};
    }
    return {type: slot_value.replace('type:', ''), category_id: null};
}

const props = defineProps<BaseEditModalProps>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();

const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();
const categories = ref<{id: number; name: string}[]>([]);

onMounted(async () => {
    try {
        const resp = await axios.get(getStationApiUrl('/media-categories').value);
        categories.value = resp.data?.rows ?? resp.data ?? [];
    } catch {
        categories.value = [];
    }
});

const blankForm = {
    name: '',
    color: '#e87722',
    is_active: true,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    is_active: {},
});

const addEntry = () => {
    entries.push({slot_value: 'type:music', algorithm: 'random'});
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const resetForm = () => {
    form.value = {...blankForm};
    entries.splice(0, entries.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
    if (Array.isArray(data.slots)) {
        const converted = (data.slots as {type?: string | null; category_id?: number | null; algorithm?: string}[]).map(
            (s) => ({slot_value: slotToValue(s), algorithm: s.algorithm ?? 'random'})
        );
        entries.splice(0, entries.length, ...converted);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({...valueToSlot(e.slot_value), algorithm: e.algorithm}));
    return {valid, data: {...form.value, slots}};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Clock Wheel') : $gettext('Add Clock Wheel')
);

const {
    loading,
    error,
    isEditMode,
    editUrl,
    clearContents,
    create,
    edit,
    close,
    doSubmit,
} = useBaseEditModal(
    computed(() => props.createUrl),
    emit,
    $modal,
    resetForm,
    populateForm,
    validateForm,
    {
        onSubmitSuccess: () => {
            notifySuccess($gettext('Clock Wheel saved.'));
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Clock Wheel?'),
    () => {
        emit('relist');
    }
);

const doDeleteFromModal = () => {
    if (editUrl.value) {
        $modal.value?.hide();
        doDelete(editUrl.value);
    }
};

defineExpose({create, edit});
</script>
