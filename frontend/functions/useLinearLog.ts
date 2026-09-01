import {computed, onMounted, onUnmounted, ref} from "vue";
import type {
    LinearLogAiDjShift,
    LinearLogGap,
    LinearLogItem,
    LinearLogResponse,
    LinearLogStatus,
} from "~/entities/LinearLog";
import {useApiRouter} from "~/functions/useApiRouter";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";

function errorMessage(error: unknown, fallback: string): string {
    if (typeof error !== "object" || error === null) {
        return fallback;
    }

    const candidate = error as {
        message?: string;
        response?: {data?: {message?: string}};
    };

    return candidate.response?.data?.message ?? candidate.message ?? fallback;
}

export function useLinearLog() {
    const {$gettext} = useTranslate();
    const {axios} = useAxios();
    const {getStationApiUrl} = useApiRouter();

    const statusUrl = getStationApiUrl("/reports/linear-log");
    const buildUrl = getStationApiUrl("/reports/linear-log/build");

    const initialLoading = ref(true);
    const buildError = ref("");
    const status = ref<LinearLogStatus>("idle");
    const featureEnabled = ref(true);
    const hoursAhead = ref(24);
    const snapshotHours = ref(24);
    const builtAt = ref<number | null>(null);
    const coverageStart = ref<number | null>(null);
    const coverageEnd = ref<number | null>(null);
    const allItems = ref<LinearLogItem[]>([]);
    const gaps = ref<LinearLogGap[]>([]);
    const aiDjShifts = ref<LinearLogAiDjShift[]>([]);
    const nowTs = ref(Math.floor(Date.now() / 1000));

    let initializedHours = false;
    let pollTimer: number | null = null;

    const isBuilding = computed(() => status.value === "queued" || status.value === "building");

    function clearPoll(): void {
        if (pollTimer !== null) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll(): void {
        clearPoll();
        pollTimer = window.setTimeout(() => void loadSnapshot(false), 2000);
    }

    async function loadSnapshot(showLoader = true): Promise<void> {
        if (showLoader && allItems.value.length === 0) {
            initialLoading.value = true;
        }

        try {
            const {data} = await axios.get<LinearLogResponse>(statusUrl.value);
            status.value = data.status;
            featureEnabled.value = data.enabled;
            snapshotHours.value = data.hours || data.configured_hours || 24;
            builtAt.value = data.built_at;
            coverageStart.value = data.coverage_start;
            coverageEnd.value = data.coverage_end;
            allItems.value = data.entries ?? [];
            gaps.value = data.gaps ?? [];
            aiDjShifts.value = data.ai_dj_shifts ?? [];
            buildError.value = data.error ?? "";
            nowTs.value = Math.floor(Date.now() / 1000);

            if (!initializedHours) {
                hoursAhead.value = data.hours || data.configured_hours || 24;
                initializedHours = true;
            }

            if (data.enabled && (data.status === "queued" || data.status === "building")) {
                schedulePoll();
            } else {
                clearPoll();
            }
        } catch (error: unknown) {
            clearPoll();
            buildError.value = errorMessage(error, $gettext("Unable to load the Linear Log."));
        } finally {
            initialLoading.value = false;
        }
    }

    async function requestBuild(): Promise<void> {
        if (!featureEnabled.value || isBuilding.value) {
            return;
        }

        clearPoll();
        buildError.value = "";
        status.value = "queued";

        try {
            await axios.post(buildUrl.value, {hours: hoursAhead.value});
            snapshotHours.value = hoursAhead.value;
            schedulePoll();
        } catch (error: unknown) {
            status.value = "failed";
            buildError.value = errorMessage(error, $gettext("Unable to queue the Linear Log build."));
        }
    }

    onMounted(() => void loadSnapshot());
    onUnmounted(clearPoll);

    return {
        initialLoading,
        buildError,
        status,
        featureEnabled,
        hoursAhead,
        snapshotHours,
        builtAt,
        coverageStart,
        coverageEnd,
        allItems,
        gaps,
        aiDjShifts,
        nowTs,
        isBuilding,
        loadSnapshot,
        requestBuild,
    };
}
