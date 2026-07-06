<script setup lang="ts">
import { router, usePage, usePoll } from '@inertiajs/vue3';
import {
    Search,
    Filter as FilterIcon,
    SlidersHorizontal,
    GripVertical,
} from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    executeAction,
    useActionConfirmation,
} from '@/composables/useKinetixActions';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useShadcnVariants';
import type {
    KinetixTableData,
    KinetixTableRecord,
    KinetixAction,
} from '@/types';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixCheckboxList from './KinetixCheckboxList.vue';
import KinetixCombobox from './KinetixCombobox.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixDatePicker from './KinetixDatePicker.vue';
import KinetixDateTimePicker from './KinetixDateTimePicker.vue';
import KinetixMonthPicker from './KinetixMonthPicker.vue';
import KinetixRangeCalendar from './KinetixRangeCalendar.vue';
import KinetixSavedViews from './KinetixSavedViews.vue';
import KinetixSelect from './KinetixSelect.vue';
import KinetixWeekPicker from './KinetixWeekPicker.vue';
import KinetixYearPicker from './KinetixYearPicker.vue';
import KinetixTableCell from './Table/KinetixTableCell.vue';
import KinetixTableHead from './Table/KinetixTableHead.vue';
import KinetixTablePagination from './Table/KinetixTablePagination.vue';

const props = defineProps<{
    table: KinetixTableData;
}>();

const { t } = useI18n();

// shadcn-vue (new-york) button UI for table actions. Record actions default to
// a light `ghost` so rows stay clean; toolbar/bulk actions default to the solid
// primary button. An explicit action color is always respected (danger →
// destructive, etc.).
const recordActionClass = (action: {
    color?: string | null;
    isIconButton?: boolean;
}) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'ghost',
        // Icon-only actions render as a compact square (shadcn row-action style).
        size: action.isIconButton ? 'icon-sm' : 'sm',
    });

const primaryActionClass = (action: { color?: string | null }) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'default',
        size: 'sm',
    });

const showFilters = ref(false);
const showColumns = ref(false);
const searchQuery = ref(props.table.state.search);
const activeFilters = ref<Record<string, any>>({
    ...props.table.state.filters,
});

// Local column visibility mapping
const visibleColumnNames = ref<Set<string>>(
    new Set(
        props.table.columns
            .filter((c) => !c.isToggledHiddenByDefault)
            .map((c) => c.name),
    ),
);

const isColumnVisible = (name: string) => visibleColumnNames.value.has(name);

const toggleColumn = (name: string) => {
    const newSet = new Set(visibleColumnNames.value);

    if (newSet.has(name)) {
        if (newSet.size > 1) {
            newSet.delete(name);
            visibleColumnNames.value = newSet;
        }

        return;
    }

    newSet.add(name);
    visibleColumnNames.value = newSet;
};

const columnsToRender = computed(() => {
    return props.table.columns.filter((c) => isColumnVisible(c.name));
});

// --- Saved views -----------------------------------------------------------
// The snapshot a saved view captures, and how one is restored.
const currentViewState = computed(() => ({
    search: searchQuery.value,
    sort: props.table.state.sort,
    direction: props.table.state.direction,
    perPage: props.table.state.perPage,
    filters: { ...activeFilters.value },
    columns: [...visibleColumnNames.value],
}));

const applyView = (state: Record<string, any>) => {
    if (Array.isArray(state.columns)) {
        visibleColumnNames.value = new Set(state.columns as string[]);
    }

    searchQuery.value = (state.search as string) ?? '';
    activeFilters.value = { ...((state.filters as object) ?? {}) };

    triggerReload({
        search: searchQuery.value,
        sort: state.sort ?? props.table.state.sort,
        direction: state.direction ?? props.table.state.direction,
        perPage: state.perPage ?? props.table.state.perPage,
        filters: activeFilters.value,
        page: 1,
    });
};

// Standard icon mappings

