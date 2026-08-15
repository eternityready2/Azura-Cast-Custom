<template>
    <loading
        :loading="isLoading"
        lazy
    >
        <top-of-hour-compliance-section
            v-if="state"
            :compliance="state.compliance"
        />
    </loading>
</template>

<script setup lang="ts">
import {toRef} from "vue";
import {useAxios} from "~/vendor/axios";
import Loading from "~/components/Common/Loading.vue";
import {useLuxon} from "~/vendor/luxon";
import {DateRange} from "~/components/Stations/Reports/Overview/CommonMetricsView.vue";
import {useQuery} from "@tanstack/vue-query";
import {QueryKeys, queryKeyWithStation} from "~/entities/Queries.ts";
import TopOfHourComplianceSection from "~/components/Stations/Reports/Overview/TopOfHourComplianceSection.vue";

const props = defineProps<{
    dateRange: DateRange,
    apiUrl: string,
}>();

const dateRange = toRef(props, 'dateRange');
const {axios} = useAxios();
const {DateTime} = useLuxon();

type TopOfHourPerformanceData = {
    compliance: {
        tolerance_seconds: number,
        hours_with_legal_id: number,
        on_time_count: number,
        late_count: number,
        compliance_percent: number | null,
        fallback_count: number,
        late_events: Array<{
            expected_play_at: string,
            actual_play_at: string,
            drift_seconds: number,
        }>,
    },
};

const {data: state, isLoading} = useQuery<TopOfHourPerformanceData>({
    queryKey: queryKeyWithStation([
        QueryKeys.StationReports,
        'top_of_hour_performance',
        dateRange,
    ]),
    queryFn: async ({signal}) => {
        const {data} = await axios.get<TopOfHourPerformanceData>(props.apiUrl, {
            signal,
            params: {
                start: DateTime.fromJSDate(dateRange.value.startDate).toISO(),
                end: DateTime.fromJSDate(dateRange.value.endDate).toISO(),
            },
        });
        return data;
    },
});
</script>
