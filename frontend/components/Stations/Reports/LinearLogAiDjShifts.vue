<template>
    <section v-if="shifts.length" class="ai-dj-shifts">
        <div class="ai-dj-shifts-title">{{ $gettext('AI DJ Work Shifts') }}</div>
        <div class="d-flex flex-wrap gap-2">
            <div
                v-for="shift in shifts"
                :key="`${shift.schedule_id}-${shift.starts_at}`"
                class="ai-dj-shift"
            >
                <strong>{{ shift.dj_name }}</strong>
                <span>{{ formatDateTime(shift.starts_at) }} – {{ formatDateTime(shift.ends_at) }}</span>
                <small v-if="shift.schedule_name">{{ shift.schedule_name }}</small>
            </div>
        </div>
        <div class="small text-body-secondary mt-2">
            {{ $gettext('The shift is scheduled here, but the DJ\'s exact talk breaks and speech are generated live and are not prebuilt by the Linear Log.') }}
        </div>
    </section>
</template>

<script setup lang="ts">
import type {LinearLogAiDjShift} from "~/entities/LinearLog";

defineProps<{
    shifts: LinearLogAiDjShift[];
}>();

function formatDateTime(timestamp: number): string {
    return new Date(timestamp * 1000).toLocaleString([], {
        weekday: "short",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
    });
}
</script>

<style scoped>
.ai-dj-shifts{padding:.8rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-info-bg-subtle) 35%,var(--bs-body-bg))}
.ai-dj-shifts-title{margin-bottom:.5rem;font-size:.8rem;font-weight:750}
.ai-dj-shift{display:flex;flex-direction:column;min-width:240px;padding:.5rem .65rem;border:1px solid var(--bs-border-color);border-radius:.45rem;background:var(--bs-body-bg);font-size:.76rem}
.ai-dj-shift small{color:var(--bs-secondary-color)}
</style>
