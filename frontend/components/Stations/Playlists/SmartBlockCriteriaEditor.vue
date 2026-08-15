<template>
    <div>
        <div
            v-if="editorLoading"
            class="p-5 text-center"
        >
            <div class="spinner-border" />
        </div>

        <template v-else>
            <div
                v-if="standalone"
                class="row g-3 mb-3 pb-3 border-bottom"
            >
                <form-group-field
                    id="smart_block_name"
                    class="col-md-8"
                    v-model="basicInfo.name"
                    :label="$gettext('Name')"
                    :input-attrs="{required: true}"
                />

                <form-group-field
                    id="smart_block_weight"
                    class="col-md-4"
                    v-model="basicInfo.weight"
                    input-type="number"
                    :input-attrs="{min: '0'}"
                    :label="$gettext('Weight')"
                    :description="$gettext('Larger numbers play more often relative to other playlists.')"
                />

                <form-group-checkbox
                    id="smart_block_is_enabled"
                    class="col-md-12"
                    v-model="basicInfo.is_enabled"
                    :label="$gettext('Enabled')"
                />
            </div>

            <tabs content-class="mt-3">
                <tab :label="$gettext('Options')">
                    <div class="row g-3">
                        <form-group-multi-check
                            id="smart_block_type"
                            class="col-md-6"
                            v-model="editor.smart_block_type"
                            :options="typeOptions"
                            stacked
                            radio
                            :label="$gettext('Type')"
                            :description="$gettext('Dynamic stays in sync automatically. Static generates a one-time tracklist you can hand-edit, until you Generate again.')"
                        />

                        <form-group-multi-check
                            id="smart_block_match_type"
                            class="col-md-6"
                            v-model="editor.smart_block_match_type"
                            :options="matchTypeOptions"
                            stacked
                            radio
                            :label="$gettext('Match')"
                        />

                        <div class="col-md-6">
                            <div class="row g-2">
                                <form-group-multi-check
                                    id="smart_block_limit_type"
                                    class="col-12"
                                    v-model="editor.smart_block_limit_type"
                                    :options="limitTypeOptions"
                                    stacked
                                    radio
                                    :label="$gettext('Limit To')"
                                />

                                <form-group-field
                                    v-if="editor.smart_block_limit_type === SmartBlockLimitType.Tracks"
                                    id="smart_block_limit_tracks"
                                    class="col-12"
                                    v-model="limitTracks"
                                    input-type="number"
                                    :input-attrs="{min: '0'}"
                                    :label="$gettext('Maximum Tracks (Optional)')"
                                    :description="$gettext('Leave blank for no cap.')"
                                />

                                <form-group-field
                                    v-else
                                    id="smart_block_limit_duration"
                                    class="col-12"
                                    v-model="limitDurationMinutes"
                                    input-type="number"
                                    :input-attrs="{min: '0', step: '1'}"
                                    :label="$gettext('Maximum Duration (Minutes, Optional)')"
                                    :description="$gettext('Leave blank for no cap.')"
                                />
                            </div>
                        </div>

                        <form-group-select
                            id="smart_block_sort_order"
                            class="col-md-6"
                            v-model="editor.smart_block_sort_order"
                            :options="sortOrderOptions"
                            :label="$gettext('Sort Tracks')"
                            :description="$gettext('Controls the order tracks are weighted when the block syncs. For Sequential playlists this is the on-air playback order; for Shuffle/Random it seeds the initial order.')"
                        />

                        <form-group-checkbox
                            id="smart_block_avoid_duplicates"
                            class="col-md-6"
                            v-model="editor.smart_block_avoid_duplicates"
                            :label="$gettext('Allow Repeated Tracks')"
                            :description="$gettext('When unchecked, tracks already in this block are not re-added on the next sync — useful for small libraries where you want strict rotation.')"
                        />
                    </div>
                </tab>

                <tab :label="$gettext('Criteria')">
                    <p
                        v-if="editor.criteria.length === 0"
                        class="text-muted"
                    >
                        {{ $gettext('No criteria yet — add one below to start matching tracks from your media library.') }}
                    </p>

                    <div
                        v-if="availableCustomFields.length === 0 && hasCustomFieldRow"
                        class="alert alert-info py-2 px-3 small"
                        role="alert"
                    >
                        {{ $gettext('You don\'t have any Custom Fields yet, so the Custom Field dropdown below has nothing to pick from. Go to Admin → Custom Fields to create one (e.g. "BPM" or "Mood"), tag some tracks with it under Media, then come back and select it here.') }}
                    </div>

                    <div
                        v-if="hasIncompleteCustomFieldRow"
                        class="alert alert-warning py-2 px-3 small"
                        role="alert"
                    >
                        {{ $gettext('One or more "Custom Field" rows below has no field selected. An incomplete row like this is skipped entirely — it will not match anything and will not block other rules. Pick a Custom Field for it, or change its Field to something else, or remove it.') }}
                    </div>

                    <div
                        v-for="(row, index) in editor.criteria"
                        :key="row._rowId"
                        class="row g-2 align-items-end mb-3 pb-3 border-bottom"
                    >
                        <form-group-select
                            :id="`smart_block_field_${index}`"
                            class="col-12 col-md-3"
                            :model-value="row.field"
                            :options="fieldOptions"
                            :label="$gettext('Field')"
                            @update:model-value="updateField(index, $event as string)"
                        />

                        <div
                            v-if="row.field === SmartBlockCriteriaField.CustomField && availableCustomFields.length === 0"
                            class="col-12 col-md-3 small text-muted"
                        >
                            <label class="form-label d-block">{{ $gettext('Custom Field') }}</label>
                            {{ $gettext('None created yet — see Admin → Custom Fields.') }}
                        </div>

                        <form-group-select
                            v-else-if="row.field === SmartBlockCriteriaField.CustomField"
                            :id="`smart_block_custom_field_${index}`"
                            class="col-12 col-md-3"
                            :model-value="row.custom_field_id ?? undefined"
                            :options="customFieldOptions"
                            :label="$gettext('Custom Field')"
                            @update:model-value="updateRow(index, {custom_field_id: Number($event)})"
                        />

                        <form-group-select
                            :id="`smart_block_comparison_${index}`"
                            class="col-12 col-md-2"
                            :model-value="row.comparison"
                            :options="comparisonOptionsFor(row)"
                            :label="$gettext('Comparison')"
                            @update:model-value="updateRow(index, {comparison: $event as string})"
                        />

                        <form-group-field
                            :id="`smart_block_value_${index}`"
                            class="col-12 col-md-2"
                            :model-value="row.value ?? ''"
                            :label="valueLabelFor(row)"
                            @update:model-value="updateRow(index, {value: $event as string})"
                        />

                        <form-group-field
                            v-if="row.comparison === SmartBlockCriteriaComparison.Between"
                            :id="`smart_block_value2_${index}`"
                            class="col-12 col-md-2"
                            :model-value="row.value2 ?? ''"
                            :label="$gettext('And')"
                            @update:model-value="updateRow(index, {value2: $event as string})"
                        />

                        <div class="col-12 col-md-auto">
                            <button
                                type="button"
                                class="btn btn-danger"
                                :title="$gettext('Remove criterion')"
                                @click="removeRow(index)"
                            >
                                <icon-ic-delete />
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="addRow"
                    >
                        <icon-ic-add class="me-1" />{{ $gettext('Add Criterion') }}
                    </button>
                </tab>

                <tab :label="$gettext('Preview')">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="badge text-bg-primary fs-6">
                            {{ $gettext('%{count} tracks match', {count: preview.matching_count}) }}
                        </span>
                        <span class="badge text-bg-secondary fs-6">
                            {{ $gettext('%{duration} of content', {duration: formatDuration(preview.matching_duration_seconds)}) }}
                        </span>
                        <span class="badge text-bg-info fs-6">
                            {{ $gettext('%{count} currently members', {count: preview.current_member_count}) }}
                        </span>
                    </div>

                    <p
                        v-if="preview.preview.length === 0"
                        class="text-muted"
                    >
                        {{ $gettext('No tracks currently match these criteria.') }}
                    </p>

                    <ul
                        v-else
                        class="list-group list-group-flush"
                    >
                        <li
                            v-for="track in preview.preview"
                            :key="track.id"
                            class="list-group-item d-flex justify-content-between align-items-center"
                        >
                            <div class="min-w-0">
                                <div class="text-truncate">
                                    {{ track.title || $gettext('(Untitled)') }}
                                </div>
                                <div class="text-muted small text-truncate">
                                    {{ track.artist }}<template v-if="track.genre"> &middot; {{ track.genre }}</template>
                                </div>
                            </div>
                            <span class="badge text-bg-secondary text-nowrap ms-2">
                                {{ formatDuration(track.length) }}
                            </span>
                        </li>
                    </ul>

                    <p
                        v-if="preview.matching_count > preview.preview.length"
                        class="text-muted small mt-2 mb-0"
                    >
                        {{
                            $gettext(
                                '…and %{more} more.',
                                {more: preview.matching_count - preview.preview.length}
                            )
                        }}
                    </p>
                </tab>
            </tabs>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
                <button
                    v-if="standalone"
                    type="button"
                    class="btn btn-danger"
                    :disabled="saving"
                    @click="doDelete"
                >
                    <icon-ic-delete class="me-1" />{{ $gettext('Delete') }}
                </button>
                <span v-else />

                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="saving"
                    @click="doSave"
                >
                    <span
                        v-if="saving"
                        class="spinner-border spinner-border-sm me-1"
                    />
                    {{ editor.smart_block_type === SmartBlockType.Static
                        ? $gettext('Save & Generate Now')
                        : $gettext('Save & Sync Now') }}
                </button>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import {computed, onMounted, reactive, ref, watch} from "vue";
