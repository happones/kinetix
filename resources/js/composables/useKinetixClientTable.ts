import {
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useVueTable,
} from '@tanstack/vue-table';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { KinetixTableColumn, KinetixTableRecord } from '@/types';

/**
 * TanStack-backed client-side engine for Kinetix tables. Given the full row set
 * shipped by `Table::clientSide()`, it does search / sort / pagination entirely
 * in the browser — no server round-trip per interaction.
 *
 * It sorts and filters on each row's already-serialized display value
 * (`record.values[col.name]`), so sorting matches what the user sees. For exact
 * numeric/date ordering on formatted columns, prefer the default server-driven
 * mode, which sorts at the database level.
 *
 * This is the only module that imports `@tanstack/vue-table`; it's reached only
 * through `KinetixDataTable.vue`, which `KinetixTable.vue` async-loads when a
 * table opts into client-side mode — so the dependency stays code-split and
 * never ships to server-driven tables.
 */
export interface KinetixClientPagination {
    total: number;
    perPage: number;
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
}

export interface UseKinetixClientTableOptions {
    /** Reactive getter for the full row set. */
    records: () => KinetixTableRecord[];
    /** Reactive getter for the columns to search/sort over. */
    columns: () => KinetixTableColumn[];
    /** Initial page size. */
    pageSize?: number;
}

export interface UseKinetixClientTable {
    search: Ref<string>;
    sortName: ComputedRef<string | null>;
    sortDirection: ComputedRef<'asc' | 'desc'>;
    pageRecords: ComputedRef<KinetixTableRecord[]>;
    pagination: ComputedRef<KinetixClientPagination>;
    toggleSort: (name: string) => void;
    setPage: (page: number) => void;
    setPerPage: (perPage: number) => void;
}

export function useKinetixClientTable(
    options: UseKinetixClientTableOptions,
): UseKinetixClientTable {
    const sorting = ref<SortingState>([]);
    const globalFilter = ref('');
    const pagination = ref({ pageIndex: 0, pageSize: options.pageSize ?? 10 });

    // One TanStack column per Kinetix column, reading the serialized display
    // value. `id` is the column name so header sort toggles map straight across.
    const columnDefs = computed<ColumnDef<KinetixTableRecord>[]>(() =>
        options.columns().map((col) => ({
            id: col.name,
            accessorFn: (row) => {
                const value = row.values?.[col.name];

                return value == null ? '' : String(value);
            },
            // Case-insensitive, and orders embedded numbers numerically
            // ("row 2" before "row 10") — the sanest default for display values.
            sortingFn: 'alphanumeric',
        })),
    );

    const table = useVueTable({
        get data() {
            return options.records();
        },
        get columns() {
            return columnDefs.value;
        },
        state: {
            get sorting() {
                return sorting.value;
            },
            get globalFilter() {
                return globalFilter.value;
            },
            get pagination() {
                return pagination.value;
            },
        },
        globalFilterFn: 'includesString',
        onSortingChange: (updater) => {
            sorting.value =
                typeof updater === 'function'
                    ? updater(sorting.value)
                    : updater;
        },
        onGlobalFilterChange: (updater) => {
            globalFilter.value =
                typeof updater === 'function'
                    ? updater(globalFilter.value)
                    : updater;
        },
        onPaginationChange: (updater) => {
            pagination.value =
                typeof updater === 'function'
                    ? updater(pagination.value)
                    : updater;
        },
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
    });

    // `search` is the public face of the global filter; editing it resets to
    // the first page so the user isn't stranded on an out-of-range page.
    const search = computed<string>({
        get: () => globalFilter.value,
        set: (value) => {
            globalFilter.value = value;
            table.setPageIndex(0);
        },
    });

    const sortName = computed(() => sorting.value[0]?.id ?? null);
    const sortDirection = computed<'asc' | 'desc'>(() =>
        sorting.value[0]?.desc ? 'desc' : 'asc',
    );

    const toggleSort = (name: string): void => {
        const current = sorting.value[0];

        if (current?.id === name) {
            sorting.value = [{ id: name, desc: !current.desc }];
        } else {
            sorting.value = [{ id: name, desc: false }];
        }

        table.setPageIndex(0);
    };

    const pageRecords = computed(() =>
        table.getRowModel().rows.map((row) => row.original),
    );

    const paginationInfo = computed<KinetixClientPagination>(() => {
        const total = table.getFilteredRowModel().rows.length;
        const { pageIndex, pageSize } = pagination.value;
        const currentPage = pageIndex + 1;
        const lastPage = Math.max(1, Math.ceil(total / pageSize));
        const from = total === 0 ? null : pageIndex * pageSize + 1;
        const to = total === 0 ? null : Math.min(total, currentPage * pageSize);

        return { total, perPage: pageSize, currentPage, lastPage, from, to };
    });

    const setPage = (page: number): void => {
        table.setPageIndex(Math.max(0, page - 1));
    };

    const setPerPage = (perPage: number): void => {
        table.setPageSize(perPage);
        table.setPageIndex(0);
    };

    return {
        search,
        sortName,
        sortDirection,
        pageRecords,
        pagination: paginationInfo,
        toggleSort,
        setPage,
        setPerPage,
    };
}
