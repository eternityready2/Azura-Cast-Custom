<template>
    <span
        class="playlist-source-icon d-inline-flex align-items-center"
        aria-hidden="true"
    >
        <template v-if="source === PlaylistSources.Songs">
            <icon-ic-library-music />
        </template>
        <template v-else-if="source === PlaylistSources.Playlists">
            <icon-ic-queue-music />
        </template>
        <template v-else-if="source === PlaylistSources.Requests">
            <icon-bi-people />
        </template>
        <template v-else-if="source === PlaylistSources.RemoteUrl">
            <icon-ic-public />
        </template>
    </span>
</template>

<script setup lang="ts">
import {PlaylistSources} from "~/entities/ApiInterfaces.ts";
import IconBiPeople from "~icons/bi/people";
import IconIcLibraryMusic from "~icons/ic/baseline-library-music";
import IconIcPublic from "~icons/ic/baseline-public";
import IconIcQueueMusic from "~icons/ic/baseline-queue-music";

defineProps<{
    source: string;
}>();
</script>
