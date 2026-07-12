import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps, ReportTypeData } from '@/types';

/**
 * Report-type list for `<KinetixReportLauncher>` — no `Table`/row-actions
 * involved (this is a plain card list, not an Eloquent-backed grid), so it
 * self-fetches once and exposes a direct `launch()` call.
 */
export function useKinetixReportTypes() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}`;

    const types = ref<ReportTypeData[]>([]);
    const loading = ref(false);
    const failed = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            types.value =
                (await kinetixFetch<ReportTypeData[]>(
                    `${base()}/report-types`,
                )) ?? [];
            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    async function launch(
        token: string,
        parameters: Record<string, unknown> = {},
    ): Promise<{ status: string; run_id: number | string }> {
        return (await kinetixFetch(`${base()}/report-runs/launch`, {
            method: 'POST',
            body: { report: token, parameters },
        })) as { status: string; run_id: number | string };
    }

    return { types, loading, failed, load, launch };
}
