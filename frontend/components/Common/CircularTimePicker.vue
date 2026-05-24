<template>
    <div ref="containerRef">
        <input
            ref="inputRef"
            :id="inputId"
            type="text"
            class="form-control"
            :value="displayValue"
            readonly
            :placeholder="$gettext('HH:MM')"
            :aria-label="ariaLabel"
        />
    </div>
</template>

<script setup lang="ts">
import {onMounted, onUnmounted, ref, computed} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {TimepickerUI} from 'timepicker-ui';
import 'timepicker-ui/main.css';

const props = withDefaults(
    defineProps<{
        modelValue?: number | null;
        inputId?: string;
        ariaLabel?: string;
    }>(),
    {
        modelValue: null,
        inputId: undefined,
        ariaLabel: undefined,
    }
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const {$gettext} = useTranslate();

const containerRef = ref<HTMLDivElement>();
const inputRef = ref<HTMLInputElement>();

let picker: TimepickerUI | null = null;

const displayValue = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined) {
        return '';
    }
    const val = props.modelValue;
    const hh = String(Math.floor(val / 100)).padStart(2, '0');
    const mm = String(val % 100).padStart(2, '0');
    return `${hh}:${mm}`;
});

onMounted(() => {
    if (!inputRef.value) return;

    picker = new TimepickerUI(inputRef.value, {
        clock: {
            type: '24h',
            currentTime: false,
        },
        ui: {
            theme: 'dark',
            mode: 'clock',
            animation: true,
            backdrop: true,
        },
        labels: {
            ok: $gettext('OK'),
            cancel: $gettext('Cancel'),
        },
        callbacks: {
            onConfirm: (data) => {
                if (data.hour !== undefined && data.minutes !== undefined) {
                    const hh = parseInt(data.hour, 10);
                    const mm = parseInt(data.minutes, 10);
                    if (!isNaN(hh) && !isNaN(mm)) {
                        emit('update:modelValue', hh * 100 + mm);
                    }
                }
            },
            onCancel: () => {
                // No change
            },
            onClear: () => {
                emit('update:modelValue', null);
            },
        },
    });

    picker.create();
});

onUnmounted(() => {
    picker?.destroy();
    picker = null;
});
</script>
