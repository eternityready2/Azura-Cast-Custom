<template>
    <canvas ref="$canvas" />
</template>

<script setup lang="ts">
import {computed, useTemplateRef} from "vue";
import useChart from "~/functions/useChart";
import {useTranslate} from "~/vendor/gettext";

interface ActivityPoint {
    timestamp: number,
    critical: number,
    warning: number,
    info: number,
}

const props = defineProps<{
    points: ActivityPoint[],
    windowHours?: number,
}>();

const {$gettext} = useTranslate();
const $canvas = useTemplateRef('$canvas');

const effectiveWindowHours = computed(() => {
    if (props.windowHours !== undefined) {
        return props.windowHours;
    }
    if (props.points.length < 2) {
        return 24;
    }

    return Math.max(
        1,
        Math.round((props.points[props.points.length - 1].timestamp - props.points[0].timestamp) / 3600),
    );
});

const labels = computed(() => props.points.map((point) => {
    const date = new Date(point.timestamp * 1000);
    if (effectiveWindowHours.value > 48) {
        return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
    }

    return date.toLocaleTimeString([], {hour: 'numeric'});
}));

const datasets = computed(() => [
    {
        label: $gettext('Critical'),
        data: props.points.map((point) => point.critical),
        backgroundColor: 'rgba(220, 53, 69, 0.82)',
        borderRadius: 4,
        maxBarThickness: 18,
    },
    {
        label: $gettext('Warnings'),
        data: props.points.map((point) => point.warning),
        backgroundColor: 'rgba(255, 193, 7, 0.78)',
        borderRadius: 4,
        maxBarThickness: 18,
    },
    {
        label: $gettext('Success / Info'),
        data: props.points.map((point) => point.info),
        backgroundColor: 'rgba(13, 202, 240, 0.38)',
        borderRadius: 4,
        maxBarThickness: 18,
    }
]);

useChart<'bar'>(
    {},
    $canvas,
    computed(() => ({
        type: 'bar',
        data: {
            labels: labels.value,
            datasets: datasets.value,
        },
        options: {
            aspectRatio: 2.35,
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        boxHeight: 8,
                    }
                },
                zoom: {
                    pan: {enabled: false},
                    zoom: {wheel: {enabled: false}, pinch: {enabled: false}},
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {display: false},
                    ticks: {maxRotation: 0, autoSkip: true, maxTicksLimit: 10},
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {precision: 0},
                    grid: {color: 'rgba(127, 127, 127, 0.12)'},
                }
            }
        }
    }))
);
</script>
