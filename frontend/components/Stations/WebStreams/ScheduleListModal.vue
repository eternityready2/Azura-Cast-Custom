<template>
    <modal
        ref="$modal"
        size="lg"
        :title="$gettext('Manage Schedule')"
        :busy="loading"
    >
        <p
            v-if="streamName"
            class="text-muted mb-3"
        >
            {{ $gettext('Existing scheduled times for "%{name}".', {name: streamName}) }}
        </p>

        <div
            v-if="error"
            class="alert alert-danger"
            role="alert"
        >
            {{ error }}
        </div>

        <p
            v-else-if="!loading && items.length === 0"
            class="text-muted"
        >
            {{ $gettext('This stream has no scheduled times yet. It will not play automatically until you add one below.') }}
        </p>

        <ul
            v-else-if="!loading"
            class="list-group mb-3"
        >
            <li
                v-for="item in items"
                :key="item.id"
                class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2"
            >
                <div>
                    <div class="fw-semibold">
                        {{ formatTimeRange(item) }}
                    </div>
                    <div class="text-muted small">
                        {{ formatRecurrence(item) }}
                    </div>
                </div>
                <div class="btn-group btn-group-sm">
                    <button
                        type="button"
                        class="btn btn-outline-primary"
                        @click="doEdit(item)"
                    >
                        {{ $gettext('Edit') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-danger"
                        @click="doDeleteItem(item)"
                    >
                        {{ $gettext('Delete') }}
                    </button>
                </div>
            </li>
        </ul>

        <template #modal-footer>
            <button
                type="button"
                class="btn btn-secondary"
                data-bs-dismiss="modal"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="loading"
                @click="doAddNew"
            >
                {{ $gettext('Add New Scheduled Time') }}
            </button>
        </template>
    </modal>
</template>

<script setup lang="ts">
import {ref} from "vue";
import Modal from "~/components/Common/Modal.vue";
import {useTranslate} from "~/vendor/gettext";
import {useApiRouter} from "~/functions/useApiRouter.ts";
import {useAxios} from "~/vendor/axios.ts";
import {getErrorAsString} from "~/vendor/axios";
import {useDialog} from "~/components/Common/Dialogs/useDialog.ts";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";

type ScheduleItem = {
    id: number,
    start_time: number,
    end_time: number,
    start_date: string | null,
    end_date: string | null,
    days: number[],
    recurrence_type: string | null,
    recurrence_interval: number | null,
};

const {$gettext} = useTranslate();
const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();
const {confirmDelete} = useDialog();
const {notifySuccess, notifyError} = useNotify();

const emit = defineEmits<{
    edit: [entityId: number, scheduleId: number],
    addNew: [entityId: number],
    relist: [],
}>();

const $modal = ref<InstanceType<typeof Modal> | null>(null);

const loading = ref(false);
const error = ref<string | null>(null);
const items = ref<ScheduleItem[]>([]);
const streamName = ref<string>('');
const currentEntityId = ref<number | null>(null);

const dayNames: Record<number, string> = {
    1: $gettext('Mon'),
    2: $gettext('Tue'),
    3: $gettext('Wed'),
    4: $gettext('Thu'),
    5: $gettext('Fri'),
    6: $gettext('Sat'),
    7: $gettext('Sun'),
};

const formatTimeOfDay = (hhmm: number): string => {
    const h = Math.floor(hhmm / 100);
    const m = hhmm % 100;
    const period = h >= 12 ? 'PM' : 'AM';
    const displayHour = h % 12 === 0 ? 12 : h % 12;
    return `${displayHour}:${String(m).padStart(2, '0')} ${period}`;
};

const formatTimeRange = (item: ScheduleItem): string =>
    `${formatTimeOfDay(item.start_time)} – ${formatTimeOfDay(item.end_time)}`;

const formatRecurrence = (item: ScheduleItem): string => {
    if (!item.recurrence_type) {
        const date = item.start_date ?? '';
        return date
            ? $gettext('One-time — %{date}', {date})
            : $gettext('One-time event');
    }

    const days = (item.days ?? [])
        .slice()
        .sort((a, b) => a - b)
        .map((d) => dayNames[d] ?? '')
        .filter(Boolean)
        .join(', ');

    const recurrenceLabel = (): string => {
        switch (item.recurrence_type) {
            case 'weekly':   return $gettext('Weekly');
            case 'biweekly': return $gettext('Bi-weekly');
            case 'monthly':  return $gettext('Monthly');
            case 'custom':   return $gettext('Every %{n} weeks', {n: String(item.recurrence_interval ?? 1)});
            default:         return item.recurrence_type ?? '';
        }
    };

    return days
        ? `${recurrenceLabel()} • ${days}`
        : recurrenceLabel();
};

const loadItems = async (entityId: number): Promise<void> => {
    loading.value = true;
    error.value = null;

    try {
        const entityApiUrl = getStationApiUrl(`/playlist/${entityId}`).value;
        const {data} = await axios.get(entityApiUrl);
        streamName.value = data.name ?? '';
        items.value = (data.schedule_items ?? []) as ScheduleItem[];
    } catch (e: unknown) {
        error.value = getErrorAsString(e);
        items.value = [];
    } finally {
        loading.value = false;
    }
};

/** Opens the list for the given stream/playlist, fetching its current schedule. */
const open = async (entityId: number, name?: string): Promise<void> => {
    currentEntityId.value = entityId;
    streamName.value = name ?? '';
    ($modal.value as any)?.show();
    await loadItems(entityId);
};

const doEdit = (item: ScheduleItem): void => {
    if (null === currentEntityId.value) {
        return;
    }
    ($modal.value as any)?.hide();
    emit('edit', currentEntityId.value, item.id);
};

const doAddNew = (): void => {
    if (null === currentEntityId.value) {
        return;
    }
    ($modal.value as any)?.hide();
    emit('addNew', currentEntityId.value);
};

const doDeleteItem = async (item: ScheduleItem): Promise<void> => {
    if (null === currentEntityId.value) {
        return;
    }

    const {value} = await confirmDelete({
        title: $gettext('Delete this scheduled event?'),
    });

    if (!value) {
        return;
    }

    try {
        const entityApiUrl = getStationApiUrl(`/playlist/${currentEntityId.value}`).value;
        const {data} = await axios.get(entityApiUrl);
        const existingItems = (data.schedule_items ?? []) as ScheduleItem[];
        const updatedItems = existingItems.filter((row) => row.id !== item.id);

        await axios.put(entityApiUrl, {schedule_items: updatedItems});

        notifySuccess($gettext('Event deleted.'));
        emit('relist');
        await loadItems(currentEntityId.value);
    } catch (e: unknown) {
        notifyError(`${$gettext('Failed to delete event.')}: ${getErrorAsString(e)}`);
    }
};

defineExpose({open});
</script>
