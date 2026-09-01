<template>
    <section v-for="group in groups" :key="group.epochHour" class="hour-group">
        <div class="hour-header" :class="{'current-hour': group.isCurrent}">
            <span v-if="group.isCurrent" class="badge text-bg-primary">{{ $gettext('NOW') }}</span>
            <strong>{{ group.label }}</strong>
            <span class="hour-summary">
                {{ group.items.length }} {{ $gettext('tracks') }} / {{ group.totalDurationFormatted }}
            </span>
            <span v-if="group.hasId" class="badge text-bg-danger ms-auto">{{ $gettext('Station ID') }}</span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 linear-table">
                <tbody>
                    <tr
                        v-for="item in group.items"
                        :key="item.id"
                        class="queue-row"
                        :class="rowClasses(item)"
                    >
                        <td v-if="visibleColumns.includes('time')" class="queue-time ps-3">
                            {{ formatTime(item.played_at) }}
                        </td>

                        <td v-if="visibleColumns.includes('title')" class="py-2">
                            <div class="d-flex align-items-start gap-2">
                                <span v-if="isNextUp(item)" class="next-marker">{{ $gettext('NEXT') }}</span>
                                <span v-else-if="item.is_live_queue" class="live-marker">{{ $gettext('LIVE QUEUE') }}</span>
                                <div>
                                    <div v-if="item.autodj_custom_uri" class="small text-body-secondary">
                                        {{ item.autodj_custom_uri }}
                                    </div>
                                    <template v-else>
                                        <strong class="track-title">{{ displayTitle(item) }}</strong>
                                        <div v-if="item.artist" class="small track-artist">{{ item.artist }}</div>
                                        <div v-if="item.album" class="small text-body-secondary">{{ item.album }}</div>
                                    </template>
                                </div>
                            </div>
                        </td>

                        <td v-if="visibleColumns.includes('source')" class="playlist-cell">
                            <div>{{ sourceLabel(item) }}</div>
                            <div v-if="item.playlist_chain?.length" class="small text-body-secondary">
                                {{ item.playlist_chain.join(' → ') }}
                            </div>
                        </td>

                        <td v-if="visibleColumns.includes('type')" class="type-cell">
                            <span :class="typeBadgeClass(item)">{{ typeLabel(item) }}</span>
                        </td>

                        <td v-if="visibleColumns.includes('rules')" class="rules-cell">
                            <span v-if="item.top_of_hour_legal_id" class="badge text-bg-danger me-1">TOH</span>
                            <span v-if="item.clock_wheel_enforce_cap" class="badge text-bg-secondary me-1">CAP</span>
                            <span v-if="item.clock_wheel_stretch_ratio" class="badge text-bg-info me-1">
                                {{ formatStretch(item.clock_wheel_stretch_ratio) }}
                            </span>
                            <span v-if="item.hour_boundary_enforce_cap" class="badge text-bg-warning me-1">BOUNDARY</span>
                            <span v-if="item.is_request" class="badge text-bg-primary">REQUEST</span>
                        </td>

                        <td v-if="visibleColumns.includes('duration')" class="duration-cell pe-3">
                            {{ formatDuration(item.duration) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup lang="ts">
import type {LinearLogHourGroup, LinearLogItem} from "~/entities/LinearLog";
import {useTranslate} from "~/vendor/gettext";

const props = defineProps<{
    groups: LinearLogHourGroup[];
    visibleColumns: string[];
    nowTs: number;
}>();

const {$gettext} = useTranslate();

function isNextUp(item: LinearLogItem): boolean {
    return (item.played_at ?? 0) >= props.nowTs && item.is_live_queue;
}

function displayTitle(item: LinearLogItem): string {
    return item.title || item.text || $gettext("Untitled");
}

function sourceLabel(item: LinearLogItem): string {
    if (item.clock_wheel) return item.clock_wheel;
    if (item.playlist) return item.playlist;
    if (item.is_request) return $gettext("Listener Request");
    if (item.autodj_custom_uri) return $gettext("Remote Stream");
    return $gettext("General Rotation");
}

function resolveType(item: LinearLogItem): string {
    if (item.is_request) return "request";
    if (item.clock_wheel) return "clock_wheel";
    if (item.top_of_hour_legal_id || item.media_type === "id") return "id";
    if (item.autodj_custom_uri) return "stream";
    return item.media_type || "music";
}

function typeLabel(item: LinearLogItem): string {
    const labels: Record<string, string> = {
        music: $gettext("Music"),
        talk: $gettext("Talk"),
        id: $gettext("ID"),
        promo: $gettext("Promo"),
        jingle: $gettext("Jingle"),
        podcast: $gettext("Podcast"),
        stream: $gettext("Stream"),
        request: $gettext("Request"),
        clock_wheel: $gettext("Clock"),
    };
    return labels[resolveType(item)] ?? $gettext("Music");
}

function typeBadgeClass(item: LinearLogItem): string {
    const classes: Record<string, string> = {
        music: "badge text-bg-success",
        talk: "badge text-bg-warning",
        id: "badge text-bg-danger",
        promo: "badge text-bg-info",
        jingle: "badge text-bg-secondary",
        podcast: "badge text-bg-primary",
        stream: "badge text-bg-dark",
        request: "badge text-bg-primary",
        clock_wheel: "badge text-bg-primary",
    };
    return classes[resolveType(item)] ?? "badge text-bg-success";
}

function rowClasses(item: LinearLogItem): Record<string, boolean> {
    return {
        "next-up": isNextUp(item),
        "legal-id": item.top_of_hour_legal_id,
        "live-queue": item.is_live_queue,
    };
}

function formatTime(timestamp: number | null): string {
    if (!timestamp) return "-";
    return new Date(timestamp * 1000).toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
    });
}

