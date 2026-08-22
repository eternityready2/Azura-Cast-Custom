
<template>
    <tab
        id="basic_info"
        :label="$gettext('Basic Info')"
        :item-header-class="tabClass"
    >
        <div
            v-if="form.is_smart_block"
            class="alert alert-info d-flex align-items-center gap-2 mb-3"
        >
            <span>
                {{ $gettext('This playlist\'s membership is auto-managed by a Smart Block. Its filter criteria are edited on the dedicated') }}
                <router-link :to="{name: 'stations:smart_blocks:index'}">
                    {{ $gettext('Smart Blocks') }}
                </router-link>
                {{ $gettext('page — the fields below (weight, scheduling, etc.) still apply normally.') }}
            </span>
        </div>

        <div class="row g-3 mb-3">
            <form-group-field
                id="form_edit_name"
                class="col-md-6"
                :field="r$.name"
                :label="$gettext('Playlist Name')"
            />

            <form-group-checkbox
                id="form_edit_is_enabled"
                class="col-md-6"
                :field="r$.is_enabled"
                :label="$gettext('Enable')"
                :description="$gettext('If disabled, the playlist will not be included in radio playback, but can still be managed.')"
            />

            <form-group-field
                id="form_edit_description"
                class="col-md-12"
                :field="r$.description"
                input-type="textarea"
                :label="$gettext('Description')"
                :description="$gettext('An optional description to help identify this playlist.')"
            />

            <form-group-multi-check
                v-if="!form.is_smart_block"
                id="edit_form_source"
                class="col-md-12"
                :field="r$.source"
                :options="sourceOptions"
                stacked
                radio
                :label="$gettext('Source')"
            />

            <div
                v-else
                class="col-md-12"
            >
                <label class="form-label">{{ $gettext('Source') }}</label>
                <p class="text-muted mb-0">
                    {{ $gettext('Song-Based (locked — Smart Blocks always pull from your media library).') }}
                </p>
            </div>
        </div>

        <template v-if="form.source === 'songs'">
            <div class="bg-primary text-bg-primary p-3 mb-3">
                <h2 class="h5 mb-0">
                    {{ $gettext('Song-Based Playlist') }}
                </h2>
            </div>

            <div class="row g-3 mb-3">
                <form-group-checkbox
                    id="form_edit_avoid_duplicates"
                    class="col-md-6"
                    :field="r$.avoid_duplicates"
                    :label="$gettext('Avoid Duplicate Artists/Titles')"
                    :description="$gettext('Whether the AutoDJ should attempt to avoid duplicate artists and track titles when playing media from this playlist.')"
                />

                <form-group-checkbox
                    id="form_edit_include_in_on_demand"
                    class="col-md-6"
                    :field="r$.include_in_on_demand"
                    :label="$gettext('Include in On-Demand Player')"
                    :description="$gettext('If this station has on-demand streaming and downloading enabled, only songs that are in playlists with this setting enabled will be visible.')"
                />

                <form-group-checkbox
                    id="form_edit_include_in_requests"
                    class="col-md-6"
                    :field="r$.include_in_requests"
                    :label="$gettext('Allow Requests from This Playlist')"
                    :description="$gettext('If requests are enabled for your station, users will be able to request media that is on this playlist.')"
                />

                <form-group-checkbox
                    id="form_edit_is_jingle"
                    class="col-md-6"
                    :field="r$.is_jingle"
                    :description="$gettext('Enable this setting to prevent metadata from being sent to the AutoDJ for files in this playlist. This is useful if the playlist contains jingles or bumpers.')"
                >
                    <template #label>
                        {{ $gettext('Hide Metadata from Listeners ("Jingle Mode")') }}
                    </template>
                </form-group-checkbox>

                <form-group-multi-check
                    id="edit_form_type"
                    class="col-md-6"
                    :field="r$.type"
                    :options="typeOptions"
                    stacked
                    radio
                    :label="$gettext('Playlist Type')"
                >
                    <template #description>
                        <a
                            href="/docs/user-guide/playlists/#advanced-playlists"
                            target="_blank"
                        >
                            {{ $gettext('Learn about Advanced Playlists') }}
                        </a>
                    </template>
                </form-group-multi-check>

                <form-group-multi-check
                    id="edit_form_order"
                    class="col-md-6"
                    :field="r$.order"
                    :options="orderOptions"
                    stacked
                    radio
                    :label="$gettext('Song Playback Order')"
                />

                <form-group-field
                    id="form_edit_rotation_goal_days"
                    class="col-md-6"
                    :field="r$.rotation_goal_days"
                    type="number"
                    :label="$gettext('Rotation goal (days)')"
                    :description="$gettext('Minimum days before the same track can play again from this playlist. Leave empty to disable.')"
                />

                <form-group-field
                    id="form_edit_aging_threshold_days"
                    class="col-md-6"
                    :field="r$.aging_threshold_days"
                    type="number"
                    :label="$gettext('Library aging threshold (days)')"
                    :description="$gettext('Gradually boost tracks that haven\'t played in this many days to the front of the rotation. Distinct from Rotation Goal\'s hard floor. Leave empty to disable.')"
                />

                <form-group-field
                    id="form_edit_crossfade_profile"
                    class="col-md-6"
                    :field="r$.crossfade_profile"
                    :label="$gettext('Crossfade profile')"
                    :description="$gettext('Optional named profile from Crossfade Profiles settings. Leave empty for station default matrix.')"
                />
            </div>

            <form-fieldset v-show="form.type === 'default'">
                <template #label>
                    {{ $gettext('General Rotation') }}
                </template>

                <div class="row g-3">
                    <form-group-select
                        id="form_edit_weight"
                        class="col-md-12"
                        :field="r$.weight"
                        :options="weightOptions"
                        :label="$gettext('Playlist Weight')"
                        :description="$gettext('Playlists with larger number weights (i.e. 25) play more frequently than playlists with smaller number weights (i.e. 1).')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_x_songs'">
                <template #label>
                    {{ $gettext('Once per x Songs') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_songs"
                        class="col-md-12"
                        :field="r$.play_per_songs"
                        input-type="number"
                        :input-attrs="{min: '0', max: '150'}"
                        :label="$gettext('Number of Songs Between Plays')"
                        :description="$gettext('This playlist will play every $x songs, where $x is specified here.')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_x_minutes'">
                <template #label>
                    {{ $gettext('Once per x Minutes') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_minutes"
                        class="col-md-12"
                        :field="r$.play_per_minutes"
                        input-type="number"
                        :input-attrs="{min: '0', max: '360'}"
                        :label="$gettext('Number of Minutes Between Plays')"
                        :description="$gettext('This playlist will play every $x minutes, where $x is specified here.')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_hour'">
                <template #label>
                    {{ $gettext('Once per Hour') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_hour_minute"
                        class="col-md-12"
                        :field="r$.play_per_hour_minute"
                        input-type="number"
                        :input-attrs="{min: '0', max: '59'}"
                        :label="$gettext('Minute of Hour to Play')"
                        :description="$gettext('Specify the minute of every hour that this playlist should play.')"
                    />
                </div>
            </form-fieldset>
        </template>

        <template v-if="form.source === 'playlists'">
            <div class="bg-primary text-bg-primary p-3 mb-3">
                <h2 class="h5 mb-0">
                    {{ $gettext('Playlist Group') }}
                </h2>
            </div>

            <div class="row g-3 mb-3">
                <form-group-checkbox
                    id="form_edit_avoid_duplicates_group"
                    class="col-md-6"
                    :field="r$.avoid_duplicates"
                    :label="$gettext('Avoid Duplicate Artists/Titles')"
                    :description="$gettext('Applies to all member playlists in this group unless a member overrides it.')"
                />
            </div>

            <p
                v-if="isExistingRecord"
                class="mb-3 text-muted"
            >
                {{ $gettext('Save this playlist, then use the Playlist Grouping interface to add or reorder member playlists.') }}
            </p>
            <p
                v-else
                class="mb-3 text-muted"
            >
                {{ $gettext('Save this playlist first, then use the Playlist Grouping interface to add member playlists.') }}
            </p>
        </template>

        <template v-if="form.source === 'playlists' || form.source === 'requests'">
            <div class="bg-primary text-bg-primary p-3 mb-3">
                <h2 class="h5 mb-0">
                    {{ $gettext('Scheduling') }}
                </h2>
            </div>

            <div class="row g-3 mb-3">
                <form-group-multi-check
                    id="edit_form_type_group"
                    class="col-md-6"
                    :field="r$.type"
                    :options="typeOptions"
                    stacked
                    radio
                    :label="$gettext('Playlist Type')"
                >
                    <template #description>
                        <a
                            href="/docs/user-guide/playlists/#advanced-playlists"
                            target="_blank"
                        >
                            {{ $gettext('Learn about Advanced Playlists') }}
                        </a>
                    </template>
                </form-group-multi-check>
            </div>

            <form-fieldset v-show="form.type === 'default'">
                <template #label>
                    {{ $gettext('General Rotation') }}
                </template>

                <div class="row g-3">
                    <form-group-select
                        id="form_edit_weight_group"
                        class="col-md-12"
                        :field="r$.weight"
                        :options="weightOptions"
                        :label="$gettext('Playlist Weight')"
                        :description="$gettext('Playlists with larger number weights (i.e. 25) play more frequently than playlists with smaller number weights (i.e. 1).')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_x_songs'">
                <template #label>
                    {{ $gettext('Once per x Songs') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_songs_group"
                        class="col-md-12"
                        :field="r$.play_per_songs"
                        input-type="number"
                        :input-attrs="{min: '0', max: '150'}"
                        :label="$gettext('Number of Songs Between Plays')"
                        :description="$gettext('This playlist will play every $x songs, where $x is specified here.')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_x_minutes'">
                <template #label>
                    {{ $gettext('Once per x Minutes') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_minutes_group"
                        class="col-md-12"
                        :field="r$.play_per_minutes"
                        input-type="number"
                        :input-attrs="{min: '0', max: '360'}"
                        :label="$gettext('Number of Minutes Between Plays')"
                        :description="$gettext('This playlist will play every $x minutes, where $x is specified here.')"
                    />
                </div>
            </form-fieldset>

            <form-fieldset v-show="form.type === 'once_per_hour'">
                <template #label>
                    {{ $gettext('Once per Hour') }}
                </template>

                <div class="row g-3">
                    <form-group-field
                        id="form_edit_play_per_hour_minute_group"
                        class="col-md-12"
                        :field="r$.play_per_hour_minute"
                        input-type="number"
                        :input-attrs="{min: '0', max: '59'}"
                        :label="$gettext('Minute of Hour to Play')"
                        :description="$gettext('Specify the minute of every hour that this playlist should play.')"
                    />
                </div>
            </form-fieldset>
        </template>

        <template v-if="form.source === 'remote_url'">
            <div class="bg-primary text-bg-primary p-3 mb-3">
                <h2 class="h5 mb-0">
                    {{ $gettext('Remote URL Playlist') }}
                </h2>
            </div>

            <div class="row g-3">
                <form-group-field
                    id="form_edit_remote_url"
                    class="col-md-6"
                    :field="r$.remote_url"
                    :label="$gettext('Remote URL')"
                />

                <form-group-multi-check
                    id="edit_form_remote_type"
                    class="col-md-6"
                    :field="r$.remote_type"
                    :options="remoteTypeOptions"
                    stacked
                    radio
                    :label="$gettext('Remote URL Type')"
                />

                <form-group-field
                    id="form_edit_remote_buffer"
                    class="col-md-6"
                    :field="r$.remote_buffer"
                    input-type="number"
                    :input-attrs="{ min: 0, max: 120 }"
                    :label="$gettext('Remote Playback Buffer (Seconds)')"
                    :description="$gettext('The length of playback time that Liquidsoap should buffer when playing this remote playlist. Shorter times may lead to intermittent playback on unstable connections.')"
                />
            </div>
        </template>
    </tab>
</template>

<script setup lang="ts">
import FormGroupField from "~/components/Form/FormGroupField.vue";
import FormGroupCheckbox from "~/components/Form/FormGroupCheckbox.vue";
import FormFieldset from "~/components/Form/FormFieldset.vue";
import {map, range} from "es-toolkit/compat";
import FormGroupMultiCheck from "~/components/Form/FormGroupMultiCheck.vue";
import FormGroupSelect from "~/components/Form/FormGroupSelect.vue";
import {useTranslate} from "~/vendor/gettext";
import Tab from "~/components/Common/Tab.vue";
import {storeToRefs} from "pinia";
import {useFormTabClass} from "~/functions/useFormTabClass.ts";
import {computed} from "vue";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form.ts";

const {r$, form} = storeToRefs(useStationsPlaylistsForm());

const tabClass = useFormTabClass(computed(() => r$.value.$groups.basicInfoTab));

const {$gettext} = useTranslate();

const sourceOptions = [
    {
        value: 'songs',
        text: $gettext('Song-Based'),
        description: $gettext('A playlist containing media files hosted on this server.')
    },
    {
        value: 'playlists',
        text: $gettext('Playlist Group'),
        description: $gettext('A playlist containing other playlists.')
    },
    {
        value: 'requests',
        text: $gettext('Request Queue'),
        description: $gettext('Pulls the next pending listener request when this playlist\'s rotation slot comes up.')
    },
];

const typeOptions = [
    {
        value: 'default',
        text: $gettext('General Rotation'),
        description: $gettext('Standard playlist, shuffles with other standard playlists based on weight.')
    },
    {
        value: 'once_per_x_songs',
        text: $gettext('Once per x Songs'),
        description: $gettext('Play once every $x songs.')
    },
    {
        value: 'once_per_x_minutes',
        text: $gettext('Once per x Minutes'),
        description: $gettext('Play once every $x minutes.')
    },
    {
        value: 'once_per_hour',
        text: $gettext('Once per Hour'),
        description: $gettext('Play once per hour at the specified minute.')
    },
    {
        value: 'custom',
        text: $gettext('Advanced'),
        description: $gettext('Manually define how this playlist is used in Liquidsoap configuration.')
    }
];

const orderOptions = [
    {
        value: 'shuffle',
        text: $gettext('Shuffled'),
        description: $gettext('The full playlist is shuffled and then played through in the shuffled order.')
    },
    {
        value: 'random',
        text: $gettext('Random'),
        description: $gettext('A completely random track is picked for playback every time the queue is populated.')
    },
    {
        value: 'sequential',
        text: $gettext('Sequential'),
        description: $gettext('The order of the playlist is manually specified and followed by the AutoDJ.')
    }
];

const isExistingRecord = computed(() => !!form.value.id);

const remoteTypeOptions = [
    {
        value: 'stream',
        text: $gettext('Icecast/Shoutcast Stream URL')
    },
    {
        value: 'playlist',
        text: $gettext('Playlist (M3U/PLS) URL')
    },
    {
        value: 'other',
        text: $gettext('Other Remote URL (File, HLS, etc.)')
    }
];

const weightOptions = map(
    range(1, 26),
    (val) => {
        return {
            value: val,
            text: String(val)
        }
    }
);
</script>
