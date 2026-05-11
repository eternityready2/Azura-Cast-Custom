<template>
    <section
        class="card"
        role="region"
        aria-labelledby="hdr_clock_wheels"
    >
        <div class="card-header text-bg-primary">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2
                        id="hdr_clock_wheels"
                        class="card-title"
                    >
                        {{ $gettext('Manage Clock Wheels') }}
                    </h2>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="card-body-flush">
                <div class="card-body buttons">
                    <add-button
                        :text="$gettext('Add Clock Wheel')"
                        @click="doCreate"
                    />
                </div>

                <data-table
                    id="station_clock_wheels"
                    paginated
                    :fields="fields"
                    :provider="listItemProvider"
                >
                    <template #cell(name)="{ item }">
                        <div
                            class="d-flex align-items-center gap-3 clock-wheel-row"
                            role="button"
                            style="cursor: pointer;"
                            @click="doEdit(item.links.self)"
                        >
                            <h5 class="m-0 flex-grow-1">{{ item.name }}</h5>
                            <span
                                class="d-inline-block rounded flex-shrink-0"
                                style="width: 1.5rem; height: 1.5rem;"
                                :style="{backgroundColor: item.color ?? '#cccccc'}"
                            />
                            <icon-bi-chevron-right class="clock-wheel-chevron text-muted flex-shrink-0" />
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
import EditModal from '~/components/Stations/ClockWheels/EditModal.vue';
import IconBiChevronRight from '~icons/bi/chevron-right';

const {getStationApiUrl} = useApiRouter();
const listUrl = getStationApiUrl('/clock-wheels');

const {$gettext} = useTranslate();

const fields: DataTableField[] = [
    {key: 'name', isRowHeader: true, label: $gettext('Name'), sortable: true},
];

const listItemProvider = useApiItemProvider(
    listUrl,
    queryKeyWithStation([QueryKeys.StationPlaylists, 'clock_wheels'])
);

const relist = () => {
    void listItemProvider.refresh();
};

const $editModal = useTemplateRef('$editModal');

const {doCreate, doEdit} = useHasEditModal($editModal);
</script>

<style scoped>
.clock-wheel-chevron {
    opacity: 0;
    transition: opacity 0.15s;
}

tr:hover .clock-wheel-chevron {
    opacity: 1;
}
</style>
