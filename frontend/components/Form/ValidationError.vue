<template>
    <div
        v-for="(error, index) of field.$errors"
        :key="index"
        class="invalid-feedback d-block"
    >
        {{ error }}
    </div>
</template>

<script setup lang="ts" generic="T = ModelFormField">
import {ModelFormField, ValidatedField} from "~/components/Form/useFormField.ts";

defineProps<{
    field: ValidatedField<T>,
}>();
</script>
