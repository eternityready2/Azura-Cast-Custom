<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_media_categories"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_media_categories"
                        class="card-title"
                    >
                        {{ $gettext('Manage Categories') }}
                    </h2>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="card-body-flush">
                <div class="card-body buttons">
                    <add-button
                        :text="$gettext('Add Category')"
                        @click="doCreate"
                    />
                </div>

                <data-table
                    id="station_media_categories"
                    paginated
                    :fields="fields"
                    :provider="listItemProvider"
                >
                    <template #cell(name)="{ item }">
                        <div
                            class="d-flex align-items-center gap-3"
                            role="button"
                            style="cursor: pointer;"
                            @click="doEdit(item.links.self)"
                        >
                            <span
                                class="d-inline-block rounded flex-shrink-0"
                                style="width: 1.25rem; height: 1.25rem;"
                                :style="{ backgroundColor: item.color ?? '#6366f1' }"
                            />
                            <span class="flex-grow-1">{{ item.name }}</span>
                        </div>
                    </template>

                    <template #cell(actions)="{ item }">
                        <div class="d-flex gap-2 justify-content-end">
                            <button
                                type="button"
                                class="btn btn-sm btn-secondary"
                                @click="doEdit(item.links.self)"
                            >
                                {{ $gettext('Edit') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                @click="doDelete(item.links.self)"
                            >
                                {{ $gettext('Delete') }}
                            </button>
                        </div>
                    </template>
                </data-table>
            </div>
        </div>
    </section>

    <edit-modal
        ref="$editModal"
        :create-url="listUrl"
        @relist="relist"
    />
</template>

<script setup lang="ts">
import DataTable, {DataTableField} from '~/components/Common/DataTable.vue';
import AddButton from '~/components/Common/AddButton.vue';
import {useTranslate} from '~/vendor/gettext';
import {useTemplateRef} from 'vue';
import useHasEditModal from '~/functions/useHasEditModal';
import {useApiItemProvider} from '~/functions/dataTable/useApiItemProvider.ts';
import {QueryKeys, queryKeyWithStation} from '~/entities/Queries.ts';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import EditModal from '~/components/Stations/MediaCategories/EditModal.vue';
import {useNotify} from '~/components/Common/Toasts/useNotify.ts';
import {useAxios} from '~/vendor/axios.ts';

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/media-categories');

const {$gettext} = useTranslate();
const {notifySuccess} = useNotify();
const {axios} = useAxios();

const fields: DataTableField[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
    {key: 'actions', label: $gettext('Actions'), class: 'shrink'},
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'media_categories'])
);

const relist = () => {
    void listItemProvider.refresh();
};

const $editModal = useTemplateRef('$editModal');
const {doCreate, doEdit} = useHasEditModal($editModal);

const doDelete = async (url: string) => {
    await axios.delete(url);
    notifySuccess($gettext('Category deleted.'));
    relist();
};
</script>
