import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import type { Ref } from 'vue';
import type { KinetixTableData } from '@/types/kinetix';

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
    /** Cursor-paginated tables navigate by seek position, not page number. */
    cursor?: string | null;
    filters?: Record<string, unknown>;
}

/** The scalar query keys a table owns, before namespacing. */
const OWNED_SCALAR_KEYS = [
    'search',
    'sort',
    'direction',
    'perPage',
    'page',
    'cursor',
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
): Record<string, any> {
    const own: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(mergedState)) {
        own[`${prefix}${key}`] = value;
    }

    const ownsKey = (key: string): boolean =>
        OWNED_SCALAR_KEYS.some((k) => key === `${prefix}${k}`) ||
        key.startsWith(`${prefix}filters`);

    // getAll(): a foreign MULTI-VALUE param (another table's array filter,
    // `posts_filters[tags][]=1&…[]=2`) must survive as an array — forEach
    // last-wins would silently truncate it to one value.
    const params = new URLSearchParams(currentSearch);
    const preserved: Record<string, unknown> = {};

    for (const key of new Set(params.keys())) {
        if (ownsKey(key)) {
            continue;
        }

        const values = params.getAll(key);
        preserved[key] = values.length > 1 ? values : values[0];
    }

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

        // `page` and `cursor` are alternative positions in the same result set,
        // so sending both would let a stale one decide. A cursor also encodes a
        // row from the ordering it was issued under, so anything that changes
        // the result set — search, sort, filters, page size — must drop it and
        // restart, or the seek resumes from a meaningless position.
        if (params.cursor !== undefined) {
            delete merged.page;

            if (params.cursor === null) {
                delete merged.cursor; // back to the first page
            }
        } else {
            delete merged.cursor;
        }

        // Refinements (search/filters/per-page/sort) REPLACE the history entry
        // — otherwise every debounced keystroke pushes one and Back walks the
        // whole typing history. A pure page/cursor change still pushes, so
        // Back steps through pages like normal pagination. (Refinements also
        // reset `page`, so presence of `page` alone doesn't make navigation.)
        const isRefinement = (
            ['search', 'filters', 'perPage', 'sort', 'direction'] as const
        ).some((key) => params[key] !== undefined);

        router.get(
            window.location.pathname,
            buildTableQuery(prefix, merged, window.location.search),
            {
                preserveState: true,
                preserveScroll: true,
                replace: isRefinement,
            },
        );
    };

    let searchTimeout: ReturnType<typeof setTimeout> | null = null;

    const onSearchInput = (): void => {
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        searchTimeout = setTimeout(() => {
            triggerReload({ search: searchQuery.value, page: 1, cursor: null });
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
