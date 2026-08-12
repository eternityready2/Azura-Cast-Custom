<template>
    <tab :label="$gettext('Smart Block')">
        <div class="row g-3 mb-3">
            <form-group-checkbox
                id="form_edit_is_smart_block"
                class="col-md-12"
                :field="r$.is_smart_block"
                :label="$gettext('Enable Smart Block')"
                :description="$gettext('Instead of managing songs by hand, automatically keep this playlist\'s membership in sync with tracks matching criteria you define below (genre, BPM, mood, or any Custom Field). Everything else about this playlist — weight, scheduling, rotation goal — still works exactly as normal, and it still shows in your main Playlists list, just tagged as a Smart Block.')"
            />
        </div>

        <template v-if="form.is_smart_block">
            <div
                v-if="!isEditMode"
                class="alert alert-info"
            >
                {{ $gettext('Save this playlist first, then come back to this tab to define its Smart Block criteria.') }}
            </div>

            <smart-block-criteria-editor
                v-else-if="smartBlockUrl"
                :key="smartBlockUrl"
                :smart-block-url="smartBlockUrl"
            />
        </template>
    </tab>
</template>

<script setup lang="ts">
import {computed} from "vue";
import {storeToRefs} from "pinia";
import Tab from "~/components/Common/Tab.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import SmartBlockCriteriaEditor from "~/components/Stations/Playlists/SmartBlockCriteriaEditor.vue";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

const {form, r$} = storeToRefs(useStationsPlaylistsForm());

const isEditMode = computed(() => !!form.value.id);

const smartBlockUrl = computed<string | null>(() => {
    const self = form.value.links?.self;
    if (self) {
        return `${self}/smart-block`;
    }
    return null;
});
</script>