// Reload data from server. Params are namespaced by the table's queryPrefix so
// multiple tables (e.g. relation managers) coexist; any unrelated/foreign query
// params already in the URL are preserved.
const triggerReload = (newParams: Record<string, any>) => {
    const prefix = props.table.queryPrefix ?? '';

    const base: Record<string, any> = {
        search: searchQuery.value,
        sort: props.table.state.sort,
        direction: props.table.state.direction,
        perPage: props.table.state.perPage,
        page: props.table.pagination?.currentPage ?? 1,
        filters: { ...activeFilters.value },
        ...newParams,
    };

    const own: Record<string, any> = {};

    for (const [key, value] of Object.entries(base)) {
        own[`${prefix}${key}`] = value;
    }

    // Keep query params that belong to other tables/widgets on the page.
    const ownsKey = (key: string) =>
        ['search', 'sort', 'direction', 'perPage', 'page'].some(
            (k) => key === `${prefix}${k}`,
        ) || key.startsWith(`${prefix}filters`);

    const preserved: Record<string, any> = {};
    new URLSearchParams(window.location.search).forEach((value, key) => {
        if (!ownsKey(key)) {
            preserved[key] = value;
        }
    });

    router.get(
        window.location.pathname,
        { ...preserved, ...own },
        { preserveState: true, preserveScroll: true },
    );
};

// Debounced search
let searchTimeout: any = null;
const onSearchInput = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        triggerReload({ search: searchQuery.value, page: 1 });
    }, 400);
};

// Avoid a pending debounced reload firing after the table unmounts.
onBeforeUnmount(() => clearTimeout(searchTimeout));

// Sorting
const isSorted = (name: string) => {
    return props.table.state.sort === name;
};

const toggleSort = (name: string) => {
    if (isSorted(name)) {
        const nextDir = props.table.state.direction === 'asc' ? 'desc' : 'asc';
        triggerReload({ sort: name, direction: nextDir });

        return;
    }

    triggerReload({ sort: name, direction: 'asc' });
};

const getFilterOptions = (
    options: Record<string, string> | null | undefined,
) => {
    return {
        '': t('kinetix.all'),
        ...(options || {}),
    };
};

// Filters
const setFilter = (name: string, value: any) => {
    activeFilters.value[name] = value;
    triggerReload({ filters: activeFilters.value, page: 1 });
};

const clearFilters = () => {
    activeFilters.value = {};
    triggerReload({ filters: {}, page: 1 });
};

// Update one bound of a range filter (date-range / number-range).
const setRangePart = (
    name: string,
    part: 'from' | 'to' | 'min' | 'max',
    value: any,
) => {
    setFilter(name, { ...(activeFilters.value[name] || {}), [part]: value });
};

const isMultiSelected = (name: string, val: string) => {
    const current = activeFilters.value[name];

    return Array.isArray(current) && current.includes(val);
};

const toggleMulti = (name: string, val: string, checked: boolean) => {
    const current = Array.isArray(activeFilters.value[name])
        ? [...activeFilters.value[name]]
        : [];
    const index = current.indexOf(val);

    if (checked && index === -1) {
        current.push(val);
    }

    if (!checked && index !== -1) {
        current.splice(index, 1);
    }

    setFilter(name, current);
};

// Row Click
const handleRowClick = (record: KinetixTableRecord, event: MouseEvent) => {
    // Avoid redirect if clicking a button or checkbox
    const target = event.target as HTMLElement;

    if (
        target.closest('button') ||
        target.closest('a') ||
        target.closest('input') ||
        target.closest('select')
    ) {
        return;
    }

    if (record.recordUrl) {
        router.visit(record.recordUrl);
    }
};

// Action execution + confirmation gating live in a shared composable so tables
// and page action bars behave identically.
const {
    pendingAction,
    isConfirmOpen,
    requestAction,
    confirm: onConfirmAction,
    cancel: onCancelAction,
} = useActionConfirmation();

const handleActionClick = (action: KinetixAction) => requestAction(action);

// --- Row selection + bulk actions ---------------------------------------------
const selectedIds = ref<Set<string | number>>(new Set());
const selectionCount = computed(() => selectedIds.value.size);

const isRowSelected = (id: string | number) => selectedIds.value.has(id);

const toggleRow = (id: string | number, checked: boolean) => {
    const next = new Set(selectedIds.value);

    if (checked) {
        next.add(id);
    } else {
        next.delete(id);
    }

    selectedIds.value = next;
};

const allOnPageSelected = computed(
    () =>
        props.table.records.length > 0 &&
        props.table.records.every((r) => selectedIds.value.has(r.id)),
);

const toggleAllOnPage = (checked: boolean) => {
    const next = new Set(selectedIds.value);
    props.table.records.forEach((r) =>
        checked ? next.add(r.id) : next.delete(r.id),
    );
    selectedIds.value = next;
};

const clearSelection = () => {
    selectedIds.value = new Set();
};

// Bulk actions send the selected ids; destructive ones gate on a confirm modal.
const bulkPending = ref<KinetixAction | null>(null);
const isBulkConfirmOpen = ref(false);

