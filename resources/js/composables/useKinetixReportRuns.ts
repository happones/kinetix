import { usePage } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps, KinetixTableData } from '@/types/kinetix';

/**
 * Live report-runs table for `<KinetixReportRunsTable>`: fetches the
 * `Table`-built payload from the gated endpoint and polls it on an interval
 * (from the shared `kinetix_reports_center` config) — mirrors
 * `useKinetixQueue.ts`'s shape. Row actions (cancel/retry/download) are
 * ordinary `Action`s already wired through `executeAction()`/
 * `useActionConfirmation()` by `<KinetixTable>` itself, so this composable
 * only needs to fetch/poll, not mutate.
 */
export function useKinetixReportRuns() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/report-runs`;
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

    onUnmounted(stop);

    return { table, loading, failed, load, start, stop };
}
