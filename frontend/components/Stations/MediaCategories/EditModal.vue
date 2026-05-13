<template>
    <modal-form
        ref="$modal"
        :loading="loading"
        :title="langTitle"
        :error="error"
        :disable-save-button="r$.$invalid"
        @submit="doSubmit"
        @hidden="clearContents"
    >
        <div class="mb-3">
            <form-group-field
                id="category_name"
                :field="r$.name"
                :label="$gettext('Title')"
            />
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">{{ $gettext('Color') }} *</label>
            <div>
                <input
                    id="category_color"
                    v-model="form.color"
                    type="color"
                    style="width: 3rem; height: 3rem; padding: 0.15rem; border: 2px solid #555; border-radius: 6px; cursor: pointer; background: none;"
                />
            </div>
        </div>

        <template
            v-if="isEditMode"
            #modal-footer
        >
            <button
                type="button"
                class="btn btn-danger me-auto"
                @click="doDeleteFromModal"
            >
                {{ $gettext('Delete') }}
            </button>
            <button
                type="button"
                class="btn btn-secondary"
                @click="close"
            >
                {{ $gettext('Close') }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="r$.$invalid"
                @click="doSubmit"
            >
                {{ $gettext('Save Changes') }}
            </button>
        </template>
    </modal-form>
</template>

<script setup lang="ts">
import ModalForm from '~/components/Common/ModalForm.vue';
import FormGroupField from '~/components/Form/FormGroupField.vue';
import {BaseEditModalEmits, BaseEditModalProps, useBaseEditModal} from '~/functions/useBaseEditModal';
import {computed, ref, useTemplateRef} from 'vue';
import {useTranslate} from '~/vendor/gettext';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAppRegle} from '~/vendor/regle.ts';
import {required} from '@regle/rules';
import mergeExisting from '~/functions/mergeExisting.ts';
import useConfirmAndDelete from '~/functions/useConfirmAndDelete.ts';

const props = defineProps<BaseEditModalProps>();
const emit = defineEmits<BaseEditModalEmits>();

const $modal = useTemplateRef('$modal');
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();

const blankForm = {
    name: '',
    color: '#6366f1',
};

const form = ref({...blankForm});

const {r$} = useAppRegle(form, {
    name: {required},
    color: {},
});

const resetForm = () => {
    form.value = {...blankForm};
};

const populateForm = (data: Record<string, unknown>) => {
    form.value = mergeExisting(form.value, data);
};

const validateForm = async () => {
    const {valid} = await r$.$validate();
    return {valid, data: {...form.value}};
};

const langTitle = computed(() =>
    isEditMode.value ? $gettext('Edit Category') : $gettext('Add Category')
);

const {
    loading,
    error,
    isEditMode,
    editUrl,
    clearContents,
    create,
    edit,
    close,
    doSubmit,
} = useBaseEditModal(
    computed(() => props.createUrl),
    emit,
    $modal,
    resetForm,
    populateForm,
    validateForm,
    {
        onSubmitSuccess: () => {
            notifySuccess($gettext('Category saved.'));
        },
    }
);

const {doDelete} = useConfirmAndDelete(
    $gettext('Delete Category?'),
    () => {
        emit('relist');
    }
);

const doDeleteFromModal = () => {
    if (editUrl.value) {
        $modal.value?.hide();
        doDelete(editUrl.value);
    }
};

defineExpose({create, edit});
</script>
