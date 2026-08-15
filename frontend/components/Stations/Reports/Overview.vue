<template>
    <section
        class="card mb-4"
        role="region"
    >
        <div class="card-header text-bg-primary">
            <div class="d-flex align-items-center">
                <h2 class="card-title flex-fill my-0">
                    {{ $gettext('Station Statistics') }}
                </h2>
                <div class="flex-shrink">
                    <date-range-dropdown
                        v-model="dateRange"
                        :options="{
                            timeConfig: {
                                enableTimePicker: true
                            },
                            timezone: timezone
                        }"
                        class="btn-dark"
                    />
                </div>
            </div>
        </div>

        <div class="card-body">
            <tabs destroy-on-hide>
                <tab :label="$gettext('Best & Worst')">
                    <best-and-worst-tab
                        :api-url="bestAndWorstUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Listeners By Time Period')">
                    <listeners-by-time-period-tab
                        :api-url="listenersByTimePeriodUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Listening Time')">
                    <listening-time-tab
                        :api-url="listeningTimeUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Heatmap')">
                    <heatmap-tab
                        :api-url="heatmapUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Clock Performance')">
                    <clock-performance-tab
                        :api-url="clockPerformanceUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Top of Hour')">
                    <top-of-hour-performance-tab
                        :api-url="topOfHourPerformanceUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Playlist Performance')">
                    <playlist-performance-tab
                        :api-url="playlistPerformanceUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Dropouts')">
                    <dropout-tab
                        :api-url="dropoutUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Listener Insights')">
                    <listener-insights-tab
                        :api-url="listenerInsightsUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Growth Trend')">
                    <growth-trend-tab
                        :api-url="growthTrendUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Retention')">
                    <retention-curve-tab
                        :api-url="retentionCurveUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Daypart Audience')">
                    <daypart-audience-tab
                        :api-url="daypartAudienceUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab :label="$gettext('Health')">
                    <health-dashboard-tab :api-url="healthUrl" />
                </tab>

                <tab :label="$gettext('Streams')">
                    <streams-tab
                        :api-url="byStreamUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab
                    v-if="showFullAnalytics"
                    :label="$gettext('Clients')"
                >
                    <clients-tab
                        :api-url="byClientUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab
                    v-if="showFullAnalytics"
                    :label="$gettext('Browsers')"
                >
                    <browsers-tab
                        :api-url="byBrowserUrl"
                        :date-range="dateRange"
                    />
                </tab>

                <tab
                    v-if="showFullAnalytics"
                    :label="$gettext('Countries')"
                >
                    <countries-tab
                        :api-url="byCountryUrl"
                        :date-range="dateRange"
                    />
                </tab>
            </tabs>
        </div>
    </section>
</template>

<script setup lang="ts">
import DateRangeDropdown from "~/components/Common/DateRangeDropdown.vue";
import ListenersByTimePeriodTab from "~/components/Stations/Reports/Overview/ListenersByTimePeriodTab.vue";
import BestAndWorstTab from "~/components/Stations/Reports/Overview/BestAndWorstTab.vue";
import BrowsersTab from "~/components/Stations/Reports/Overview/BrowsersTab.vue";
import CountriesTab from "~/components/Stations/Reports/Overview/CountriesTab.vue";
import StreamsTab from "~/components/Stations/Reports/Overview/StreamsTab.vue";
import ClientsTab from "~/components/Stations/Reports/Overview/ClientsTab.vue";
import ListeningTimeTab from "~/components/Stations/Reports/Overview/ListeningTimeTab.vue";
import HeatmapTab from "~/components/Stations/Reports/Overview/HeatmapTab.vue";
import ClockPerformanceTab from "~/components/Stations/Reports/Overview/ClockPerformanceTab.vue";
import TopOfHourPerformanceTab from "~/components/Stations/Reports/Overview/TopOfHourPerformanceTab.vue";
import PlaylistPerformanceTab from "~/components/Stations/Reports/Overview/PlaylistPerformanceTab.vue";
import DropoutTab from "~/components/Stations/Reports/Overview/DropoutTab.vue";
import ListenerInsightsTab from "~/components/Stations/Reports/Overview/ListenerInsightsTab.vue";
import GrowthTrendTab from "~/components/Stations/Reports/Overview/GrowthTrendTab.vue";
import RetentionCurveTab from "~/components/Stations/Reports/Overview/RetentionCurveTab.vue";
import DaypartAudienceTab from "~/components/Stations/Reports/Overview/DaypartAudienceTab.vue";
import HealthDashboardTab from "~/components/Stations/Reports/Overview/HealthDashboardTab.vue";
import {ref} from "vue";
import Tabs from "~/components/Common/Tabs.vue";
import Tab from "~/components/Common/Tab.vue";
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";
import {useStationData} from "~/functions/useStationQuery.ts";
import {toRefs} from "@vueuse/core";
import {useAzuraCastDashboardGlobals} from "~/vendor/azuracast.ts";
import {AnalyticsLevel} from "~/entities/ApiInterfaces.ts";
import {useApiRouter} from "~/functions/useApiRouter.ts";

const {analyticsLevel} = useAzuraCastDashboardGlobals();
const showFullAnalytics = analyticsLevel === AnalyticsLevel.All;

const {getStationApiUrl} = useApiRouter();
const listenersByTimePeriodUrl = getStationApiUrl('/reports/overview/charts');
const bestAndWorstUrl = getStationApiUrl('/reports/overview/best-and-worst');
const byStreamUrl = getStationApiUrl('/reports/overview/by-stream');
const byBrowserUrl = getStationApiUrl('/reports/overview/by-browser');
const byCountryUrl = getStationApiUrl('/reports/overview/by-country');
const byClientUrl = getStationApiUrl('/reports/overview/by-client');
const listeningTimeUrl = getStationApiUrl('/reports/overview/by-listening-time');
const heatmapUrl = getStationApiUrl('/reports/overview/heatmap');
const clockPerformanceUrl = getStationApiUrl('/reports/overview/clock-performance');
const topOfHourPerformanceUrl = getStationApiUrl('/reports/overview/top-of-hour-performance');
const playlistPerformanceUrl = getStationApiUrl('/reports/overview/playlist-performance');
const dropoutUrl = getStationApiUrl('/reports/overview/dropout');
const listenerInsightsUrl = getStationApiUrl('/reports/overview/listener-insights');
const growthTrendUrl = getStationApiUrl('/reports/overview/growth-trend');
const retentionCurveUrl = getStationApiUrl('/reports/overview/retention-curve');
const daypartAudienceUrl = getStationApiUrl('/reports/overview/daypart-audience');
const healthUrl = getStationApiUrl('/reports/overview/health');

const stationData = useStationData();
const {timezone} = toRefs(stationData);

const {now} = useStationDateTimeFormatter();

const nowTz = now();
const dateRange = ref({
    startDate: nowTz.minus({days: 13}).toJSDate(),
    endDate: nowTz.toJSDate(),
});
</script>
