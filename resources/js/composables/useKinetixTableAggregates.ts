import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    isKinetixAbort,
    kinetixFetch,
    kinetixRoutePrefix,
} from '@/composables/useKinetixHttp';
import type { KinetixTableStat, KinetixSummary } from '@/types/kinetix';

interface AggregatesResponse {
    stats: KinetixTableStat[];
    summaries: Record<string, KinetixSummary[]>;
    hasSummaries: boolean;
}

export interface UseKinetixTableAggregatesOptions {
    /** The table's `aggregatesDescriptor` (null when not deferred). */
    descriptor: () => string | null | undefined;
    /** The table's inline aggregates, read live whenever NOT deferred. */
    initial: () => {
        stats: KinetixTableStat[];
        summaries: Record<string, KinetixSummary[]>;
        hasSummaries: boolean;
    };
}

export interface UseKinetixTableAggregates {
    stats: ComputedRef<KinetixTableStat[]>;
    summaries: ComputedRef<Record<string, KinetixSummary[]>>;
    hasSummaries: ComputedRef<boolean>;
    /** A fetch is in flight. */
    loading: Ref<boolean>;
    /** At least one fetch has settled — later ones refresh in place. */
    loaded: Ref<boolean>;
    load: () => Promise<void>;
    cancel: () => void;
}

/**
 * A table's aggregates (KPI stats + column summaries).
 *
 * Not deferred: they come straight from the table prop, read LIVE — a search,
 * filter, page change or poll replaces the prop in place (preserveState), and
 * the cards and footer must follow it.
 *
 * Deferred (`Table::deferStats()`): `toData()` ships them empty with a signed
 * descriptor; `load()` POSTs it (plus the current query string, so the totals
 * match the window the user sees) to `kinetix.tables.aggregates`. Call it again
 * whenever the table reloads: a newer call aborts the one in flight, so a slow
 * response for the previous filters can never overwrite the current ones.
 */
export function useKinetixTableAggregates(
    options: UseKinetixTableAggregatesOptions,
): UseKinetixTableAggregates {
    const page = usePage();

    const fetched = ref<AggregatesResponse | null>(null);
    // Deferred tables start in the loading state, so the first paint (and SSR)
    // already shows the skeleton instead of an empty gap.
    const loading = ref(!!options.descriptor());
    const loaded = ref(false);
    let controller: AbortController | null = null;

    const deferred = (): boolean => !!options.descriptor();

    const stats = computed<KinetixTableStat[]>(() =>
        deferred() ? (fetched.value?.stats ?? []) : options.initial().stats,
    );
    const summaries = computed<Record<string, KinetixSummary[]>>(() =>
        deferred()
            ? (fetched.value?.summaries ?? {})
            : options.initial().summaries,
    );
    const hasSummaries = computed<boolean>(() =>
        deferred()
            ? !!fetched.value?.hasSummaries
            : options.initial().hasSummaries,
    );

    const cancel = (): void => {
        controller?.abort();
        controller = null;
    };

    const load = async (): Promise<void> => {
        const descriptor = options.descriptor();

        if (!descriptor) {
            loading.value = false;

            return;
        }

        cancel();
        const current = new AbortController();
        controller = current;
        loading.value = true;

        try {
            const query =
                typeof window !== 'undefined' ? window.location.search : '';

            const result = await kinetixFetch<AggregatesResponse>(
                `/${kinetixRoutePrefix(page)}/tables/aggregates${query}`,
                {
                    method: 'POST',
                    body: { descriptor },
                    signal: current.signal,
                },
            );

            if (controller !== current) {
                return;
            }

            fetched.value = {
                stats: result?.stats ?? [],
                summaries: result?.summaries ?? {},
                hasSummaries: !!result?.hasSummaries,
            };
        } catch (error) {
            // A superseded request is not a failure. A real one keeps what is
            // on screen — the table stays usable without its totals.
            if (isKinetixAbort(error) || controller !== current) {
                return;
            }
        } finally {
            if (controller === current) {
                controller = null;
                loading.value = false;
                loaded.value = true;
            }
        }
    };

    return { stats, summaries, hasSummaries, loading, loaded, load, cancel };
}
