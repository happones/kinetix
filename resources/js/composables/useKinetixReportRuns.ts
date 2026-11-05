import { usePage } from '@inertiajs/vue3';
import { getCurrentScope, onScopeDispose, readonly, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixPolling } from '@/composables/useKinetixPolling';
import type {
    KinetixReportRunsPayload,
    KinetixSharedProps,
    KinetixTableData,
} from '@/types/kinetix';

/** The idle cadence: nothing is pending or running, so a launch is the only change to expect. */
const IDLE_POLL_MS = 60_000;

/** Full-speed polls kept after `kinetix:report-runs-changed`, before an idle answer slows the loop again. */
const GRACE_TICKS = 2;

/** The window event a launch or retry dispatches so the runs table catches up at once. */
export const KINETIX_REPORT_RUNS_CHANGED = 'kinetix:report-runs-changed';

/**
 * Live report-runs table for `<KinetixReportRunsTable>`: fetches the
 * `Table`-built payload from the gated endpoint and polls it through
 * `useKinetixPolling()` (paused in background tabs, never overlapping) at the
 * shared `kinetix_reports_center.poll` cadence while a run is pending or
 * running, and at most once a minute while none is. A launch or retry
 * (`kinetix:report-runs-changed`) refreshes at once and resumes full speed.
 * Row actions (cancel/retry/download) are ordinary `Action`s already wired
 * through `executeAction()`/`useActionConfirmation()` by `<KinetixTable>`
 * itself, so this composable only needs to fetch/poll, not mutate.
 */
export function useKinetixReportRuns() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/report-runs`;
    const poll = page.props.kinetix_reports_center?.poll ?? 5000;

    const table = ref<KinetixTableData | null>(null);
    const loading = ref(false);
    const failed = ref(false);
    // A server without the flag reports nothing: treat it as active so the
    // cadence stays what it was.
    const hasActive = ref(true);
    let graceTicks = 0;
    // The last payload as JSON: an unchanged poll keeps the same table object,
    // so the rows do not re-render every tick.
    let lastPayload: string | null = null;

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const next =
                await kinetixFetch<Partial<KinetixReportRunsPayload>>(base());

            if (next == null) {
                hasActive.value = true;
                lastPayload = null;
                table.value = null;
            } else {
                const { hasActive: active, ...data } = next;
                const serialized = JSON.stringify(data);

                hasActive.value = active !== false;

                if (serialized !== lastPayload || table.value === null) {
                    lastPayload = serialized;
                    table.value = data as KinetixTableData;
                }
            }

            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;

            if (graceTicks > 0) {
                graceTicks--;
            }
        }
    }

    const polling = useKinetixPolling(load, {
        interval: () => {
            if (poll <= 0) {
                return 0;
            }

            return hasActive.value || graceTicks > 0
                ? poll
                : Math.max(poll, IDLE_POLL_MS);
        },
    });

    function onRunsChanged(): void {
        graceTicks = GRACE_TICKS + 1;
        void polling.refresh();
    }

    function start(): void {
        if (polling.active.value || typeof window === 'undefined') {
            return;
        }

        window.addEventListener(KINETIX_REPORT_RUNS_CHANGED, onRunsChanged);
        polling.start();
    }

    function stop(): void {
        if (typeof window !== 'undefined') {
            window.removeEventListener(
                KINETIX_REPORT_RUNS_CHANGED,
                onRunsChanged,
            );
        }

        polling.stop();
    }

    if (getCurrentScope()) {
        onScopeDispose(stop);
    }

    return {
        table,
        loading,
        failed,
        hasActive: readonly(hasActive),
        load,
        start,
        stop,
    };
}