function formatDuration(seconds: number): string {
    const minutes = Math.floor((seconds ?? 0) / 60);
    const remain = Math.floor((seconds ?? 0) % 60);
    return `${minutes}:${String(remain).padStart(2, "0")}`;
}

function formatStretch(ratio: number): string {
    return `${(ratio * 100).toFixed(1)}%`;
}
</script>

<style scoped>
.hour-header{display:flex;align-items:center;gap:.6rem;padding:.62rem 1rem;border-bottom:1px solid var(--bs-border-color);background:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.hour-header.current-hour{background:linear-gradient(90deg,color-mix(in srgb,var(--bs-primary) 18%,var(--bs-body-bg)),color-mix(in srgb,var(--bs-primary) 8%,var(--bs-body-bg)));box-shadow:inset 3px 0 0 var(--bs-primary)}
.hour-summary{color:var(--bs-secondary-color);font-size:.76rem}
.linear-table{--bs-table-color:var(--bs-body-color);--bs-table-bg:var(--bs-body-bg);--bs-table-hover-color:var(--bs-body-color);--bs-table-hover-bg:color-mix(in srgb,var(--bs-secondary-bg) 72%,var(--bs-body-bg))}
.queue-row td{border-color:var(--bs-border-color)}
.queue-row.next-up td{background:color-mix(in srgb,var(--bs-success-bg-subtle) 34%,var(--bs-body-bg))}
.queue-row.legal-id td{background:color-mix(in srgb,var(--bs-danger-bg-subtle) 28%,var(--bs-body-bg))}
.queue-row.live-queue td{box-shadow:inset 2px 0 0 color-mix(in srgb,var(--bs-success) 55%,transparent)}
.queue-time,.duration-cell{font-family:var(--bs-font-monospace);font-size:.76rem;white-space:nowrap}
.queue-time{width:100px}
.duration-cell{width:72px;text-align:right}
.playlist-cell{width:210px;font-size:.79rem}
.type-cell{width:95px}
.rules-cell{width:185px}
.track-title{color:var(--bs-body-color)}
.track-artist{color:var(--bs-secondary-color)!important}
.next-marker,.live-marker{display:inline-block;padding:.16rem .32rem;border-radius:.28rem;color:#fff;font-size:.58rem;font-weight:750;letter-spacing:.035em;white-space:nowrap}
.next-marker{background:var(--bs-success)}
.live-marker{background:var(--bs-secondary)}
@media(max-width:767px){.rules-cell{min-width:160px}}
</style>
