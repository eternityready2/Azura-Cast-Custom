<template>
    <div class="feature-outcome-chart">
        <canvas ref="$canvas" />
    </div>
</template>

<script setup lang="ts">
import {computed, useTemplateRef} from "vue";
import useChart from "~/functions/useChart";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    successes: number,
    warnings: number,
    failures: number,
}>();

const {$gettext} = useTranslate();
const $canvas = useTemplateRef('$canvas');

const labels = computed(() => [
    $gettext('Success'),
    $gettext('Warning'),
    $gettext('Failure'),
]);

const values = computed(() => [
    props.successes,
    props.warnings,
    props.failures,
]);

useChart<'bar'>(
    {},
    $canvas,
    computed(() => ({
        type: 'bar',
        data: {
            labels: labels.value,
            datasets: [{
                data: values.value,
                backgroundColor: [
                    'rgba(25, 135, 84, 0.76)',
                    'rgba(255, 193, 7, 0.76)',
                    'rgba(220, 53, 69, 0.8)',
                ],
                borderWidth: 0,
                borderRadius: 5,
                maxBarThickness: 18,
            }],
        },
        options: {
            aspectRatio: 3.4,
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {display: false},
                zoom: {
                    pan: {enabled: false},
                    zoom: {wheel: {enabled: false}, pinch: {enabled: false}},
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {precision: 0},
                    grid: {color: 'rgba(127, 127, 127, 0.1)'},
                },
                y: {
                    grid: {display: false},
                    ticks: {font: {size: 10}},
                },
            },
        },
    }))
);
</script>

<style scoped>
.feature-outcome-chart {
    min-height: 92px;
}
</style>
