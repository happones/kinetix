import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixTableStat, KinetixSummary } from '@/types/kinetix';

interface AggregatesResponse {
    stats: KinetixTableStat[];
    summaries: Record<string, KinetixSummary[]>;
    hasSummaries: boolean;
}

export interface UseKinetixTableAggregatesOptions {
    /** The table's `aggregatesDescriptor` (null when not deferred). */
    descriptor: () => string | null | undefined;
    /** Inline aggregates to seed from when NOT deferred. */
    initial: () => {
        stats: KinetixTableStat[];
        summaries: Record<string, KinetixSummary[]>;
        hasSummaries: boolean;
    };
}

/**
 * Loads a table's deferred aggregates (KPI stats + column summaries) after
 * first paint. When the table shipped `deferStats()`, `toData()` left them
 * empty and provided a signed descriptor; `load()` POSTs it (plus the current
 * search/filter query string, so the totals match the window the user sees) to
 * `kinetix.tables.aggregates` and fills the reactive refs. When not deferred it
 * seeds straight from the inline values and `loading` stays false.
 */
export function useKinetixTableAggregates(
    options: UseKinetixTableAggregatesOptions,
) {
    const page = usePage();
    const initial = options.initial();

    const stats: Ref<KinetixTableStat[]> = ref(initial.stats);
    const summaries: Ref<Record<string, KinetixSummary[]>> = ref(
        initial.summaries,
    );
    const hasSummaries: Ref<boolean> = ref(initial.hasSummaries);
    const loading = ref(false);

    const load = async (): Promise<void> => {
        const descriptor = options.descriptor();

        if (!descriptor) {
            return;
        }

        loading.value = true;

        try {
            // Forward the current query string so the server computes the
            // aggregates over the same filtered/searched set the page shows.
            const query =
                typeof window !== 'undefined' ? window.location.search : '';

            const result = await kinetixFetch<AggregatesResponse>(
                `/${kinetixRoutePrefix(page)}/tables/aggregates${query}`,
                { method: 'POST', body: { descriptor } },
            );

            if (!result) {
                return;
            }

            stats.value = result.stats ?? [];
            summaries.value = result.summaries ?? {};
            hasSummaries.value = !!result.hasSummaries;
        } finally {
            loading.value = false;
        }
    };

    return { stats, summaries, hasSummaries, loading, load };
}
