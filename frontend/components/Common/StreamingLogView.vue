<template>
    <loading :loading="isLoading">
        <div v-if="logs === ''" class="alert alert-secondary log-empty-state">
            <strong>{{ $gettext('This log is currently empty.') }}</strong>
            <span>{{ $gettext('The log file exists, but the service has not written any entries to it yet. This is not the same as a diagnostics failure.') }}</span>
        </div>

        <form-group-checkbox
            id="modal_scroll_to_bottom"
            v-model="scrollToBottom"
            :label="$gettext('Automatically Scroll to Bottom')"
        />

        <textarea
            id="log-view-contents"
            ref="$textarea"
            class="form-control log-viewer"
            spellcheck="false"
            readonly
            :value="logs"
        />
    </loading>
</template>

<script setup lang="ts">
import {nextTick, ref, toRef, useTemplateRef, watch} from "vue";
import {useAxios} from "~/vendor/axios";
import {tryOnScopeDispose} from "@vueuse/core";
import Loading from "~/components/Common/Loading.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import {ApiLogContents} from "~/entities/ApiInterfaces.ts";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    logUrl: string
}>();

const {$gettext} = useTranslate();
const isLoading = ref<boolean>(false);
const logs = ref<string>('');
const currentLogPosition = ref<number | null>(null);
const scrollToBottom = ref<boolean>(true);

const {axiosSilent} = useAxios();

const $textarea = useTemplateRef('$textarea');

let updateInterval: ReturnType<typeof setInterval> | null = null;

const stop = () => {
    if (updateInterval) {
        clearInterval(updateInterval);
        updateInterval = null;
    }
};

tryOnScopeDispose(stop);

const updateLogs = async () => {
    try {
        const {data} = await axiosSilent.request<ApiLogContents>({
            method: 'GET',
            url: props.logUrl,
            params: {
                position: currentLogPosition.value
            }
        });

        if (data.contents !== '') {
            logs.value = logs.value + data.contents + "\n";
            if (scrollToBottom.value && $textarea.value) {
                void nextTick(() => {
                    $textarea.value!.scrollTop = $textarea.value?.scrollHeight ?? 0;
                });
            }
        }

        currentLogPosition.value = data.position;

        if (data.eof) {
            stop();
        }
    } finally {
        isLoading.value = false;
    }
};

watch(toRef(props, 'logUrl'), (newLogUrl) => {
    isLoading.value = true;
    logs.value = '';
    currentLogPosition.value = 0;
    stop();

    if ('' !== newLogUrl) {
        updateInterval = setInterval(() => void updateLogs(), 2500);
        void updateLogs();
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
