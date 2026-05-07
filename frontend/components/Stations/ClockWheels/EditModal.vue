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
            <tab :label="$gettext('Clock Wheel')">
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
                        <th class="text-uppercase small">{{ $gettext('Playlist') }}</th>
                        <th class="text-uppercase small">{{ $gettext('Selection Algorithm') }}</th>
                        <th class="text-uppercase small text-center" style="width: 80px;">
                            {{ $gettext('Delete') }}
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
                                v-model="entry.playlist_id"
                                class="form-select form-select-sm"
                            >
                                <option
                                    v-if="playlists.length === 0"
                                    disabled
                                    value=""
                                >
                                    {{ $gettext('No playlists found') }}
                                </option>
                                <option
                                    v-for="pl in playlists"
                                    :key="pl.id"
                                    :value="pl.id"
                                >
                                    {{ pl.name }}
                                </option>
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
            </tab>
            <form-schedule v-model:schedule-items="form.schedule_items" />
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
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormSchedule from '~/components/Stations/Playlists/Form/Schedule.vue';
import Tabs from '~/components/Common/Tabs.vue';
import Tab from '~/components/Common/Tab.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, reactive, ref, useTemplateRef, onMounted} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle, useAppCollectScope} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import normalizeStationScheduleDays from '~/functions/normalizeStationScheduleDays';

interface ClockWheelEntry {
    playlist_id: number | null;
    algorithm: string;
}

interface Playlist {
    id: number;
    name: string;
}

const props = defineProps<BaseEditModalProps>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();

const playlists = ref<Playlist[]>([]);

onMounted(async () => {
    const url = getStationApiUrl('/playlists');
    const resp = await axios.get(url.value);
    playlists.value = (resp.data as Playlist[]).map((p) => ({id: p.id, name: p.name}));
});

const {r$: scheduledr$} = useAppCollectScope('stations-clock-wheels');

const blankForm = {
    name: '',
    color: '#e87722',
    is_active: true,
    schedule_items: [] as Array<Record<string, unknown>>,
};

const form = ref({...blankForm});
const entries = reactive<ClockWheelEntry[]>([]);

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
    is_active: {},
});

const addEntry = () => {
    entries.push({playlist_id: playlists.value[0]?.id ?? null, algorithm: 'random'});
};

const removeEntry = (index: number) => {
    entries.splice(index, 1);
};

const resetForm = () => {
    form.value = {...blankForm, schedule_items: []};
    entries.splice(0, entries.length);
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
    if (Array.isArray(data.slots)) {
        entries.splice(0, entries.length, ...(data.slots as ClockWheelEntry[]));
    }
    if (Array.isArray(data.schedule_items)) {
        form.value.schedule_items = (data.schedule_items as Array<Record<string, unknown>>).map((item) => ({
            ...item,
            recurrence_type: item.recurrence_type ?? 'weekly',
            recurrence_interval: item.recurrence_interval ?? 1,
            recurrence_end_type: item.recurrence_end_type ?? 'never',
            recurrence_end_after: item.recurrence_end_after ?? null,
            recurrence_end_date: null,
        }));
    }
};

const validateForm = async () => {
    const [{valid: mainValid}, {valid: schedValid}] = await Promise.all([
        r$.$validate(),
        scheduledr$.$validate(),
    ]);
    const valid = mainValid && schedValid;
    const scheduleItems = form.value.schedule_items.map((item) => ({
        ...item,
        recurrence_type: item.recurrence_type ?? 'weekly',
        recurrence_interval: (item.recurrence_type === 'biweekly' ? 2 : Number(item.recurrence_interval)) || 1,
        recurrence_end_type: item.recurrence_end_type ?? 'never',
        recurrence_end_after: (item.recurrence_end_type === 'after' && item.recurrence_end_after != null)
            ? Number(item.recurrence_end_after) : null,
        recurrence_end_date: null,
        days: normalizeStationScheduleDays(item.days),
    }));
    return {valid, data: {...form.value, slots: [...entries], schedule_items: scheduleItems}};
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