const runBulkAction = (action: KinetixAction) => {
    executeAction(action, { ids: Array.from(selectedIds.value) });
    clearSelection();
};

const requestBulkAction = (action: KinetixAction) => {
    if (action.requiresConfirmation) {
        bulkPending.value = action;
        isBulkConfirmOpen.value = true;

        return;
    }

    runBulkAction(action);
};

const onBulkConfirm = () => {
    if (bulkPending.value) {
        runBulkAction(bulkPending.value);
    }

    bulkPending.value = null;
};

const onBulkCancel = () => {
    bulkPending.value = null;
};

const page = usePage();
const routePrefix = computed(() => {
    return (page.props.kinetix_config as any)?.route_prefix ?? '_kinetix';
});

const copyToClipboard = (text: string) => {
    if (!text) {
        return;
    }

    navigator.clipboard.writeText(text);
};

const updateCell = async (
    recordId: string | number,
    columnName: string,
    newValue: any,
) => {
    if (!recordId || !columnName) {
        return;
    }

    try {
        const data = await kinetixFetch<{ status?: string }>(
            `/${routePrefix.value}/tables/cell-update`,
            {
                method: 'POST',
                body: {
                    model: props.table.model,
                    recordId,
                    column: columnName,
                    value: newValue,
                },
            },
        );

        if (data?.status === 'success') {
            // reload() preserves scroll and state by default.
            router.reload();
        }
    } catch (e) {
        console.error('Cell update failed:', e);
    }
};

// --- Polling (Inertia usePoll) ----------------------------------------------
// `Table::poll('10s')` → a partial reload on an interval (preserves scroll/state).
const parsePollInterval = (poll: string | null | undefined): number => {
    if (!poll) {
        return 0;
    }

    const match = /^(\d+)\s*(ms|s)?$/.exec(poll.trim());

    if (!match) {
        return 0;
    }

    const value = Number(match[1]);

    return match[2] === 'ms' ? value : value * 1000;
};

const pollInterval = parsePollInterval(props.table.poll);
// reload() preserves scroll + state by default, so no extra options needed.
const poll = usePoll(pollInterval || 60000, {}, { autoStart: false });
onMounted(() => {
    if (pollInterval > 0) {
        poll.start();
    }
});

// --- Row reordering ----------------------------------------------------------
const localRecords = ref<KinetixTableRecord[]>([...props.table.records]);
watch(
    () => props.table.records,
    (next) => {
        localRecords.value = [...next];
    },
);

// Rows iterate the local copy while reordering so drag previews are instant.
const rows = computed<KinetixTableRecord[]>(() =>
    props.table.reorderable ? localRecords.value : props.table.records,
);

let dragIndex: number | null = null;

const onDragStart = (index: number) => {
    dragIndex = index;
};

const onDragOver = (index: number, event: DragEvent) => {
    event.preventDefault();

    if (dragIndex === null || dragIndex === index) {
        return;
    }

    const next = [...localRecords.value];
    const [moved] = next.splice(dragIndex, 1);
    next.splice(index, 0, moved);
    localRecords.value = next;
    dragIndex = index;
};

const onDrop = async () => {
    dragIndex = null;

    try {
        await kinetixFetch(`/${routePrefix.value}/tables/reorder`, {
            method: 'POST',
            body: {
                model: props.table.model,
                ids: localRecords.value.map((r) => r.id),
            },
        });
    } catch (e) {
        console.error('Reorder failed:', e);
    }
};
</script>