import Tab from "~/components/Common/Tab.vue";
import Tabs from "~/components/Common/Tabs.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import {
    SmartBlockCriteriaComparison,
    SmartBlockCriteriaField,
    SmartBlockLimitType,
    SmartBlockType,
    type StationPlaylistSmartBlockCriterion,
} from "~/entities/ApiInterfaces.ts";
import {getErrorAsString, useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import IconIcAdd from "~icons/ic/baseline-add";
import IconIcDelete from "~icons/ic/baseline-delete";

const props = withDefaults(defineProps<{
    /** Full API URL for this playlist's smart-block sub-resource, e.g. ".../playlist/5/smart-block". */
    smartBlockUrl: string,
    /** Full API URL for this playlist itself (its "self" link), needed for Delete.
     *  Only required when standalone is true. */
    playlistUrl?: string,
    /** When true, this is the ONLY editor for the playlist (the standalone Smart
     *  Blocks page) -- so it also manages Name/Weight/Enabled and offers Delete.
     *  When false (embedded elsewhere), those fields are assumed to be handled by
     *  whatever's hosting this component instead. */
    standalone?: boolean,
}>(), {
    playlistUrl: undefined,
    standalone: false,
});

const emit = defineEmits<{
    saved: [payload: {added: number, removed: number, total_members: number}],
    deleted: [],
}>();

const {$gettext} = useTranslate();
const {axios} = useAxios();
const {notifySuccess, notifyError} = useNotify();

type EditorCriterionRow = StationPlaylistSmartBlockCriterion & {_rowId: string};

let rowIdCounter = 0;
const nextRowId = (): string => `row-${Date.now()}-${rowIdCounter++}`;

const criterionFingerprint = (row: StationPlaylistSmartBlockCriterion): string => JSON.stringify([
    row.field, row.comparison, row.value ?? '', row.value2 ?? '', row.custom_field_id ?? null,
]);

const dedupeCriteria = <T extends StationPlaylistSmartBlockCriterion>(rows: T[]): T[] => {
    const seen = new Set<string>();
    return rows.filter((row) => {
        const fingerprint = criterionFingerprint(row);
        if (seen.has(fingerprint)) {
            return false;
        }
        seen.add(fingerprint);
        return true;
    });
};

const editorLoading = ref<boolean>(true);
const saving = ref<boolean>(false);

const basicInfo = reactive({
    name: '',
    weight: 3,
    is_enabled: true,
});

type EditorState = {
    smart_block_type: string,
    smart_block_match_type: string,
    smart_block_limit: number | null,
    smart_block_limit_type: string,
    smart_block_sort_order: string,
    smart_block_avoid_duplicates: boolean,
    criteria: EditorCriterionRow[],
};

const editor = ref<EditorState>({
    smart_block_type: SmartBlockType.Dynamic,
    smart_block_match_type: 'all',
    smart_block_limit: null,
    smart_block_limit_type: SmartBlockLimitType.Tracks,
    smart_block_sort_order: 'random',
    smart_block_avoid_duplicates: true,
    criteria: [],
});

type PreviewState = {
    matching_count: number,
    matching_duration_seconds: number,
    current_member_count: number,
    preview: {id: number, title?: string, artist?: string, genre?: string, length: number}[],
};

const preview = ref<PreviewState>({
    matching_count: 0,
    matching_duration_seconds: 0,
    current_member_count: 0,
    preview: [],
});

const availableCustomFields = ref<{id: number, name: string, short_name: string}[]>([]);

const limitTracks = computed<number | null>({
    get: () => editor.value.smart_block_limit_type === SmartBlockLimitType.Tracks
        ? editor.value.smart_block_limit
        : null,
    set: (value) => {
        editor.value.smart_block_limit = value;
    },
});

const limitDurationMinutes = computed<number | null>({
    get: () => {
        if (editor.value.smart_block_limit_type !== SmartBlockLimitType.Duration
            || editor.value.smart_block_limit === null) {
            return null;
        }
        return Math.round(editor.value.smart_block_limit / 60);
    },
    set: (value) => {
        editor.value.smart_block_limit = (value === null || value === undefined)
            ? null
            : Math.round(value * 60);
    },
});

const matchTypeOptions = [
    {value: 'all', text: $gettext('Match ALL criteria (AND)')},
    {value: 'any', text: $gettext('Match ANY criterion (OR)')},
];

const typeOptions = [
    {value: SmartBlockType.Dynamic, text: $gettext('Dynamic')},
    {value: SmartBlockType.Static, text: $gettext('Static')},
];

const limitTypeOptions = [
    {value: SmartBlockLimitType.Tracks, text: $gettext('Number of Tracks')},
    {value: SmartBlockLimitType.Duration, text: $gettext('Total Duration')},
];

const sortOrderOptions: Record<string, string> = {
    'random': $gettext('Random'),
    'newest_first': $gettext('Newest First'),
    'oldest_first': $gettext('Oldest First'),
    'alpha_title': $gettext('Alphabetical (Title)'),
    'alpha_artist': $gettext('Alphabetical (Artist)'),
};

const baseFieldOptions: Record<string, string> = {
    [SmartBlockCriteriaField.Genre]: $gettext('Genre'),
    [SmartBlockCriteriaField.Category]: $gettext('Category'),
    [SmartBlockCriteriaField.Artist]: $gettext('Artist'),
    [SmartBlockCriteriaField.Album]: $gettext('Album'),
    [SmartBlockCriteriaField.Title]: $gettext('Title'),
    [SmartBlockCriteriaField.Duration]: $gettext('Duration'),
    [SmartBlockCriteriaField.LastPlayed]: $gettext('Last Played (days ago)'),
    [SmartBlockCriteriaField.CustomField]: $gettext('Custom Field (e.g. BPM, Mood)'),
};

const fieldOptions = computed<Record<string, string>>(() => baseFieldOptions);

const hasIncompleteCustomFieldRow = computed<boolean>(() => editor.value.criteria.some(
    (row) => row.field === SmartBlockCriteriaField.CustomField && !row.custom_field_id,
));

const hasCustomFieldRow = computed<boolean>(() => editor.value.criteria.some(
    (row) => row.field === SmartBlockCriteriaField.CustomField,
));

const customFieldOptions = computed<Record<string, string>>(() => {
    const options: Record<string, string> = {};
    for (const field of availableCustomFields.value) {
        options[String(field.id)] = field.name;
    }
    return options;
});

const textComparisonOptions: Record<string, string> = {
    [SmartBlockCriteriaComparison.Is]: $gettext('Is'),
    [SmartBlockCriteriaComparison.IsNot]: $gettext('Is Not'),
    [SmartBlockCriteriaComparison.Contains]: $gettext('Contains'),
    [SmartBlockCriteriaComparison.NotContains]: $gettext('Does Not Contain'),
};

const numericComparisonOptions: Record<string, string> = {
    [SmartBlockCriteriaComparison.Is]: $gettext('Is'),
    [SmartBlockCriteriaComparison.IsNot]: $gettext('Is Not'),
    [SmartBlockCriteriaComparison.GreaterThan]: $gettext('Greater Than'),
    [SmartBlockCriteriaComparison.LessThan]: $gettext('Less Than'),
    [SmartBlockCriteriaComparison.Between]: $gettext('Between'),
};

const customFieldComparisonOptions: Record<string, string> = {
    ...textComparisonOptions,
    [SmartBlockCriteriaComparison.GreaterThan]: $gettext('Greater Than (numeric)'),
    [SmartBlockCriteriaComparison.LessThan]: $gettext('Less Than (numeric)'),
    [SmartBlockCriteriaComparison.Between]: $gettext('Between (numeric)'),
};

const comparisonOptionsFor = (
    row: StationPlaylistSmartBlockCriterion,
): Record<string, string> => {
    if (row.field === SmartBlockCriteriaField.Duration || row.field === SmartBlockCriteriaField.LastPlayed) {
        return numericComparisonOptions;
    }
    if (row.field === SmartBlockCriteriaField.CustomField) {
        return customFieldComparisonOptions;
    }
    return textComparisonOptions;
};

const valueLabelFor = (row: StationPlaylistSmartBlockCriterion): string => {
    if (row.field === SmartBlockCriteriaField.Duration) {
        return $gettext('Value (seconds)');
    }
    if (row.field === SmartBlockCriteriaField.LastPlayed) {
        return $gettext('Value (days)');
    }
    return $gettext('Value');
};

const formatDuration = (seconds: number): string => {
    const total = Math.max(0, Math.round(seconds ?? 0));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    return `${m}:${String(s).padStart(2, '0')}`;
};

const loadEditor = async (): Promise<void> => {
    editorLoading.value = true;
    try {
        const {data} = await axios.get(props.smartBlockUrl);

        editor.value = {
            smart_block_type: data.smart_block_type ?? SmartBlockType.Dynamic,
            smart_block_match_type: data.smart_block_match_type ?? 'all',
            smart_block_limit: data.smart_block_limit ?? null,
            smart_block_limit_type: data.smart_block_limit_type ?? SmartBlockLimitType.Tracks,
            smart_block_sort_order: data.smart_block_sort_order ?? 'random',
            smart_block_avoid_duplicates: data.smart_block_avoid_duplicates ?? true,
            criteria: dedupeCriteria((data.criteria ?? []) as StationPlaylistSmartBlockCriterion[])
                .map((row) => ({...row, _rowId: nextRowId()})),
        };

        preview.value = {
            matching_count: data.matching_count ?? 0,
            matching_duration_seconds: data.matching_duration_seconds ?? 0,
            current_member_count: data.current_member_count ?? 0,
            preview: data.preview ?? [],
        };

        availableCustomFields.value = data.available_custom_fields ?? [];

        if (props.standalone) {
            basicInfo.name = data.name ?? '';
            basicInfo.weight = data.weight ?? 3;
            basicInfo.is_enabled = data.is_enabled ?? true;
        }
    } catch (err) {
        notifyError(`${$gettext('Failed to load Smart Block.')}: ${getErrorAsString(err)}`);
    } finally {
        editorLoading.value = false;
    }
};

const addRow = (): void => {
    editor.value.criteria = [
        ...editor.value.criteria,
        {
            _rowId: nextRowId(),
            field: SmartBlockCriteriaField.Genre,
            comparison: SmartBlockCriteriaComparison.Is,
            value: '',
            value2: null,
            custom_field_id: null,
        },
    ];
};

const removeRow = (index: number): void => {
    const updated = [...editor.value.criteria];
    updated.splice(index, 1);
    editor.value.criteria = updated;
};

const updateRow = (
    index: number,
    changes: Partial<StationPlaylistSmartBlockCriterion>,
): void => {
    const updated = [...editor.value.criteria];
    updated[index] = {...updated[index], ...changes};
    editor.value.criteria = updated;
};

const updateField = (index: number, field: string): void => {
    const isNumericOnly = field === SmartBlockCriteriaField.Duration
        || field === SmartBlockCriteriaField.LastPlayed;
    updateRow(index, {
        field: field as SmartBlockCriteriaField,
        custom_field_id: field === SmartBlockCriteriaField.CustomField
            ? editor.value.criteria[index].custom_field_id
            : null,
        comparison: isNumericOnly
            ? SmartBlockCriteriaComparison.GreaterThan
            : SmartBlockCriteriaComparison.Is,
    });
};

const doSave = async (): Promise<void> => {
    saving.value = true;
    try {
        // Hard safety net: never send exact-duplicate rows or _rowId (a client-only
        // field the backend doesn't know about), no matter how a duplicate ended up
        // in the array in the first place.
        const dedupedCriteria = dedupeCriteria(editor.value.criteria)
            .map(({_rowId, ...row}) => row);

        const {data} = await axios.put(props.smartBlockUrl, {
            ...(props.standalone ? {
                name: basicInfo.name,
                is_enabled: basicInfo.is_enabled,
                weight: basicInfo.weight,
            } : {}),
            is_smart_block: true,
            smart_block_type: editor.value.smart_block_type,
            smart_block_match_type: editor.value.smart_block_match_type,
            smart_block_limit: editor.value.smart_block_limit,
            smart_block_limit_type: editor.value.smart_block_limit_type,
            smart_block_sort_order: editor.value.smart_block_sort_order,
            smart_block_avoid_duplicates: editor.value.smart_block_avoid_duplicates,
            criteria: dedupedCriteria,
        });

        await loadEditor();

        notifySuccess(
            editor.value.smart_block_type === SmartBlockType.Static
                ? $gettext(
                    '%{added} tracks added, %{removed} removed. Tracklist generated.',
                    {added: data.added ?? 0, removed: data.removed ?? 0},
                )
                : $gettext(
                    '%{added} tracks added, %{removed} removed. Smart Block synced.',
                    {added: data.added ?? 0, removed: data.removed ?? 0},
                ),
        );

        emit('saved', {
            added: data.added ?? 0,
            removed: data.removed ?? 0,
            total_members: data.total_members ?? 0,
        });
    } catch (err) {
        notifyError(`${$gettext('Failed to save Smart Block.')}: ${getErrorAsString(err)}`);
    } finally {
        saving.value = false;
    }
};

const doDelete = async (): Promise<void> => {
    if (!props.playlistUrl) {
        return;
    }

    if (!window.confirm($gettext('Are you sure you want to delete this Smart Block? This cannot be undone.'))) {
        return;
    }

    saving.value = true;
    try {
        await axios.delete(props.playlistUrl);
        notifySuccess($gettext('Smart Block deleted.'));
        emit('deleted');
    } catch (err) {
        notifyError(`${$gettext('Failed to delete Smart Block.')}: ${getErrorAsString(err)}`);
    } finally {
        saving.value = false;
    }
};

watch(
    () => props.smartBlockUrl,
    () => {
        void loadEditor();
    },
);

onMounted(() => {
    void loadEditor();
});

defineExpose({reload: loadEditor});
</script>

<style lang="scss" scoped>
.min-w-0 {
    min-width: 0;
}
</style>
