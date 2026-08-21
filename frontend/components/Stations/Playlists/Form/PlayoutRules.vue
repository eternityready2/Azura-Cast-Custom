<template>
    <tab :label="$gettext('Playout Rules')">
        <div class="rules-intro mb-3">
            <strong>{{ $gettext('Playout Engine Settings') }}</strong>
            <span>{{ $gettext('Set scheduling priority, block playback behavior and sponsor guarantees in one place. Basic playlist source, type, order and rotation controls remain on Basic Info.') }}</span>
        </div>

        <form-fieldset>
            <template #label>{{ $gettext('Playout Priority') }}</template>

            <div class="priority-options">
                <label
                    v-for="option in priorityOptions"
                    :key="option.value"
                    class="priority-option"
                    :class="{'is-active': priority === option.value}"
                >
                    <input v-model="priority" class="form-check-input" type="radio" :value="option.value">
                    <span class="option-copy">
                        <strong>{{ option.title }}</strong>
                        <small>{{ option.description }}</small>
                    </span>
                </label>
            </div>

            <label class="behavior-option mt-2" :class="{'is-active': prioritizeRequests}">
                <input v-model="prioritizeRequests" class="form-check-input" type="checkbox">
                <span>
                    <strong>{{ $gettext('Prioritize Over Listener Requests') }}</strong>
                    <small>{{ $gettext('Prioritize this playlist over listener requests.') }}</small>
                </span>
            </label>

            <div class="rules-help">
                {{ $gettext('Control how this playlist is handled by the AutoDJ software.') }}
            </div>
        </form-fieldset>

        <form-fieldset>
            <template #label>{{ $gettext('Playback Behavior') }}</template>

            <div class="behavior-options">
                <label class="behavior-option" :class="{'is-active': singleTrack}">
                    <input v-model="singleTrack" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Only Play One Track') }}</strong>
                        <small>{{ $gettext('At each scheduled start, play one track from this playlist instead of running the whole playlist block.') }}</small>
                    </span>
                </label>

                <label class="behavior-option" :class="{'is-active': mergeTracks}">
                    <input v-model="mergeTracks" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Merge All Tracks') }}</strong>
                        <small>{{ $gettext('Treat all tracks in this playlist as one continuous block. Useful for multi-part programmes and long-form content.') }}</small>
                    </span>
                </label>

                <label class="behavior-option" :class="{'is-active': allowOverrun}">
                    <input v-model="allowOverrun" class="form-check-input" type="checkbox">
                    <span>
                        <strong>{{ $gettext('Allow Schedule Overrun') }}</strong>
                        <small>{{ $gettext('If a track is still playing when the scheduled window ends, let it finish before returning to normal rotation. Leave off for a strict schedule boundary.') }}</small>
                    </span>
                </label>
            </div>
        </form-fieldset>

        <form-fieldset>
            <template #label>{{ $gettext('Sponsor Guaranteed Playout') }}</template>

            <label class="behavior-option sponsor-toggle" :class="{'is-active': form.is_sponsor}">
                <input v-model="form.is_sponsor" class="form-check-input" type="checkbox">
                <span>
                    <strong>{{ $gettext('This is a sponsor/paid ad spot') }}</strong>
                    <small>{{ $gettext('When enabled, AzuraCast guarantees this playlist gets its required number of plays each day -- never silently skipped by normal rotation, the same way the legal ID is guaranteed at the top of the hour. Shows up in the Sponsor Play Report for proof-of-delivery.') }}</small>
                </span>
            </label>

            <div v-if="form.is_sponsor" class="row g-3 mt-1">
                <form-group-field
                    id="edit_form_sponsor_name"
                    class="col-md-6"
                    :field="r$.sponsor_name"
                    :label="$gettext('Sponsor Name')"
                    :description="$gettext('Shown on the Sponsor Play Report. Defaults to the playlist name if left blank.')"
                />

                <form-group-field
                    id="edit_form_sponsor_guaranteed_plays_per_day"
                    class="col-md-6"
                    :field="r$.sponsor_guaranteed_plays_per_day"
                    type="number"
                    :label="$gettext('Guaranteed Plays Per Day')"
                    :description="$gettext('Minimum number of times this sponsor spot should air each day. Leave empty to track plays without enforcing a minimum.')"
                />
            </div>
        </form-fieldset>
    </tab>
