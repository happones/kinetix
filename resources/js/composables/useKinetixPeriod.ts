import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

export type KinetixPeriodKey =
    | 'today'
    | 'yesterday'
    | '7d'
    | '30d'
    | '90d'
    | 'month'
    | 'year'
    | 'all';

export interface KinetixPeriodRange {
    start: string | null;
    end: string | null;
}

const toISODate = (date: Date): string => date.toISOString().slice(0, 10);

/**
 * Resolve a period key to a `{ start, end }` ISO date range — mirrors the PHP
 * `Period` parser so client and server agree.
 */
export function resolvePeriodRange(key: KinetixPeriodKey): KinetixPeriodRange {
    const now = new Date();
    const start = new Date(now);
    const end = new Date(now);

    switch (key) {
        case 'today':
            break;
        case 'yesterday':
            start.setDate(now.getDate() - 1);
            end.setDate(now.getDate() - 1);
            break;
        case '7d':
            start.setDate(now.getDate() - 6);
            break;
        case '30d':
            start.setDate(now.getDate() - 29);
            break;
        case '90d':
            start.setDate(now.getDate() - 89);
            break;
        case 'month':
            start.setDate(1);
            break;
        case 'year':
            start.setMonth(0, 1);
            break;
        case 'all':
            return { start: null, end: null };
    }

    return { start: toISODate(start), end: toISODate(end) };
}

/**
 * Dashboard period filter state: the selected key, its resolved date range, and
 * a setter that optionally pushes `?period=` to the server (Inertia visit) so
 * the backend re-scopes its data with the matching `Period::fromRequest()`.
 */
export function useKinetixPeriod(
    initial: KinetixPeriodKey = '30d',
    options: { navigate?: boolean; only?: string[] } = {},
) {
    const urlPeriod =
        typeof window !== 'undefined'
            ? (new URLSearchParams(window.location.search).get(
                  'period',
              ) as KinetixPeriodKey | null)
            : null;

    const period = ref<KinetixPeriodKey>(urlPeriod ?? initial);
    const range = computed<KinetixPeriodRange>(() =>
        resolvePeriodRange(period.value),
    );

    function setPeriod(key: KinetixPeriodKey): void {
        period.value = key;

        if (options.navigate && typeof window !== 'undefined') {
            const query = Object.fromEntries(
                new URLSearchParams(window.location.search),
            );

            router.get(
                window.location.pathname,
                { ...query, period: key },
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: options.only,
                },
            );
        }
    }

    return { period, range, setPeriod };
}
