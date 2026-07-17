import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, type Ref } from 'vue';
import type { KinetixTableData } from '@/types';

/**
 * The reload parameters a Kinetix table round-trips to the server. Every field
 * is optional on a partial reload; unspecified fields fall back to the table's
 * current state.
 */
export interface KinetixTableQueryParams {
    search?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    perPage?: number;
    page?: number;
    filters?: Record<string, unknown>;
}

/** The five scalar query keys a table owns, before namespacing. */
const OWNED_SCALAR_KEYS = [
    'search',
    'sort',
    'direction',
    'perPage',
    'page',
] as const;

/**
 * Build the namespaced query object for a table reload, preserving any query
 * params that belong to other tables/widgets on the same page.
 *
 * Kept pure (no router, no `window`) so the prefix/preservation logic is unit
 * testable: pass the merged reload state and the current query string, get back
 * the exact `{ ...preserved, ...own }` object handed to `router.get`.
 *
 * @param prefix        The table's `queryPrefix` (namespaces coexisting tables).
 * @param mergedState   Final reload values (current state merged with overrides).
 * @param currentSearch The current `window.location.search` string.
 */
export function buildTableQuery(
    prefix: string,
    mergedState: Record<string, unknown>,
    currentSearch: string,
): Record<string, unknown> {
    const own: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(mergedState)) {
        own[`${prefix}${key}`] = value;
    }

    const ownsKey = (key: string): boolean =>
        OWNED_SCALAR_KEYS.some((k) => key === `${prefix}${k}`) ||
        key.startsWith(`${prefix}filters`);

    const preserved: Record<string, unknown> = {};
    new URLSearchParams(currentSearch).forEach((value, key) => {
        if (!ownsKey(key)) {
            preserved[key] = value;
        }
    });

    return { ...preserved, ...own };
}

export interface UseKinetixTableQueryOptions {
    /** Reactive getter for the table payload (state, prefix, pagination). */
    table: () => KinetixTableData;
    /** The two-way search box model, kept in sync with reloads. */
    searchQuery: Ref<string>;
    /** The active filter map, kept in sync with reloads. */
    activeFilters: Ref<Record<string, unknown>>;
    /** Debounce window (ms) for typed search input. */
    searchDebounce?: number;
}

export interface UseKinetixTableQuery {
    triggerReload: (params: KinetixTableQueryParams) => void;
    onSearchInput: () => void;
}

/**
 * Server-driven reload orchestration for a Kinetix table: namespaces params by
 * the table's `queryPrefix`, preserves foreign query params, and debounces the
 * search box. Reloads preserve scroll and state.
 */
export function useKinetixTableQuery(
    options: UseKinetixTableQueryOptions,
): UseKinetixTableQuery {
    const { table, searchQuery, activeFilters } = options;
    const debounce = options.searchDebounce ?? 400;

    const triggerReload = (params: KinetixTableQueryParams): void => {
        const current = table();
        const prefix = current.queryPrefix ?? '';

        const merged: Record<string, unknown> = {
            search: searchQuery.value,
            sort: current.state.sort,
            direction: current.state.direction,
            perPage: current.state.perPage,
            page: current.pagination?.currentPage ?? 1,
            filters: { ...activeFilters.value },
            ...params,
        };

        router.get(
            window.location.pathname,
            buildTableQuery(prefix, merged, window.location.search),
            { preserveState: true, preserveScroll: true },
        );
    };

    let searchTimeout: ReturnType<typeof setTimeout> | null = null;

    const onSearchInput = (): void => {
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        searchTimeout = setTimeout(() => {
            triggerReload({ search: searchQuery.value, page: 1 });
        }, debounce);
    };

    // Avoid a pending debounced reload firing after the table unmounts.
    onBeforeUnmount(() => {
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
    });

    return { triggerReload, onSearchInput };
}
