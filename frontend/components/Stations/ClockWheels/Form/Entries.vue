<template>
    <tab :label="$gettext('Basic Info')">
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
            <div
                class="color-swatch-input"
                :style="{ backgroundColor: color.value }"
                style="width: 3rem; height: 3rem; border: 2px solid #555; border-radius: 6px;"
            />
            <input
                id="color"
                type="color"
                class="form-control form-control-color d-none"
                style="width: 3rem; height: 3rem; padding: 0.15rem;"
                @input="color.value = ($event.target as HTMLInputElement).value"
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
                                @click="props.removeEntry(index)"
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
import {computed, ref} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';

const {$gettext} = useTranslate();

const props = defineProps<{
    form: {name: string; color: string; is_active: boolean};
    r$: {name: {required: unknown}; color: object; is_active: object};
    entries: {slot_value: string; algorithm: string}[];
    addEntry: () => void;
    removeEntry: (index: number) => void;
}>();

/** Two-way binding to form.color without mutating props directly */
const color = computed({
    get: () => props.form.color,
    set: (v: string) => { props.form.color = v; },
});

const {getStationApiUrl} = useApiRouter();
const {axios} = useAxios();
const categories = ref<{id: number; name: string}[]>([]);

void axios.get(getStationApiUrl('/media-categories').value).then(
    (resp) => { categories.value = resp.data?.rows ?? resp.data ?? []; },
    () => { categories.value = []; }
);
</script>