</template>

<script setup lang="ts">
import {computed} from "vue";
import {storeToRefs} from "pinia";
import FormFieldset from "~/components/Form/FormFieldset.vue";
import FormGroupField from "~/components/Form/FormGroupField.vue";
import Tab from "~/components/Common/Tab.vue";
import {useStationsPlaylistsForm} from "~/components/Stations/Playlists/Form/form";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const {form, r$} = storeToRefs(useStationsPlaylistsForm());

const hasOption = (option: string) => form.value.backend_options.includes(option);

const setOption = (option: string, enabled: boolean) => {
    const options = form.value.backend_options.filter((item) => item !== option);

    if (enabled) {
        options.push(option);
    }

    form.value.backend_options = options;
};

const priority = computed({
    get: () => {
        if (hasOption("interrupt") && hasOption("prioritize")) {
            return "priority";
        }

        if (hasOption("interrupt")) {
            return "programme";
        }

        return "rotation";
    },
    set: (value: string) => {
        setOption("interrupt", "rotation" !== value);

        if ("priority" === value) {
            setOption("prioritize", true);
        }
    },
});

const prioritizeRequests = computed({
    get: () => hasOption("prioritize"),
    set: (value: boolean) => setOption("prioritize", value),
});

const singleTrack = computed({
    get: () => hasOption("single_track"),
    set: (value: boolean) => setOption("single_track", value),
});

const mergeTracks = computed({
    get: () => hasOption("merge"),
    set: (value: boolean) => setOption("merge", value),
});

const allowOverrun = computed({
    get: () => hasOption("allow_overrun"),
    set: (value: boolean) => setOption("allow_overrun", value),
});

const priorityOptions = [
    {
        value: "rotation",
        title: $gettext("Rotation"),
        description: $gettext("Fills gaps between scheduled programming without interrupting the current song. Best for general music rotation and background playlists."),
    },
    {
        value: "programme",
        title: $gettext("Programme"),
        description: $gettext("Interrupt other songs to play at scheduled time. Best for regular shows and prerecorded programmes."),
    },
    {
        value: "priority",
        title: $gettext("Priority / News"),
        description: $gettext("Interrupt other songs to play at scheduled time and prioritize over listener requests. Best for news, alerts and time-sensitive content."),
    },
];
</script>

<style scoped>
.rules-intro {
    padding: .9rem 1rem;
    border: 1px solid #4e67a3;
    border-radius: .7rem;
    background: linear-gradient(90deg, #182642, #24264b);
}

.rules-intro strong,
.rules-intro span {
    display: block;
}

.rules-intro strong {
    color: #edf3ff;
    font-size: .9rem;
}

.rules-intro span {
    margin-top: .22rem;
    color: #aebbd2;
    font-size: .76rem;
    line-height: 1.45;
}

.priority-options,
.behavior-options {
    display: grid;
    gap: .6rem;
}

.priority-option,
.behavior-option {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .85rem .9rem;
    margin: 0;
    border: 1px solid #66738b;
    border-radius: .65rem;
    background: #293340;
    cursor: pointer;
}

.priority-option.is-active,
.behavior-option.is-active {
    border-color: #2688ff;
    background: #182b42;
    box-shadow: 0 0 0 .1rem rgba(38, 136, 255, .16);
}

.priority-option input,
.behavior-option input {
    margin-top: .2rem;
    accent-color: #2688ff;
}

.option-copy strong,
.option-copy small,
.behavior-option strong,
.behavior-option small {
    display: block;
}

.option-copy strong,
.behavior-option strong {
    color: #eef4ff;
    font-size: .84rem;
}

.option-copy small,
.behavior-option small {
    margin-top: .17rem;
    color: #b7c1d3;
    line-height: 1.45;
}

.rules-help {
    margin-top: .65rem;
    color: #96a5bf;
    font-size: .72rem;
}

.sponsor-toggle {
    margin-bottom: .4rem;
}
</style>
