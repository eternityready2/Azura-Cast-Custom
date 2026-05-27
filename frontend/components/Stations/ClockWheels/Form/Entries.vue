<template>
    <tab :label="$gettext('Basic Info')">
        <div class="mb-3">
            <form-group-field
                id="name"
                :field="r$.name"
                :label="$gettext('Title')"
            />
        </div>

        <div class="mb-3">
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
            {{ $gettext('Air times are set on the station Schedule page. Entries play in order from top to bottom and repeat until the scheduled block ends.') }}
        </div>

        <div class="mb-1">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-semibold">
                    {{ $gettext('Entries') }} ({{ entries.length }})
                </span>
                <small class="text-muted">
                    {{ $gettext('Drag to reorder. Each item plays fully, then advances to the next.') }}
                </small>
            </div>

            <table class="table table-sm table-bordered mb-0 clock-wheel-entries-table">
                <thead>
                    <tr>
                        <th
                            class="text-uppercase small"
                            style="width: 2rem;"
                        />
                        <th
                            class="text-uppercase small text-center"
                            style="width: 2.5rem;"
                        >
                            #
                        </th>
                        <th class="text-uppercase small">
                            {{ $gettext('Type or Category') }}
                        </th>
                        <th class="text-uppercase small">
                            {{ $gettext('Algorithm') }}
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
                            colspan="5"
                            class="text-center text-muted py-3"
                        >
                            {{ $gettext('No entries yet. Add one below.') }}
                        </td>
                    </tr>
                    <tr
                        v-for="(entry, index) in entries"
                        :key="rowKey(entry, index)"
                        :data-entry-index="index"
                    >
                        <td class="text-center align-middle drag-handle text-muted">
                            ⋮⋮
                        </td>
                        <td class="text-center align-middle text-muted small">
                            {{ index + 1 }}
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
                        <td class="text-center align-middle">
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :title="$gettext('Insert entry after this row')"
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
                    </tr>
                </tbody>
            </table>

            <button
                type="button"
                class="btn btn-secondary w-100 mt-2"
                @click="props.addEntry()"
            >
                {{ $gettext('Add Entry') }}
            </button>
        </div>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from '~/components/Form/FormGroupField.vue';
import FormGroupCheckbox from '~/components/Form/FormGroupCheckbox.vue';
import Tab from '~/components/Common/Tab.vue';
import {onMounted, ref, toRef, useTemplateRef} from 'vue';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';
import {useDraggable} from 'vue-draggable-plus';

export interface ClockWheelEntryRow {
    slot_value: string;
    algorithm: string;
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

const $tbody = useTemplateRef('$tbody');

onMounted(() => {
    if ($tbody.value === null) {
        return;
    }

    useDraggable($tbody, toRef(props, 'entries'), {
        handle: '.drag-handle',
        animation: 150,
        onEnd() {
            props.onEntriesReordered();
        },
    });
});

const rowKey = (entry: ClockWheelEntryRow, index: number) =>
    `${index}-${entry.slot_value}`;
</script>

<style lang="scss" scoped>
.clock-wheel-entries-table .drag-handle {
    cursor: grab;
}

.clock-wheel-entries-table .drag-handle:active {
    cursor: grabbing;
}
</style>
