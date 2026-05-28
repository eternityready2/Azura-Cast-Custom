<template>
    <tab :label="$gettext('Basic Info')">
        <div class="mb-3">
            <form-group-field
                id="name"
                :field="r$.name"
                :label="$gettext('Title')"
            />
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">{{ $gettext('Color') }} *</label>
            <div>
                <input
                    id="color"
                    v-model="form.color"
                    type="color"
                    style="width: 3rem; height: 3rem; padding: 0.15rem; border: 2px solid #555; border-radius: 6px; cursor: pointer; background: none;"
                />
            </div>
        </div>

        <form-group-checkbox
            id="is_active"
            class="mb-3"
            :field="r$.is_active"
            :label="$gettext('Active')"
            :description="$gettext('Inactive wheels are saved but do not run on-air until scheduled on the station Schedule page.')"
        />

        <div class="alert alert-info py-2 mb-4">
            {{ $gettext('Air times are managed on the station Schedule page (calendar), not here. Create the wheel first, then use Schedule -> Create Event to assign it.') }}
        </div>

        <div class="mb-1">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-semibold">
                    {{ $gettext('Clockwheel entries') }} ({{ entries.length }})
                </span>
                <small class="text-muted">
                    {{ $gettext('Drag to reorder; times stay on the hour unless you edit them.') }}
                </small>
            </div>

            <div
                v-if="entries.length > 0"
                class="clock-wheel-timeline mb-3"
                role="img"
                :aria-label="$gettext('Hour timeline showing anchor positions')"
            >
                <div class="clock-wheel-timeline__track">
                    <span class="clock-wheel-timeline__label clock-wheel-timeline__label--start">0:00</span>
                    <span class="clock-wheel-timeline__label clock-wheel-timeline__label--end">59:59</span>
                    <button
                        v-for="(entry, index) in sortedEntries"
                        :key="'marker-' + index"
                        type="button"
                        class="clock-wheel-timeline__marker"
                        :style="{ left: timelinePercent(entry.position_seconds) + '%' }"
                        :title="formatPosition(entry.position_seconds) + ' - ' + slotLabel(entry.slot_value)"
                        @click="focusRow(entries.indexOf(entry))"
                    />
                </div>
            </div>

            <div
                v-if="timelineWarnings.length > 0"
                class="alert alert-warning py-2 small mb-2"
            >
                <ul class="mb-0 ps-3">
                    <li
                        v-for="(warn, wi) in timelineWarnings"
                        :key="wi"
                    >
                        {{ formatPosition(entries[warn.index]?.position_seconds ?? 0) }}: {{ warn.message }}
                    </li>
                </ul>
            </div>

            <table class="table table-sm table-bordered mb-0 clock-wheel-entries-table">
                <thead>
                    <tr>
                        <th
                            class="text-uppercase small"
                            style="width: 2rem;"
                        />
                        <th class="text-uppercase small">
                            {{ $gettext('Position (m:s)') }}
                        </th>
                        <th class="text-uppercase small">
                            {{ $gettext('Type or Category') }}
                        </th>
                        <th class="text-uppercase small">
                            {{ $gettext('Algorithm') }}
                        </th>
                        <th class="text-uppercase small">
                            {{ $gettext('Max sec') }}
                        </th>
                        <th
                            class="text-uppercase small text-center"
                            style="width: 9rem;"
                        >
                            {{ $gettext('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody ref="$tbody">
                    <tr v-if="entries.length === 0">
                        <td
                            colspan="6"
                            class="text-center text-muted py-3"
                        >
                            {{ $gettext('No Clockwheel Entries found.') }}
                        </td>
                    </tr>
                    <tr
                        v-for="(entry, index) in entries"
                        :key="rowKey(entry, index)"
                        :class="{ 'table-warning': rowHasWarning(index) }"
                        :data-entry-index="index"
                    >
                        <td class="text-center align-middle drag-handle text-muted">
                            ..
                        </td>
                        <td>
                            <input
                                :value="formatPosition(entry.position_seconds)"
                                type="text"
                                class="form-control form-control-sm"
                                placeholder="0:00"
                                @change="onPositionChange(entry, $event)"
                            >
                        </td>
                        <td>
                            <select
                                v-model="entry.slot_value"
                                class="form-select form-select-sm"
                            >
                                <optgroup :label="$gettext('Types')">
                                    <option value="type:music">
                                        {{ $gettext('Music (music and copyrighted material)') }}
                                    </option>
                                    <option value="type:talk">
                                        {{ $gettext('Talk (sermons, speeches, and live recordings)') }}
                                    </option>
                                    <option value="type:id">
                                        {{ $gettext('ID (station identification such as sweepers and jingles)') }}
                                    </option>
                                    <option value="type:promo">
                                        {{ $gettext('Promo (station promotion that is not considered an ID)') }}
                                    </option>
                                    <option value="type:ad">
                                        {{ $gettext('Ad (advert replacement files)') }}
                                    </option>
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
                                <option value="random">
                                    {{ $gettext('Random') }}
                                </option>
                                <option value="oldest_album">
                                    {{ $gettext('Oldest Album') }}
                                </option>
                                <option value="oldest_artist">
                                    {{ $gettext('Oldest Artist') }}
                                </option>
                                <option value="oldest_track">
                                    {{ $gettext('Oldest Track') }}
                                </option>
                                <option value="most_recent_album">
                                    {{ $gettext('Most Recent Album') }}
                                </option>
                                <option value="most_recent_artist">
                                    {{ $gettext('Most Recent Artist') }}
                                </option>
                            </select>
                        </td>
                        <td>
                            <input
                                v-model.number="entry.duration_seconds"
                                type="number"
                                min="0"
                                max="3600"
                                class="form-control form-control-sm"
                                :placeholder="$gettext('Auto')"
                            >
                        </td>
                        <td class="text-center align-middle">
                            <div class="btn-group btn-group-sm w-100 justify-content-center">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary cw-action-btn"
                                    :title="$gettext('Insert entry after this anchor')"
                                    @click="props.insertEntryAfter(index)"
                                >
                                    <icon-ic-add />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary cw-action-btn"
                                    :title="$gettext('Duplicate this entry')"
                                    @click="props.duplicateEntry(index)"
                                >
                                    <icon-ic-copy />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger cw-action-btn"
                                    :title="$gettext('Delete')"
                                    @click="props.removeEntry(index)"
                                >
                                    <icon-ic-delete />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                class="btn btn-secondary w-100 mt-2"
                @click="props.addEntry()"
            >
                {{ $gettext('Add Clockwheel Entry') }}
            </button>
        </div>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import Tab from '~/components/Common/Tab.vue';
import {computed, onMounted, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useDraggable} from 'vue-draggable-plus';
import IconIcDelete from '~icons/ic/baseline-delete';
import IconIcAdd from '~icons/ic/baseline-add';
import IconIcCopy from '~icons/ic/baseline-content-copy';
import {
    formatClockWheelPosition,
    getClockWheelTimelineWarnings,
    parseClockWheelPosition,
    slotValueShortLabel,
    timelinePercent,
} from '~/functions/clockWheelPosition.ts';

const {$gettext} = useTranslate();

export interface ClockWheelEntryRow {
    slot_value: string;
    algorithm: string;
    position_seconds: number;
    duration_seconds: number | null;
}

const props = defineProps<{
    form: {name: string; color: string; is_active: boolean};
    r$: Record<string, any>;
    addEntry: () => void;
    removeEntry: (index: number) => void;
    duplicateEntry: (index: number) => void;
    insertEntryAfter: (index: number) => void;
    onEntriesReordered: () => void;
    onEntriesChanged: () => void;
}>();

const entries = defineModel<ClockWheelEntryRow[]>('entries', {required: true});

const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();
const categories = ref<{id: number; name: string}[]>([]);

void axios.get(getStationApiUrl('/media-categories').value).then(
    (resp) => {
        categories.value = resp.data?.rows ?? resp.data ?? [];
    },
    () => {
        categories.value = [];
    }
);

const sortedEntries = computed(() =>
    [...entries.value].sort((a, b) => a.position_seconds - b.position_seconds)
);

const timelineWarnings = computed(() =>
    getClockWheelTimelineWarnings(entries.value, $gettext)
);

const $tbody = useTemplateRef('$tbody');

onMounted(() => {
    if ($tbody.value === null) {
        return;
    }

    useDraggable($tbody.value, entries, {
        handle: '.drag-handle',
        animation: 150,
        onEnd() {
            props.onEntriesReordered();
        },
    });
});

const formatPosition = formatClockWheelPosition;
const slotLabel = (slotValue: string) => slotValueShortLabel(slotValue, categories.value);

const rowKey = (entry: ClockWheelEntryRow, index: number) =>
    `${index}-${entry.position_seconds}-${entry.slot_value}`;

const rowHasWarning = (index: number) =>
    timelineWarnings.value.some((w) => w.index === index);

const onPositionChange = (entry: ClockWheelEntryRow, event: Event) => {
    const target = event.target as HTMLInputElement;
    entry.position_seconds = parseClockWheelPosition(target.value);
    target.value = formatPosition(entry.position_seconds);
    props.onEntriesChanged();
};

const focusRow = (index: number) => {
    if (index < 0) {
        return;
    }
    const row = $tbody.value?.querySelector(`tr[data-entry-index="${index}"]`);
    row?.scrollIntoView({behavior: 'smooth', block: 'nearest'});
};
</script>

<style lang="scss" scoped>
.clock-wheel-timeline__track {
    position: relative;
    height: 2rem;
    background: var(--bs-secondary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
}

.clock-wheel-timeline__label {
    position: absolute;
    top: 100%;
    font-size: 0.65rem;
    color: var(--bs-secondary-color);
    margin-top: 0.15rem;

    &--start {
        left: 0;
    }

    &--end {
        right: 0;
    }
}

.clock-wheel-timeline__marker {
    position: absolute;
    top: 50%;
    width: 0.65rem;
    height: 1.25rem;
    margin: 0;
    padding: 0;
    border: none;
    border-radius: 2px;
    background: var(--bs-primary);
    transform: translate(-50%, -50%);
    cursor: pointer;
    opacity: 0.85;

    &:hover,
    &:focus {
        opacity: 1;
        outline: 2px solid var(--bs-primary);
        outline-offset: 2px;
    }
}

.clock-wheel-entries-table.sortable,
.clock-wheel-entries-table .drag-handle {
    cursor: grab;
}

.clock-wheel-entries-table .drag-handle:active {
    cursor: grabbing;
}

.cw-action-btn {
    width: 2.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
}
</style>
