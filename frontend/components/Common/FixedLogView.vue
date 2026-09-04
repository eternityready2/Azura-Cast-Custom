<template>
    <loading :loading="isLoading">
        <div v-if="logs === ''" class="alert alert-secondary log-empty-state">
            <strong>{{ $gettext('This log is currently empty.') }}</strong>
            <span>{{ $gettext('The file exists, but there are no entries to display. Use the station Developer Report for a populated operational diagnostic summary.') }}</span>
        </div>

        <div style="height: 300px; resize: vertical; overflow: auto !important;">
            <code-mirror
                id="log-view-contents"
                v-model="logs"
                readonly
                basic
                :dark="isDark"
            />
        </div>
    </loading>
</template>

<script setup lang="ts">
import {ref, toRef, watch} from "vue";
import {useAxios} from "~/vendor/axios.ts";
import Loading from "~/components/Common/Loading.vue";
import CodeMirror from "vue-codemirror6";
import {useTheme} from "~/functions/theme.ts";
import {ApiLogContents} from "~/entities/ApiInterfaces.ts";
import {storeToRefs} from "pinia";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    logUrl: string
}>();

const {$gettext} = useTranslate();
const isLoading = ref(false);
const logs = ref('');

const {isDark} = storeToRefs(useTheme());
const {axios} = useAxios();

watch(toRef(props, 'logUrl'), (newLogUrl) => {
    isLoading.value = true;
    logs.value = '';

    if ('' !== newLogUrl) {
        void (async () => {
            try {
                const {data} = await axios.request<ApiLogContents>({
                    method: 'GET',
                    url: props.logUrl
                });

                logs.value = data.contents ?? '';
            } finally {
                isLoading.value = false;
            }
        })();
    } else {
        isLoading.value = false;
    }
}, {immediate: true});

const getContents = () => logs.value;

defineExpose({
    getContents
});
</script>

<style scoped>
.log-empty-state {
    display: grid;
    gap: 0.2rem;
    margin-bottom: 0.75rem;
}

.log-empty-state span {
    font-size: 0.85rem;
}
</style>
