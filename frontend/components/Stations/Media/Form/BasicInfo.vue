<template>
    <tab :label="$gettext('Basic Information')"
         :item-header-class="tabClass">
        <div class="row g-3">
            <form-group-field
                id="edit_form_path"
                class="col-md-6"
                :field="r$.path"
                :label="$gettext('File Name')"
                :description="$gettext('The relative path of the file in the station\'s media directory.')"
            />

            <form-group-field
                id="edit_form_title"
                class="col-md-6"
                :field="r$.title"
                :label="$gettext('Song Title')"
            />

            <form-group-field
                id="edit_form_artist"
                class="col-md-6"
                :field="r$.artist"
                :label="$gettext('Song Artist')"
            />

            <form-group-field
                id="edit_form_genre"
                class="col-md-6"
                :field="r$.genre"
                :label="$gettext('Song Genre')"
            />

            <form-group-field
                id="edit_form_album"
                class="col-md-6"
                :field="r$.album"
                :label="$gettext('Song Album')"
            />

            <form-group-field
                id="edit_form_lyrics"
                class="col-md-6"
                :field="r$.lyrics"
                input-type="textarea"
                :label="$gettext('Song Lyrics')"
            />

            <form-group-field
                id="edit_form_isrc"
                class="col-md-6"
                :field="r$.isrc"
                :label="$gettext('ISRC')"
                :description="$gettext('International Standard Recording Code, used for licensing reports.')"
            />

            <div class="col-md-6">
                <label
                    class="form-label fw-semibold"
                    for="edit_form_type"
                >
                    {{ $gettext('Type') }}
                </label>
                <select
                    id="edit_form_type"
                    v-model="r$.type.$value"
                    class="form-select"
                >
                    <option value="music">{{ $gettext('Music (music and copyrighted material)') }}</option>
                    <option value="talk">{{ $gettext('Talk (sermons, speeches, and live recordings)') }}</option>
                    <option value="id">{{ $gettext('ID (station identification such as sweepers and jingles)') }}</option>
                    <option value="promo">{{ $gettext('Promo (station promotion that is not considered an ID)') }}</option>
                    <option value="ad">{{ $gettext('Ad (advert replacement files)') }}</option>
                </select>
            </div>

            <div class="col-md-6">
                <label
                    class="form-label fw-semibold"
                    for="edit_form_category"
                >
                    {{ $gettext('Category') }}
                </label>
                <select
                    id="edit_form_category"
                    v-model="r$.category_id.$value"
                    class="form-select"
                >
                    <option :value="null">{{ $gettext('— None —') }}</option>
                    <option
                        v-for="cat in categories"
                        :key="cat.id"
                        :value="cat.id"
                    >
                        {{ cat.name }}
                    </option>
                </select>
            </div>
        </div>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from "~/components/Form/FormGroupField.vue";
import {storeToRefs} from "pinia";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {computed, ref, onMounted} from "vue";
import {useStationsMediaForm} from "~/components/Stations/Media/Form/form.ts";
import Tab from "~/components/Common/Tab.vue";
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useAxios} from '~/vendor/axios.ts';

const {r$} = storeToRefs(useStationsMediaForm());
const tabClass = useFormTabClass(computed(() => r$.value.$groups.basicInfoTab));

const {getStationApiUrl} = useApiRouter();
const categoriesUrl = getStationApiUrl('/media-categories');
const {axios} = useAxios();

const categories = ref<{id: number; name: string}[]>([]);

onMounted(async () => {
    try {
        const resp = await axios.get(categoriesUrl.value);
        categories.value = resp.data?.rows ?? resp.data ?? [];
    } catch {
        categories.value = [];
    }
});
</script>
