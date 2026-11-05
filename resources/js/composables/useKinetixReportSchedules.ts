import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixPolling } from '@/composables/useKinetixPolling';
import type { KinetixSharedProps, KinetixTableData } from '@/types/kinetix';

/**
 * Live scheduled-reports table for `<KinetixReportSchedules>` — same shape as
 * `useKinetixReportRuns()`: fetch + poll a `Table`-built payload, with
 * enable/disable (`ToggleColumn`) and delete/run-now (`Action`) already
 * wired through the generic `<KinetixTable>` plumbing. Polls through
 * `useKinetixPolling()`: paused in background tabs, never overlapping.
 */
export function useKinetixReportSchedules() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/report-schedules`;
    const interval = page.props.kinetix_reports_center?.poll ?? 5000;

    const table = ref<KinetixTableData | null>(null);
    const loading = ref(false);
    const failed = ref(false);
    // The last payload as JSON: an unchanged poll keeps the same table object,
    // so the rows do not re-render every tick.
    let lastPayload: string | null = null;

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const next = await kinetixFetch<KinetixTableData>(base());
            const serialized = JSON.stringify(next);

            if (serialized !== lastPayload || table.value === null) {
                lastPayload = serialized;
                table.value = next;
            }

            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    const polling = useKinetixPolling(load, { interval });
    const start = (): void => polling.start();
    const stop = (): void => polling.stop();

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

    return { table, loading, failed, load, start, stop, create };
}
