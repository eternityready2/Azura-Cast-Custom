<template>
    <licensing-report
        kind="ppca"
        :title="$gettext('PPCA Report')"
        :subtitle="$gettext('Generate licensing reports for Australian webcasters')"
        :about-title="$gettext('About PPCA Reports')"
        :description="$gettext('This report generates the three schedules required by PPCA for Australian internet radio streaming licence holders. Reports are submitted quarterly.')"
        :notes="notes"
        :buttons="buttons"
        :generate-title="$gettext('Generate Reports')"
        :generate-subtitle="$gettext('Configure the reporting period and options, then download each schedule')"
        :show-playlist-selector="true"
        :playlist-label="$gettext('PPCA Music Playlists')"
        :playlist-help="$gettext('Select the playlists that contain PPCA Sound Recordings. Non-music playlists such as talk, jingles and news should be left unchecked.')"
        :show-revenue="true"
    />
</template>

<script setup lang="ts">
import LicensingReport from "~/components/Stations/Reports/LicensingReport.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();

const notes = [
    $gettext('Schedule A (Recordings Report) tracks each song played with the number of unique listeners (streams) during the reporting period. The Record Label field is sourced from the Publisher (TPUB) ID3 tag in your media files.'),
    $gettext('If Record Label data is missing, re-scan your media files after ensuring the Publisher ID3 tag is set. You can trigger a re-scan from the Media Manager.'),
    $gettext('Schedule C (Music Use) calculates the percentage of broadcast time that is PPCA music. Select only the playlists that contain PPCA Sound Recordings below. Talk, news and jingle playlists should not be selected.'),
    $gettext('Always verify the generated reports before submitting to PPCA. Listener counts from external aggregators are not included and may need to be manually appended.')
];

const buttons = [
    {label: $gettext('Schedule A (Recordings)'), param: 'a'},
    {label: $gettext('Schedule B (Accounting)'), param: 'b'},
    {label: $gettext('Schedule C (Music Use)'), param: 'c'}
];
</script>
