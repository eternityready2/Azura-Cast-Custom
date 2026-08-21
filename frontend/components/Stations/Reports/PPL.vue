<template>
    <licensing-report
        kind="ppl"
        :title="$gettext('PPL Webcasting Report')"
        :subtitle="$gettext('Generate licensing reports for UK webcasters')"
        :about-title="$gettext('About PPL Reports')"
        :description="$gettext('This report generates the two key figures required by PPL (Phonographic Performance Limited) for UK internet radio webcasting licences: the average number of music tracks played per hour and the total listener hours for the reporting period.')"
        :notes="notes"
        :button-label="$gettext('Download PPL Report')"
        :generate-title="$gettext('Generate Report')"
        :generate-subtitle="$gettext('Configure the reporting period and select your music playlists')"
        :show-playlist-selector="true"
        :playlist-label="$gettext('Music Playlists')"
        :playlist-help="$gettext('Select the playlists that contain music (sound recordings). Non-music playlists such as talk, jingles and news should not be selected.')"
    />
</template>

<script setup lang="ts">
import LicensingReport from "~/components/Stations/Reports/LicensingReport.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

const notes = [
    $gettext('The PPL Webcasting Report requires two figures: the average number of tracks webcast per hour and the total listener hours (TLH) for the period. These are multiplied to calculate your total performances.'),
    $gettext('Select only the playlists that contain music (sound recordings) below. Talk, jingle, and news playlists should not be selected, as they are not PPL-licensed content.'),
    $gettext('Tracks played by live DJs or streamers are automatically included in the report alongside AutoDJ tracks. The downloaded CSV shows a breakdown of both sources.'),
    $gettext('Total Listener Hours are calculated from your station listener session data. Ensure your analytics level is set to collect listener data for accurate results.'),
    $gettext('Always verify the generated report before submitting to PPL. Listener counts from external aggregators are not included and may need to be added manually.')
];
</script>
