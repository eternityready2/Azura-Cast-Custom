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
            <form-basic-info/>
            <form-schedule v-model:schedule-items="form.schedule_items" />
            <form-advanced/>
        </tabs>
    </modal-form>
</template>

<script setup lang="ts">
import FormBasicInfo from "~/components/Stations/Playlists/Form/BasicInfo.vue";
import FormSchedule from "~/components/Stations/Playlists/Form/Schedule.vue";
import FormAdvanced from "~/components/Stations/Playlists/Form/Advanced.vue";
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from "~/functions/useBaseEditModal";
import {computed, toRef, useTemplateRef} from "vue";
import {useTranslate} from "~/vendor/gettext";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import ModalForm from "~/components/Common/ModalForm.vue";
import Tabs from "~/components/Common/Tabs.vue";
import {storeToRefs} from "pinia";
import {useAppCollectScope} from "~/vendor/regle.ts";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import mergeExisting from "~/functions/mergeExisting.ts";

const props = defineProps<BaseEditModalProps>();

const emit = defineEmits<BaseEditModalEmits & {
    (e: 'needs-restart'): void
}>();

const $modal = useTemplateRef('$modal');

const {notifySuccess} = useNotify();

const formStore = useStationsPlaylistsForm();
const {form, r$} = storeToRefs(formStore);
const {$reset: resetForm} = formStore;

const {r$: validatedr$} = useAppCollectScope('stations-playlists');

const {
    loading,
    error,
    isEditMode,
    clearContents,
    create,
    edit,
    doSubmit,
    close
} = useBaseEditModal(
    toRef(props, 'createUrl'),
    emit,
    $modal,
    resetForm,
    (data) => {
        if (data.schedule_items?.length) {
            data.schedule_items = data.schedule_items.map((item: Record<string, unknown>) => {
                const endType = item.recurrence_end_type ?? 'never';
                const merged: Record<string, unknown> = {
                    ...item,
                    recurrence_type: item.recurrence_type ?? 'weekly',
                    recurrence_interval: item.recurrence_interval ?? 1,
                    recurrence_end_type: (endType === 'on_date' ? 'never' : endType) as string,
                    recurrence_end_after: endType === 'after' ? (item.recurrence_end_after ?? null) : null,
                    recurrence_end_date: null
                };
                if (merged.recurrence_type === 'monthly' && merged.recurrence_monthly_pattern === 'day_of_week' && merged.recurrence_monthly_day_of_week != null && (!merged.days || (merged.days as number[]).length === 0)) {
                    merged.days = [Number(merged.recurrence_monthly_day_of_week)];
                }
                return merged;
            });
        }
        r$.value.$reset({
            toState: mergeExisting(r$.value.$value, data)
        })
    },
    async () => {
        const {valid} = await validatedr$.$validate();
        const data = { ...form.value };
        if (data.schedule_items?.length) {
            data.schedule_items = data.schedule_items.map((item: Record<string, unknown>) => {
                const out = { ...item };
                out.recurrence_type = item.recurrence_type ?? 'weekly';
                out.recurrence_interval = (item.recurrence_type === 'biweekly' ? 2 : Number(item.recurrence_interval)) || 1;
                out.recurrence_end_type = item.recurrence_end_type ?? 'never';
                out.recurrence_end_after = (item.recurrence_end_type === 'after' && item.recurrence_end_after != null)
                    ? Number(item.recurrence_end_after) : null;
                out.recurrence_end_date = null;
                if (out.recurrence_type === 'monthly' && out.recurrence_monthly_pattern === 'day_of_week' && item.days && Array.isArray(item.days) && item.days.length > 0) {
                    out.recurrence_monthly_day_of_week = Number(item.days[0]);
                }
                return out;
            });
        }
        return { valid, data };
    },
    {
        onSubmitSuccess: () => {
            notifySuccess();
            emit('relist');
            emit('needs-restart');
            close();
        },
    }
);

const {$gettext} = useTranslate();

const langTitle = computed(() => {
    return isEditMode.value
        ? $gettext('Edit Playlist')
        : $gettext('Add Playlist');
});

defineExpose({
    create,
    edit,
    close
});
</script>
