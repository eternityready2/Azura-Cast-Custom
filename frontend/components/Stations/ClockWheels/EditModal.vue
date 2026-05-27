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
        <tabs>
            <ClockWheelsFormEntries
                :form="form"
                :r$="r$"
                :entries="entries"
                :add-entry="addEntry"
                :remove-entry="removeEntry"
                :duplicate-entry="duplicateEntry"
                :insert-entry-after="insertEntryAfter"
                :on-entries-reordered="onEntriesReordered"
            />
        </tabs>

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
import Tabs from '~/components/Common/Tabs.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, reactive, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import ClockWheelsFormEntries from '~/components/Stations/ClockWheels/Form/Entries.vue';

interface ClockWheelEntry {
    slot_value: string;
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

const defaultEntry = (): ClockWheelEntry => ({
    slot_value: 'type:music',
    algorithm: 'random',
});

const addEntry = () => {
    entries.push(defaultEntry());
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const duplicateEntry = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    entries.push({...source});
};

const insertEntryAfter = (index: number) => {
    entries.splice(index + 1, 0, defaultEntry());
};

const onEntriesReordered = () => {
    // Order is persisted via array index on save (slot_order).
};

const resetForm = () => {
    form.value = {...blankForm};
    entries.splice(0, entries.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
    if (Array.isArray(data.slots)) {
        const converted = (data.slots as {
            type?: string | null;
            category_id?: number | null;
            algorithm?: string;
            slot_order?: number;
        }[])
            .slice()
            .sort((a, b) => (a.slot_order ?? 0) - (b.slot_order ?? 0))
            .map((s) => ({
                slot_value: slotToValue(s),
                algorithm: s.algorithm ?? 'random',
            }));
        entries.splice(0, entries.length, ...converted);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({
        ...valueToSlot(e.slot_value),
        algorithm: e.algorithm,
    }));
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
            emit('relist');
            close();
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
        void doDelete(editUrl.value);
    }
};

defineExpose({create, edit});
</script>
