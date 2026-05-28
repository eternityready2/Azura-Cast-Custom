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
                v-model:entries="entries"
                :add-entry="addEntry"
                :remove-entry="removeEntry"
                :duplicate-entry="duplicateEntry"
                :insert-entry-after="insertEntryAfter"
                :on-entries-reordered="onEntriesReordered"
                :on-entries-changed="onEntriesChanged"
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
import {
    applyDragOrderToPositions,
    sortClockWheelEntries,
} from '~/functions/clockWheelPosition.ts';

interface ClockWheelEntry {
    slot_value: string;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
}

function slotToValue(slot: {type?: string | null; category_id?: number | null}): string {
    if (slot.category_id != null) {
        return 'cat:' + slot.category_id;
    }
    return 'type:' + (slot.type ?? 'music');
}

function valueToSlot(slotValue: string): {type: string | null; category_id: number | null} {
    if (slotValue.startsWith('cat:')) {
        return {type: null, category_id: parseInt(slotValue.slice(4), 10)};
    }
    return {type: slotValue.replace('type:', ''), category_id: null};
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

const defaultEntry = (positionSeconds: number): ClockWheelEntry => ({
    slot_value: 'type:music',
    algorithm: 'random',
    position_seconds: Math.min(3599, Math.max(0, positionSeconds)),
    duration_seconds: null,
});

const addEntry = () => {
    sortClockWheelEntries(entries);
    const lastPosition = entries.length > 0
        ? entries[entries.length - 1].position_seconds + 300
        : 0;
    entries.push(defaultEntry(lastPosition));
    sortClockWheelEntries(entries);
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const duplicateEntry = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    sortClockWheelEntries(entries);
    const next = entries[index + 1];
    let position = source.position_seconds + 60;
    if (next && position >= next.position_seconds) {
        position = Math.floor((source.position_seconds + next.position_seconds) / 2);
    }
    if (!next) {
        position = Math.min(3599, source.position_seconds + 300);
    }

    entries.push({
        ...source,
        position_seconds: position,
    });
    sortClockWheelEntries(entries);
};

const insertEntryAfter = (index: number) => {
    const source = entries[index];
    if (!source) {
        return;
    }

    sortClockWheelEntries(entries);
    const next = entries[index + 1];
    let position = source.position_seconds + 300;
    if (next) {
        position = Math.min(position, next.position_seconds - 1);
        if (position <= source.position_seconds) {
            position = Math.floor((source.position_seconds + next.position_seconds) / 2);
        }
    } else {
        position = Math.min(3599, position);
    }

    entries.splice(index + 1, 0, defaultEntry(position));
    sortClockWheelEntries(entries);
};

const onEntriesReordered = () => {
    applyDragOrderToPositions(entries);
    sortClockWheelEntries(entries);
};

const onEntriesChanged = () => {
    sortClockWheelEntries(entries);
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
            position_seconds?: number;
            duration_seconds?: number | null;
        }[]).map(
            (s) => ({
                slot_value: slotToValue(s),
                algorithm: s.algorithm ?? 'random',
                position_seconds: s.position_seconds ?? 0,
                duration_seconds: s.duration_seconds ?? null,
            })
        );
        entries.splice(0, entries.length, ...converted);
        sortClockWheelEntries(entries);
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({
        ...valueToSlot(e.slot_value),
        algorithm: e.algorithm,
        position_seconds: e.position_seconds,
        duration_seconds: e.duration_seconds,
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
