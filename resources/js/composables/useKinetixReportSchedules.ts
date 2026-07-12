import { usePage } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps, KinetixTableData } from '@/types';

/**
 * Live scheduled-reports table for `<KinetixReportSchedules>` — same shape as
 * `useKinetixReportRuns()`: fetch + poll a `Table`-built payload, with
 * enable/disable (`ToggleColumn`) and delete/run-now (`Action`) already
 * wired through the generic `<KinetixTable>` plumbing.
 */
export function useKinetixReportSchedules() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/report-schedules`;
    const interval = page.props.kinetix_reports_center?.poll ?? 5000;

    const table = ref<KinetixTableData | null>(null);
    const loading = ref(false);
    const failed = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;

    async function load(): Promise<void> {
        loading.value = true;

        try {
            table.value = await kinetixFetch<KinetixTableData>(base());
            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    function start(): void {
        void load();

        if (timer === null && interval > 0) {
            timer = setInterval(() => void load(), interval);
        }
    }

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    /**
     * Create a new scheduled report (report token, frequency, optional
     * parameters/enabled/notify-on-completion), then refresh the table.
     */
    async function create(payload: {
        report: string;
        frequency: string;
        parameters?: Record<string, unknown>;
        enabled?: boolean;
        notify_on_completion?: boolean;
    }): Promise<void> {
        await kinetixFetch(base(), { method: 'POST', body: payload });
        await load();
    }

    onUnmounted(stop);

    return { table, loading, failed, load, start, stop, create };
}
