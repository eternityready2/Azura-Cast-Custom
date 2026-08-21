<template>
    <licensing-report
        kind="cadence"
        :title="$gettext('NPR Cadence Streaming Report')"
        :subtitle="$gettext('Generate stream access reports for NPR Cadence')"
        :about-title="$gettext('About Cadence Streaming Reports')"
        :description="$gettext('This export produces the Streaming Report format requested by NPR Cadence: one file per stream with the required listener access fields, including IP address, date, time, stream, duration, status and referrer information.')"
        :notes="notes"
        :button-label="$gettext('Download Cadence Report')"
    />
</template>

<script setup lang="ts">
import LicensingReport from "~/components/Stations/Reports/LicensingReport.vue";
import {useTranslate} from "~/vendor/gettext";

const {$gettext} = useTranslate();
const notes = [
    $gettext("Dates use YYYY-MM-DD and times use 24-hour HH:MM:SS in your station time zone. Duration is reported in seconds."),
    $gettext("Status is recorded as 200 for completed listener sessions. Referrer uses the listener user agent captured for the stream session."),
    $gettext("Choose CSV for comma-separated output or tab-delimited text for systems that require a TXT file."),
    $gettext("Accurate results require listener analytics to be enabled. History is limited by your configured listener history retention period."),
];
</script>
