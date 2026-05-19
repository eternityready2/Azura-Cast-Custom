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
            />
            <FormSchedule v-model:schedule-items="scheduleItems" />
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
import FormSchedule from '~/components/Stations/ClockWheels/Form/Schedule.vue';
import type {ClockWheelScheduleRow} from '~/components/Stations/ClockWheels/Form/ScheduleRow.vue';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';

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

const blankForm = {
    name: '',
    color: '#e87722',
    is_active: true,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);
const scheduleItems = ref<ClockWheelScheduleRow[]>([]);

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
    scheduleItems.value.splice(0, scheduleItems.value.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
    if (Array.isArray(data.slots)) {
        const converted = (data.slots as {type?: string | null; category_id?: number | null; algorithm?: string}[]).map(
            (s) => ({slot_value: slotToValue(s), algorithm: s.algorithm ?? 'random'})
        );
        entries.splice(0, entries.length, ...converted);
    }
    if (Array.isArray(data.schedule_items)) {
        scheduleItems.value.splice(0, scheduleItems.value.length, ...(data.schedule_items as ClockWheelScheduleRow[]).map((item: Record<string, unknown>) => {
            const endType = item.recurrence_end_type ?? 'never';
            const merged: Record<string, unknown> = {
                ...item,
                recurrence_type: item.recurrence_type ?? 'weekly',
                recurrence_interval: item.recurrence_interval ?? 1,
                recurrence_end_type: (endType === 'on_date' ? 'never' : endType) as string,
                recurrence_end_after: endType === 'after' ? (item.recurrence_end_after ?? null) : null,
                recurrence_end_date: null
            };
            if (endType === 'after') {
                merged.end_date = null;
            }
            if (merged.recurrence_type === 'monthly' && merged.recurrence_monthly_pattern === 'day_of_week' && merged.recurrence_monthly_day_of_week != null && (!merged.days || (merged.days as number[]).length === 0)) {
                merged.days = [Number(merged.recurrence_monthly_day_of_week)];
            }
            return merged;
        }));
    }
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    const slots = entries.map((e) => ({...valueToSlot(e.slot_value), algorithm: e.algorithm}));
    const schedule_items = scheduleItems.value.map((item) => {
        const out = {...item};
        out.recurrence_type = item.recurrence_type ?? 'weekly';
        out.recurrence_interval = (item.recurrence_type === 'biweekly' ? 2 : Number(item.recurrence_interval)) || 1;
        out.recurrence_end_type = item.recurrence_end_type ?? 'never';
        out.recurrence_end_after = (item.recurrence_end_type === 'after' && item.recurrence_end_after != null)
            ? Number(item.recurrence_end_after) : null;
        out.recurrence_end_date = null;
        if (item.recurrence_end_type === 'after') {
            out.end_date = null;
        }
        const normalizedDays = normalizeStationScheduleDays(item.days);
        if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'date') {
            out.days = [];
        } else {
            out.days = normalizedDays;
        }
        if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'day_of_week' && normalizedDays.length > 0) {
            out.recurrence_monthly_day_of_week = normalizedDays[0];
        }
        return out;
    });
    return {valid, data: {...form.value, slots, schedule_items}};
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