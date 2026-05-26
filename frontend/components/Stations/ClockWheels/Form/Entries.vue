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
            <label
                class="form-label fw-semibold"
                for="color"
            >{{ $gettext('Color') }} *</label>
            <div>
                <input
                    id="color"
                    v-model="form.color"
                    type="color"
                    class="form-control form-control-color"
                    style="width: 3rem; height: 3rem; padding: 0.15rem; border: 2px solid #555; border-radius: 6px; cursor: pointer; background: none;"
                >
            </div>
        </div>

        <!-- Entries section -->
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
                        :title="formatPosition(entry.position_seconds) + ' — ' + slotLabel(entry.slot_value)"
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
                            style="width: 7rem;"
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
                            ⋮⋮
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
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Insert entry after this anchor')"
                                    @click="props.insertEntryAfter(index)"
                                >
                                    <icon-ic-add />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Duplicate this entry')"
                                    @click="props.duplicateEntry(index)"
                                >
                                    <icon-ic-copy />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger"
                                    :title="$gettext('Delete')"
                                    @click="props.removeEntry(index)"
                                >
                                    &times;
                                </button>
                            </div>
                        </td>
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
import Tab from '~/components/Common/Tab.vue';
import IconIcAdd from '~icons/ic/baseline-add';
import IconIcCopy from '~icons/ic/baseline-content-copy';
import {computed, onMounted, ref, toRef, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useDraggable} from 'vue-draggable-plus';
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
    r$: {name: {required: unknown}; color: object; is_active: object};
    entries: ClockWheelEntryRow[];
    addEntry: () => void;
    removeEntry: (index: number) => void;
    duplicateEntry: (index: number) => void;
    insertEntryAfter: (index: number) => void;
    onEntriesReordered: () => void;
    onEntriesChanged: () => void;
}>();

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

const sortedEntries
