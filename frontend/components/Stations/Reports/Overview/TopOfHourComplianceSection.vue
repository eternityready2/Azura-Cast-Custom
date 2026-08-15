<template>
    <fieldset>
        <legend>
            {{ $gettext('Top of Hour compliance (station-wide)') }}
            <span class="text-muted fw-normal small">
                ({{ $gettext('tolerance') }}: {{ compliance.tolerance_seconds }}s)
            </span>
        </legend>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">
                        {{ compliance.compliance_percent ?? '—' }}<span
                            v-if="compliance.compliance_percent != null"
                            class="fs-6"
                        >%</span>
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('On time') }}
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold">
                        {{ compliance.on_time_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Compliant hours') }}
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold text-warning">
                        {{ compliance.late_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Late (> tolerance)') }}
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border rounded p-2 text-center">
                    <div class="fs-4 fw-semibold text-secondary">
                        {{ compliance.fallback_count }}
                    </div>
                    <div class="small text-muted">
                        {{ $gettext('Fallback events') }}
                    </div>
                </div>
            </div>
        </div>

        <ul
            v-if="compliance.late_events.length > 0"
            class="list-group list-group-flush mb-3"
        >
            <li
                v-for="(event, index) in compliance.late_events"
                :key="index"
                class="list-group-item px-0 small"
            >
                {{ $gettext('Expected') }}: {{ formatDateTime(event.expected_play_at) }}
                · {{ $gettext('Actual') }}: {{ formatDateTime(event.actual_play_at) }}
                · {{ $gettext('Drift') }}: {{ event.drift_seconds }}s
            </li>
        </ul>
    </fieldset>
</template>

<script setup lang="ts">
import useStationDateTimeFormatter from "~/functions/useStationDateTimeFormatter.ts";

defineProps<{
    compliance: {
        tolerance_seconds: number,
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
}>();

const {formatIsoAsDateTime} = useStationDateTimeFormatter();

function formatDateTime(value: string): string {
    return formatIsoAsDateTime(value) || '—';
}
</script>