<template>
    <div
        data-slot="card"
        class="kinetix-table-wrapper backdrop-blur-sm rounded-xl shadow-sm flex flex-col overflow-hidden border border-border bg-card text-card-foreground"
    >
        <!-- Header Controls -->
        <div
            data-slot="card-header"
            class="p-6 md:flex-row md:items-center gap-4 flex flex-col justify-between border-b border-border"
        >
            <div>
                <h3
                    v-if="table.heading"
                    data-slot="card-title"
                    class="text-base font-semibold leading-none text-foreground"
                >
                    {{ table.heading }}
                </h3>
                <p
                    v-if="table.description"
                    data-slot="card-description"
                    class="text-xs mt-1 text-muted-foreground"
                >
                    {{ table.description }}
                </p>
            </div>

            <!-- Toolbar Actions and Search/Filter Options -->
            <div
                class="gap-2 md:self-auto flex flex-wrap items-center self-end"
            >
                <!-- Search bar if any column is searchable -->
                <div
                    v-if="table.columns.some((c) => c.isSearchable)"
                    class="relative min-w-[200px]"
                >
                    <Search
                        class="left-3 top-2.5 h-4 w-4 absolute text-muted-foreground"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="t('kinetix.search_records')"
                        class="pl-9 pr-4 py-2 text-sm rounded-lg w-full border border-border bg-muted/40 text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @input="onSearchInput"
                    />
                </div>

                <!-- Saved views (presets of search/filters/sort/columns) -->
                <KinetixSavedViews
                    v-if="table.savedViewsKey"
                    :view-key="table.savedViewsKey"
                    :current-state="currentViewState"
                    @apply="applyView"
                />

                <!-- Custom Header Toolbar Actions -->
                <template v-for="(action, i) in table.toolbarActions" :key="i">
                    <KinetixActionDropdown
                        v-if="action.type === 'group'"
                        :group="action"
                    />
                    <button
                        v-else
                        :class="primaryActionClass(action)"
                        @click="handleActionClick(action)"
                    >
                        <component
                            :is="resolveIcon(action.icon)"
                            v-if="action.icon"
                        />
                        {{ action.label }}
                    </button>
                </template>

                <!-- Filters Popover Trigger -->
                <div v-if="table.filters.length > 0">
                    <PopoverRoot v-model:open="showFilters">
                        <PopoverTrigger as-child>
                            <button
                                :class="[
                                    buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    }),
                                    Object.keys(activeFilters).length > 0
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : '',
                                ]"
                            >
                                <FilterIcon class="h-3.5 w-3.5" />
                                {{ t('kinetix.filters') }}
                                <span
                                    v-if="Object.keys(activeFilters).length > 0"
                                    class="ml-1 w-4 h-4 font-bold flex shrink-0 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground"
                                >
                                    {{ Object.keys(activeFilters).length }}
                                </span>
                            </button>
                        </PopoverTrigger>

                        <PopoverPortal>
                            <PopoverContent
                                align="end"
                                :side-offset="4"
                                class="w-72 rounded-lg p-4 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 border border-border bg-popover outline-none"
                            >
                                <div
                                    class="pb-2 mb-3 flex items-center justify-between border-b border-border"
                                >
                                    <span
                                        class="text-xs font-bold tracking-wider text-foreground uppercase"
                                        >{{ t('kinetix.table_filters') }}</span
                                    >
                                    <button
                                        class="text-xs text-muted-foreground underline-offset-4 transition-colors outline-none hover:text-foreground focus-visible:underline"
                                        @click="clearFilters"
                                    >
                                        {{ t('kinetix.reset') }}
                                    </button>
                                </div>
                                <div class="space-y-4">
                                    <div
                                        v-for="filter in table.filters"
                                        :key="filter.name"
                                        class="gap-1.5 flex flex-col"
                                    >
                                        <label
                                            class="text-xs font-semibold text-muted-foreground"
                                            >{{ filter.label }}</label
                                        >

                                        <KinetixCombobox
                                            v-if="
                                                (filter.type === 'select' ||
                                                    filter.type === 'ternary') &&
                                                filter.isSearchable
                                            "
                                            :value="
                                                activeFilters[filter.name] ?? ''
                                            "
                                            :options="
                                                getFilterOptions(filter.options)
                                            "
                                            :search-token="filter.searchToken"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />

                                        <KinetixSelect
                                            v-else-if="
                                                filter.type === 'select' ||
                                                filter.type === 'ternary'
                                            "
                                            :value="
                                                activeFilters[filter.name] ?? ''
                                            "
                                            :options="
                                                getFilterOptions(filter.options)
                                            "
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />

                                        <div
                                            v-if="filter.type === 'checkbox'"
                                            class="gap-2 mt-1 flex items-center"
                                        >
                                            <KinetixCheckbox
                                                :id="'filter-' + filter.name"
                                                :checked="
                                                    !!activeFilters[filter.name]
                                                "
                                                @change="
                                                    setFilter(
                                                        filter.name,
                                                        $event,
                                                    )
                                                "
                                            />
                                            <label
                                                :for="'filter-' + filter.name"
                                                class="text-xs cursor-pointer text-foreground select-none"
                                            >
                                                {{ t('kinetix.enable_filter') }}
                                            </label>
                                        </div>

                                        <!-- Date range — shadcn calendar variant -->
                                        <KinetixRangeCalendar
                                            v-if="
                                                filter.type === 'date-range' &&
                                                filter.useCalendar
                                            "
                                            :value="activeFilters[filter.name]"
                                            :number-of-months="
                                                filter.numberOfMonths
                                            "
                                            :locale="filter.locale"
                                            :weekday-format="
                                                filter.weekdayFormat
                                            "
                                            :fixed-weeks="filter.fixedWeeks"
                                            :min-value="filter.minValue"
                                            :max-value="filter.maxValue"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />

                                        <!-- Single date — shadcn calendar by default -->
                                        <KinetixDatePicker
                                            v-if="
                                                filter.type === 'date' &&
                                                filter.useCalendar
                                            "
                                            :value="
                                                activeFilters[filter.name] ||
                                                null
                                            "
                                            :locale="filter.locale"
                                            :min-value="filter.minValue"
                                            :max-value="filter.maxValue"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />
                                        <input
                                            v-else-if="filter.type === 'date'"
                                            type="date"
                                            :value="
                                                activeFilters[filter.name] || ''
                                            "
                                            class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            @change="
                                                setFilter(
                                                    filter.name,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).value,
                                                )
                                            "
                                        />

                                        <!-- Single datetime — shadcn picker by default -->
                                        <KinetixDateTimePicker
                                            v-if="
                                                filter.type === 'datetime' &&
                                                filter.useCalendar
                                            "
                                            :value="
                                                activeFilters[filter.name] ||
                                                null
                                            "
                                            :locale="filter.locale"
                                            :minute-step="filter.minuteStep"
                                            :hour12="filter.hour12"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />
                                        <input
                                            v-else-if="
                                                filter.type === 'datetime'
                                            "
                                            type="datetime-local"
                                            :value="
                                                activeFilters[filter.name] || ''
                                            "
                                            class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            @change="
                                                setFilter(
                                                    filter.name,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).value,
                                                )
                                            "
                                        />

                                        <!-- Month / Year / Week filters (shadcn or native) -->
                                        <KinetixMonthPicker
                                            v-if="filter.type === 'month'"
                                            :value="
                                                activeFilters[filter.name] ||
                                                null
                                            "
                                            :native="!filter.useCalendar"
                                            :locale="filter.locale"
                                            :min-value="filter.minValue"
                                            :max-value="filter.maxValue"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />
                                        <KinetixYearPicker
                                            v-if="filter.type === 'year'"
                                            :value="
                                                activeFilters[filter.name] ||
                                                null
                                            "
                                            :native="!filter.useCalendar"
                                            :min-value="filter.minValue"
                                            :max-value="filter.maxValue"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />
                                        <KinetixWeekPicker
                                            v-if="filter.type === 'week'"
                                            :value="
                                                activeFilters[filter.name] ||
                                                null
                                            "
                                            :native="!filter.useCalendar"
                                            :locale="filter.locale"
                                            :week-starts-on="
                                                filter.weekStartsOn ?? 1
                                            "
                                            :min-value="filter.minValue"
                                            :max-value="filter.maxValue"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />

                                        <!-- Address — free-text search across address columns -->
                                        <input
                                            v-if="filter.type === 'address'"
                                            type="text"
                                            :value="
                                                activeFilters[filter.name] || ''
                                            "
                                            :placeholder="
                                                t('kinetix.address_search')
                                            "
                                            class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            @input="
                                                setFilter(
                                                    filter.name,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).value,
                                                )
                                            "
                                        />

                                        <!-- Date range — native inputs -->
                                        <div
                                            v-if="
                                                filter.type === 'date-range' &&
                                                !filter.useCalendar
                                            "
                                            class="gap-2 flex items-center"
                                        >
                                            <input
                                                type="date"
                                                :value="
                                                    (
                                                        activeFilters[
                                                            filter.name
                                                        ] || {}
                                                    ).from || ''
                                                "
                                                class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                @change="
                                                    setRangePart(
                                                        filter.name,
                                                        'from',
                                                        (
                                                            $event.target as HTMLInputElement
                                                        ).value,
                                                    )
                                                "
                                            />
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >–</span
                                            >
                                            <input
                                                type="date"
                                                :value="
                                                    (
                                                        activeFilters[
                                                            filter.name
                                                        ] || {}
                                                    ).to || ''
                                                "
                                                class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                @change="
                                                    setRangePart(
                                                        filter.name,
                                                        'to',
                                                        (
                                                            $event.target as HTMLInputElement
                                                        ).value,
                                                    )
                                                "
                                            />
                                        </div>

                                        <!-- Number range -->
                                        <div
                                            v-if="
                                                filter.type === 'number-range'
                                            "
                                            class="gap-2 flex items-center"
                                        >
                                            <input
                                                type="number"
                                                :placeholder="t('kinetix.min')"
                                                :value="
                                                    (
                                                        activeFilters[
                                                            filter.name
                                                        ] || {}
                                                    ).min ?? ''
                                                "
                                                class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                @input="
                                                    setRangePart(
                                                        filter.name,
                                                        'min',
                                                        (
                                                            $event.target as HTMLInputElement
                                                        ).value,
                                                    )
                                                "
                                            />
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >–</span
                                            >
                                            <input
                                                type="number"
                                                :placeholder="t('kinetix.max')"
                                                :value="
                                                    (
                                                        activeFilters[
                                                            filter.name
                                                        ] || {}
                                                    ).max ?? ''
                                                "
                                                class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                @input="
                                                    setRangePart(
                                                        filter.name,
                                                        'max',
                                                        (
                                                            $event.target as HTMLInputElement
                                                        ).value,
                                                    )
                                                "
                                            />
                                        </div>

                                        <!-- Multi-select -->
                                        <KinetixCheckboxList
                                            v-if="
                                                filter.type === 'multi-select'
                                            "
                                            :value="
                                                activeFilters[filter.name] || []
                                            "
                                            :options="filter.options"
                                            :searchable="filter.isSearchable"
                                            :search-token="filter.searchToken"
                                            @update:value="
                                                setFilter(filter.name, $event)
                                            "
                                        />
                                    </div>
                                </div>
                            </PopoverContent>
                        </PopoverPortal>
                    </PopoverRoot>
                </div>

                <!-- Columns Toggler Dropdown -->
                <div v-if="table.columns.some((c) => c.isToggleable)">
                    <PopoverRoot v-model:open="showColumns">
                        <PopoverTrigger as-child>
                            <button
                                :class="
                                    buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })
                                "
                            >
                                <SlidersHorizontal class="h-3.5 w-3.5" />
                                {{ t('kinetix.columns') }}
                            </button>
                        </PopoverTrigger>

                        <PopoverPortal>
                            <PopoverContent
                                align="end"
                                :side-offset="4"
                                class="w-56 rounded-lg p-3 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 border border-border bg-popover outline-none"
                            >
                                <div
                                    class="text-xs font-bold pb-2 mb-2 tracking-wider border-b border-border text-foreground uppercase"
                                >
                                    {{ t('kinetix.toggle_columns') }}
                                </div>
                                <div class="space-y-2 max-h-60 overflow-y-auto">
                                    <div
                                        v-for="col in table.columns.filter(
                                            (c) => c.isToggleable,
                                        )"
                                        :key="col.name"
                                        class="gap-2 py-0.5 rounded px-1.5 flex items-center hover:bg-accent"
                                    >
                                        <KinetixCheckbox
                                            :id="'col-' + col.name"
                                            :checked="isColumnVisible(col.name)"
                                            @change="toggleColumn(col.name)"
                                        />
                                        <label
                                            :for="'col-' + col.name"
                                            class="text-xs py-1 flex-1 cursor-pointer text-foreground select-none"
                                        >
                                            {{ col.label }}
                                        </label>
                                    </div>
                                </div>
                            </PopoverContent>
                        </PopoverPortal>
                    </PopoverRoot>
                </div>
            </div>
        </div>

        <!-- Bulk action bar (visible when rows are selected) -->
        <div
            v-if="table.bulkActions.length > 0 && selectionCount > 0"
            class="gap-3 px-6 py-3 flex flex-wrap items-center border-b border-border bg-muted/40"
        >
            <span class="text-xs font-semibold text-muted-foreground">
                {{ t('kinetix.selected', { count: selectionCount }) }}
            </span>
            <div class="gap-2 flex flex-wrap items-center">
                <button
                    v-for="(action, i) in table.bulkActions"
                    :key="i"
                    type="button"
                    :class="primaryActionClass(action)"
                    @click="requestBulkAction(action)"
                >
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon"
                    />
                    {{ action.label }}
                </button>
            </div>
            <button
                type="button"
                class="text-xs ml-auto text-muted-foreground underline-offset-4 transition-colors outline-none hover:text-foreground focus-visible:underline"
                @click="clearSelection"
            >
                {{ t('kinetix.clear_selection') }}
            </button>
        </div>

        <!-- HTML Table -->
        <div class="kinetix-scroll-x overflow-x-auto">
            <table class="min-w-full divide-y divide-border">
                <KinetixTableHead
                    :columns-to-render="columnsToRender"
                    :sort="table.state.sort"
                    :direction="table.state.direction"
                    :has-bulk-actions="table.bulkActions.length > 0"
                    :has-record-actions="table.recordActions.length > 0"
                    :all-on-page-selected="allOnPageSelected"
                    :sticky-actions="table.stickyActions"
                    :reorderable="table.reorderable"
                    @toggle-all-on-page="toggleAllOnPage"
                    @toggle-sort="toggleSort"
                />
                <tbody
                    class="divide-y divide-border"
                    :class="{ 'divide-none': table.isStriped }"
                >
                    <tr
                        v-for="(record, rowIndex) in rows"
                        :key="record.id"
                        class="group transition-colors"
                        :data-state="
                            isRowSelected(record.id) ? 'selected' : undefined
                        "
                        :draggable="table.reorderable || undefined"
                        :class="[
                            record.recordUrl ? 'cursor-pointer' : '',
                            table.isStriped && rowIndex % 2 === 1
                                ? 'bg-muted/30'
                                : 'bg-transparent',
                            record.recordUrl
                                ? 'hover:bg-muted/40'
                                : 'hover:bg-muted/30',
                            'data-[state=selected]:bg-muted',
                        ]"
                        @click="handleRowClick(record, $event)"
                        @dragstart="table.reorderable && onDragStart(rowIndex)"
                        @dragover="
                            table.reorderable && onDragOver(rowIndex, $event)
                        "
                        @drop="table.reorderable && onDrop()"
                    >
                        <td
                            v-if="table.reorderable"
                            class="w-8 px-2 py-4 text-muted-foreground"
                            @click.stop
                        >
                            <GripVertical
                                class="size-4 cursor-grab active:cursor-grabbing"
                            />
                        </td>
                        <td
                            v-if="table.bulkActions.length > 0"
                            class="w-10 px-4 py-4"
                            @click.stop
                        >
                            <KinetixCheckbox
                                :checked="isRowSelected(record.id)"
                                @change="toggleRow(record.id, $event)"
                            />
                        </td>
                        <td
                            v-for="col in columnsToRender"
                            :key="col.name"
                            class="px-6 py-4 text-sm font-medium whitespace-nowrap"
                            :class="[
                                col.alignment === 'center' ? 'text-center' : '',
                                col.alignment === 'right'
                                    ? 'text-right'
                                    : 'text-left',
                                col.type === 'text' && !col.isBadge
                                    ? 'text-foreground'
                                    : '',
                            ]"
                        >
                            <slot
                                :name="`cell-${col.name}`"
                                :col="col"
                                :record="record"
                                :value="record.values[col.name]"
                                :row-index="rowIndex"
                            >
                                <KinetixTableCell
                                    :col="col"
                                    :record="record"
                                    :row-index="rowIndex"
                                    @update-cell="updateCell"
                                    @copy-to-clipboard="copyToClipboard"
                                />
                            </slot>
                        </td>

                        <!-- Record Row Actions -->
                        <td
                            v-if="table.recordActions.length > 0"
                            class="px-6 py-4 text-sm font-medium text-right whitespace-nowrap"
                            :class="
                                table.stickyActions
                                    ? 'right-0 sticky z-10 border-l border-border bg-card group-hover:bg-muted/30'
                                    : ''
                            "
                        >
                            <div class="gap-2 flex items-center justify-end">
                                <template
                                    v-for="(action, idx) in record.actions"
                                    :key="idx"
                                >
                                    <KinetixActionDropdown
                                        v-if="action.type === 'group'"
                                        :group="action"
                                    />
                                    <button
                                        v-else
                                        :class="recordActionClass(action)"
                                        :title="
                                            action.isIconButton
                                                ? action.label
                                                : undefined
                                        "
                                        :aria-label="
                                            action.isIconButton
                                                ? action.label
                                                : undefined
                                        "
                                        @click.stop="handleActionClick(action)"
                                    >
                                        <component
                                            :is="resolveIcon(action.icon)"
                                            v-if="action.icon"
                                        />
                                        <span v-if="!action.isIconButton">{{
                                            action.label
                                        }}</span>
                                    </button>
                                </template>
                            </div>
                        </td>
                    </tr>

                    <!-- Empty State -->
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="
                                columnsToRender.length +
                                (table.recordActions.length > 0 ? 1 : 0) +
                                (table.bulkActions.length > 0 ? 1 : 0) +
                                (table.reorderable ? 1 : 0)
                            "
                            class="px-6 py-12 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.no_records_found') }}
                        </td>
                    </tr>
                </tbody>

                <!-- Summary footer -->
                <tfoot
                    v-if="table.hasSummaries"
                    class="font-semibold border-t-2 border-border bg-muted/40"
                >
                    <tr>
                        <td v-if="table.reorderable" class="w-8 px-2 py-3" />
                        <td
                            v-if="table.bulkActions.length > 0"
                            class="w-10 px-4 py-3"
                        />
                        <td
                            v-for="(col, ci) in columnsToRender"
                            :key="col.name"
                            class="px-6 py-3 text-sm whitespace-nowrap"
                            :class="[
                                col.alignment === 'center' ? 'text-center' : '',
                                col.alignment === 'right'
                                    ? 'text-right'
                                    : 'text-left',
                            ]"
                        >
                            <template v-if="table.summaries?.[col.name]">
                                <div
                                    v-for="(s, si) in table.summaries?.[
                                        col.name
                                    ]"
                                    :key="si"
                                >
                                    <span
                                        v-if="s.label"
                                        class="text-muted-foreground"
                                        >{{ s.label }}: </span
                                    >{{ s.value }}
                                </div>
                            </template>
                            <span
                                v-else-if="ci === 0"
                                class="text-muted-foreground"
                            >
                                {{ t('kinetix.summary_total') }}
                            </span>
                        </td>
                        <td
                            v-if="table.recordActions.length > 0"
                            class="px-6 py-3"
                        />
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Footer actions bar (e.g. Export all) -->
        <div
            v-if="(table.footerActions?.length ?? 0) > 0"
            class="gap-2 px-4 py-3 flex flex-wrap items-center border-t border-border"
        >
            <template
                v-for="(action, i) in table.footerActions"
                :key="`footer-${i}`"
            >
                <KinetixActionDropdown
                    v-if="action.type === 'group'"
                    :group="action"
                />
                <button
                    v-else
                    type="button"
                    :class="primaryActionClass(action)"
                    @click="handleActionClick(action)"
                >
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon"
                    />
                    {{ action.label }}
                </button>
            </template>
        </div>

        <!-- Footer Pagination -->
        <KinetixTablePagination
            v-if="table.isPaginated && table.pagination"
            :pagination="table.pagination"
            :pagination-page-options="table.paginationPageOptions"
            @change-page="triggerReload({ page: $event })"
            @change-per-page="triggerReload({ perPage: $event, page: 1 })"
        />

        <!-- Confirmation modal for actions that require it -->
        <KinetixConfirmModal
            v-model:open="isConfirmOpen"
            :heading="pendingAction?.modalHeading"
            :description="pendingAction?.modalDescription"
            :icon="pendingAction?.modalIcon"
            :color="pendingAction?.color"
            :submit-label="pendingAction?.modalSubmitActionLabel"
            :cancel-label="pendingAction?.modalCancelActionLabel"
            @confirm="onConfirmAction"
            @cancel="onCancelAction"
        />

        <!-- Confirmation modal for bulk actions -->
        <KinetixConfirmModal
            v-model:open="isBulkConfirmOpen"
            :heading="bulkPending?.modalHeading"
            :description="bulkPending?.modalDescription"
            :icon="bulkPending?.modalIcon"
            :color="bulkPending?.color"
            :submit-label="bulkPending?.modalSubmitActionLabel"
            :cancel-label="bulkPending?.modalCancelActionLabel"
            @confirm="onBulkConfirm"
            @cancel="onBulkCancel"
        />
    </div>
</template>

<style scoped>
.kinetix-table-wrapper {
    width: 100%;
}

/* shadcn-style scrollbar: thin, rounded, muted thumb (tokens resolve in
   shadcn-vue v4 apps and via the published kinetix.css fallback). */
.kinetix-scroll-x {
    scrollbar-width: thin;
    scrollbar-color: var(--color-border, #d4d4d8) transparent;
}
.kinetix-scroll-x::-webkit-scrollbar {
    height: 0.625rem;
    width: 0.625rem;
}
.kinetix-scroll-x::-webkit-scrollbar-track {
    background: transparent;
}
.kinetix-scroll-x::-webkit-scrollbar-thumb {
    border-radius: 9999px;
    border: 2px solid transparent;
    background-clip: content-box;
    background-color: var(--color-border, #d4d4d8);
}
.kinetix-scroll-x:hover::-webkit-scrollbar-thumb {
    background-color: var(--color-muted-foreground, #a1a1aa);
}
</style>
