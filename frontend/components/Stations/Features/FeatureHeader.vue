<template>
    <div class="card-header text-bg-primary">
        <div class="row align-items-center g-3">
            <div class="col">
                <h2 class="card-title mb-0">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle"
                    class="mb-0 mt-1 opacity-75"
                >
                    {{ subtitle }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="col-auto"
            >
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    title: string,
    subtitle?: string,
}>();
</script>
