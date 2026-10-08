<script setup lang="ts">
import { router, usePage, usePoll } from '@inertiajs/vue3';
import { ChevronRight, GripVertical } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { KINETIX_DROP_PREVIEW_CLASS } from '@/composables/kinetixDragStyles';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { useKinetixColumnVisibility } from '@/composables/useKinetixColumnVisibility';
import { useKinetixFormActions } from '@/composables/useKinetixFormActions';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import { isIconOnlyAction, resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixRecordModals } from '@/composables/useKinetixRecordModals';
import { useKinetixRowClick } from '@/composables/useKinetixRowClick';
import { useKinetixRowSelection } from '@/composables/useKinetixRowSelection';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useKinetixShadcnVariants';
import { useKinetixTableAggregates } from '@/composables/useKinetixTableAggregates';
import { useKinetixTableGroups } from '@/composables/useKinetixTableGroups';
import { useKinetixTableQuery } from '@/composables/useKinetixTableQuery';
import { useKinetixTableReorder } from '@/composables/useKinetixTableReorder';
import type {
    KinetixAction,
    KinetixTableData,
    KinetixTableRecord,
} from '@/types/kinetix';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixButton from './KinetixButton.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixEmptyState from './KinetixEmptyState.vue';
import KinetixForm from './KinetixForm.vue';
import KinetixInfolist from './KinetixInfolist.vue';
import KinetixModal from './primitives/KinetixModal.vue';
import KinetixTableBulkBar from './Table/KinetixTableBulkBar.vue';
import KinetixTableCell from './Table/KinetixTableCell.vue';
import KinetixTableHead from './Table/KinetixTableHead.vue';
import KinetixTablePagination from './Table/KinetixTablePagination.vue';
import KinetixTableStats from './Table/KinetixTableStats.vue';
import KinetixTableSummaryRow from './Table/KinetixTableSummaryRow.vue';
import KinetixTableToolbar from './Table/KinetixTableToolbar.vue';

const props = defineProps<{
    table: KinetixTableData;
}>();

// The root is a wrapper holding the stat cards plus the table card, so attrs are
// forwarded explicitly to the card — a consumer's `class` keeps landing where it
// always did rather than on the new wrapper.
defineOptions({ inheritAttrs: false });

// Client-side ("TanStack") variant is loaded lazily so its dependency stays
// code-split off the default server-driven path — only tables that opt into
// `Table::clientSide()` ever fetch it.
const KinetixDataTable = defineAsyncComponent(
    () => import('./KinetixDataTable.vue'),
);

const { t } = useI18n();
const page = usePage();
const routePrefix = computed(
    () => (page.props.kinetix_config as any)?.route_prefix ?? '_kinetix',
);

// shadcn-vue (new-york) button UI for row actions. Record actions default to a
// light `ghost` so rows stay clean; an explicit action color is always honored.
const recordActionClass = (action: {
    icon?: string | null;
    color?: string | null;
    isIconButton?: boolean;
}) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'ghost',
        // `isIconOnlyAction`, not `isIconButton`: an icon button whose icon does
        // not resolve falls back to its label, so it must not be icon-sized.
        size: isIconOnlyAction(action) ? 'icon-sm' : 'sm',
    });

// --- Search + filters state --------------------------------------------------
const searchQuery = ref(props.table.state.search);
const activeFilters = ref<Record<string, any>>({
    ...props.table.state.filters,
});

// --- Screen-reader result announcements ---------------------------------------
// Search/filter/sort/page changes reload the rows with no focus change, so the
// new result count is announced through the shared live region. Keyed off the
// table STATE (not the records array) so polling refreshes stay silent.
const { announce } = useKinetixAnnounce();

const resultsAnnouncement = (): string => {
    const pagination = props.table.pagination;

    if (props.table.records.length === 0) {
        return t('kinetix.no_records');
    }

    if (pagination?.total !== null && pagination?.total !== undefined) {
        return t('kinetix.showing_records', {
            from: pagination.from,
            to: pagination.to,
            total: pagination.total,
        });
    }

    if (pagination?.from !== null && pagination?.from !== undefined) {
        return t('kinetix.showing_range', {
            from: pagination.from,
            to: pagination.to,
        });
    }

    return t('kinetix.results_count', {
        count: props.table.records.length,
    });
};

// An array OF getters (not one getter returning an array): Vue then compares
// each source by value, so a poll refresh that only swaps `records` stays
// silent while any state/page change announces.
watch(
    [
        () => JSON.stringify(props.table.state),
        () => props.table.pagination?.currentPage,
    ],
    () => {
        announce(resultsAnnouncement());
    },
);

// --- Column visibility -------------------------------------------------------
const { isColumnVisible, toggleColumn, columnsToRender, visibleColumnNames } =
    useKinetixColumnVisibility(() => props.table.columns);

// --- Server reload orchestration (namespaced query + debounced search) -------
const { triggerReload, onSearchInput } = useKinetixTableQuery({
    table: () => props.table,
    searchQuery,
    activeFilters,
});

// --- Saved views -------------------------------------------------------------
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

// --- Sorting -----------------------------------------------------------------
const toggleSort = (name: string) => {
    if (props.table.state.sort === name) {
        const nextDir = props.table.state.direction === 'asc' ? 'desc' : 'asc';
        triggerReload({ sort: name, direction: nextDir });

        return;
    }

    triggerReload({ sort: name, direction: 'asc' });
};

// --- Filters -----------------------------------------------------------------
const setFilter = (name: string, value: any) => {
    activeFilters.value[name] = value;
    triggerReload({ filters: activeFilters.value, page: 1 });
};

const clearFilters = () => {
    activeFilters.value = {};
    triggerReload({ filters: {}, page: 1 });
};

// --- Record action confirmation ----------------------------------------------
const {
    pendingAction,
    isConfirmOpen,
    processing: actionProcessing,
    processingAction: actionProcessingName,
    requestAction,
    confirm: onConfirmAction,
    cancel: onCancelAction,
} = useActionConfirmation();

// --- In-table modal CRUD (simple resources) ----------------------------------
// When the table opts into recordModals, actions flagged `modal` open a
// create/edit/view/delete modal hosted here instead of navigating/dispatching.
const {
    isFormOpen: isRecordFormOpen,
    isInfolistOpen: isRecordInfolistOpen,
    isDeleteOpen: isRecordDeleteOpen,
    isEditing: isRecordEditing,
    isLoading: isRecordLoading,
    processing: recordProcessing,
    activeForm: recordForm,
    activeInfolist: recordInfolist,
    activeLabel: recordLabel,
    pendingDelete: recordPendingDelete,
    handleModalAction,
    submitForm: submitRecordForm,
    confirmDelete: confirmRecordDelete,
    cancelDelete: cancelRecordDelete,
    closeForm: closeRecordForm,
    closeInfolist: closeRecordInfolist,
} = useKinetixRecordModals({
    config: () => props.table.recordModals,
    routePrefix: () => routePrefix.value,
});

// --- Server-side form actions (FormAction) -----------------------------------
// A record/toolbar action flagged `isFormAction` opens a modal hosting its
// KinetixForm; submit POSTs the values to the signed form-action endpoint,
// which validates server-side and reloads the table.
const {
    isOpen: isFormActionOpen,
    processing: formActionProcessing,
    activeForm: formActionForm,
    activeAction: formActionAction,
    handleFormAction,
    submitForm: submitFormAction,
    closeForm: closeFormAction,
} = useKinetixFormActions({
    descriptor: () => props.table.formActionDescriptor,
    routePrefix: () => routePrefix.value,
});

// Unique per table instance: the form-action modal's PINNED footer submit
// button lives outside the <form>, so it targets it via the native `form`
// attribute — the actions stay visible while a long schema scrolls.
const formActionFormId = `kinetix-form-action-${useId()}`;

// Unique per table instance: the modal's PINNED footer submit button lives
// outside the <form>, so it targets it via the native `form` attribute — the
// actions stay visible while a long schema scrolls.
const recordFormId = `kinetix-record-form-${useId()}`;

// A per-row action carries its `record` so a `dispatchEvent` action's listener
// (or an inertiaVisit/httpRequest body) receives it; toolbar/footer actions
// pass none. Modal actions are intercepted first and handled locally.
const handleActionClick = (
    action: KinetixAction,
    record?: KinetixTableRecord,
) => {
    if (handleModalAction(action, record)) {
        return;
    }

    if (handleFormAction(action, record)) {
        return;
    }

    requestAction(action, record ? { record } : {});
};

// --- Row click ---------------------------------------------------------------
// The server names the target (`recordUrl` / `recordAction`, inferred from the
// row's view→edit actions unless configured). A row action runs through the
// same handler as its button, so a modal `view` opens the same modal.
const { isRowClickable, handleRowClick, handleRowKeydown } = useKinetixRowClick(
    {
        runAction: (action, record) => handleActionClick(action, record),
    },
);

// --- Row selection + bulk actions --------------------------------------------
const {
    selectionCount,
    allOnPageSelected,
    isRowSelected,
    toggleRow,
    toggleAllOnPage,
    clearSelection,
    bulkPending,
    isBulkConfirmOpen,
    bulkProcessing,
    requestBulkAction,
    onBulkConfirm,
    onBulkCancel,
} = useKinetixRowSelection(() => props.table.records, {
    descriptor: () => props.table.bulkDescriptor,
    routePrefix: () => routePrefix.value,
});

// --- Inline cell editing -----------------------------------------------------
// Bumped when the server refuses a value: the cells re-mount from the record's
// real value. An input keeps what was typed in its DOM, and the props never
// changed, so nothing else would put the saved value back.
const cellRevision = ref(0);

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
        // A refused value (422: the column's rules; 403: no access) must not
        // stay on screen looking saved: say why, and show the stored value.
        toast.error(
            e instanceof Error && e.message
                ? e.message
                : t('kinetix.table_value_invalid'),
        );
        cellRevision.value++;
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
const poll = usePoll(pollInterval || 60000, {}, { autoStart: false });

// Aggregates (stats + summaries). Inline ones are read live from the prop;
// deferred ones (Table::deferStats()) arrive empty with a signed descriptor and
// are fetched after first paint — and again on every reload, so the totals
// always describe the rows on screen.
const aggregates = useKinetixTableAggregates({
    descriptor: () =>
        props.table.deferStats ? props.table.aggregatesDescriptor : null,
    initial: () => ({
        stats: props.table.stats ?? [],
        summaries: props.table.summaries ?? {},
        hasSummaries: !!props.table.hasSummaries,
    }),
});

// Guards <Teleport to="body"> so record modals only mount client-side (SSR-safe),
// matching KinetixConfirmModal.
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;

    if (pollInterval > 0) {
        poll.start();
    }

    // Fetch deferred aggregates once the table is on screen (no-op otherwise).
    if (props.table.deferStats) {
        void aggregates.load();
    }
});

// Every search, filter, sort, page change or poll swaps the table prop (with
// preserveState the component stays mounted): refetch deferred aggregates for
// the new window. load() aborts the previous request.
watch(
    () => props.table,
    () => {
        if (props.table.deferStats) {
            void aggregates.load();
        }
    },
);

onBeforeUnmount(() => aggregates.cancel());

// --- Row reordering ----------------------------------------------------------
const {
    rows,
    draggingId,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    moveRowBy,
} = useKinetixTableReorder({
    records: () => props.table.records,
    reorderable: () => !!props.table.reorderable,
    model: () => props.table.model,
    routePrefix: () => routePrefix.value,
});

// Keyboard alternative to dragging: arrows on the focused grip move the row.
// Focus travels with the button (rows are keyed by id, so Vue moves the node).
const moveRowKeyboard = (index: number, delta: number): void => {
    const target = moveRowBy(index, delta);

    if (target === null) {
        return;
    }

    announce(
        t('kinetix.row_moved', {
            position: target + 1,
            total: rows.value.length,
        }),
    );
};

// --- Row grouping ------------------------------------------------------------
// When Table::defaultGroup() is active the server orders same-group rows
// contiguously and tags each with groupKey/groupLabel; this slices them into
// collapsible sections. Collapse state is local (survives polling, resets on
// full reload), matching column visibility.
const { isGrouped, renderItems, toggleGroup } = useKinetixTableGroups(
    () => props.table,
    () => rows.value,
);

// Drag reorder is off while grouped (a row can't leave its bucket). ONE flag
// decides it everywhere — head, body, footer and every colspan — or the grip
// column exists in some rows and not others and every cell shifts under the
// wrong header.
const canReorder = computed(
    () => !!props.table.reorderable && !isGrouped.value,
);

// Full width for a group header row's single cell: data columns + every
// leading/trailing utility column the body renders.
const totalColumnSpan = computed(
    () =>
        columnsToRender.value.length +
        (props.table.recordActions.length > 0 ? 1 : 0) +
        (props.table.bulkActions.length > 0 ? 1 : 0) +
        (canReorder.value ? 1 : 0),
);
</script>

<template>
    <div class="kinetix-table-root min-w-0 max-w-full">
        <!-- KPI cards (Table::stats()), above the table in both variants. -->
        <!-- Deferred: show a skeleton row until the aggregates land. -->
        <div
            v-if="
                table.deferStats &&
                aggregates.loading.value &&
                !aggregates.loaded.value
            "
            class="mb-4 gap-4 sm:grid-cols-2 lg:grid-cols-4 grid grid-cols-1"
            aria-hidden="true"
        >
            <div
                v-for="n in 4"
                :key="n"
                class="h-24 animate-pulse rounded-xl border border-border bg-muted/40"
            />
        </div>
        <KinetixTableStats
            v-else-if="aggregates.stats.value.length"
            :stats="aggregates.stats.value"
        />

        <!-- Client-side variant: full row set rendered by the TanStack engine. -->
        <KinetixDataTable
            v-if="table.clientSide"
            v-bind="$attrs"
            :table="table"
        />

        <!-- Default: server-driven table (search/sort/filter/paginate via Inertia). -->
        <div
            v-else
            v-bind="$attrs"
            data-slot="card"
            class="kinetix-table-wrapper backdrop-blur-sm rounded-xl shadow-sm min-w-0 flex max-w-full flex-col overflow-hidden border border-border bg-card text-card-foreground"
        >
            <KinetixTableToolbar
                v-model:search-query="searchQuery"
                :table="table"
                :active-filters="activeFilters"
                :current-view-state="currentViewState"
                :is-column-visible="isColumnVisible"
                :processing="actionProcessing"
                :processing-action="actionProcessingName"
                @search-input="onSearchInput"
                @apply-view="applyView"
                @action-click="handleActionClick"
                @set-filter="setFilter"
                @clear-filters="clearFilters"
                @toggle-column="toggleColumn"
            />

            <!-- Bulk action bar (visible when rows are selected) -->
            <KinetixTableBulkBar
                v-if="table.bulkActions.length > 0 && selectionCount > 0"
                :bulk-actions="table.bulkActions"
                :selection-count="selectionCount"
                :processing="bulkProcessing"
                @run-action="requestBulkAction"
                @clear="clearSelection"
            />

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
                        :reorderable="canReorder"
                        @toggle-all-on-page="toggleAllOnPage"
                        @toggle-sort="toggleSort"
                    />
                    <tbody
                        class="divide-y divide-border"
                        :class="{ 'divide-none': table.isStriped }"
                    >
                        <!-- One loop over renderItems covers both modes: when a
                         group is active it interleaves collapsible header rows
                         with their (possibly hidden) data rows; ungrouped it is
                         a flat list of data rows. Reorder/selection stay on the
                         flat path (grouping fixes the order). -->
                        <template
                            v-for="item in renderItems"
                            :key="
                                item.type === 'header'
                                    ? `group-${item.key}`
                                    : item.record.id
                            "
                        >
                            <!-- Collapsible group header spanning the full row. -->
                            <tr
                                v-if="item.type === 'header'"
                                class="bg-muted/40 transition-colors"
                            >
                                <td :colspan="totalColumnSpan" class="p-0">
                                    <component
                                        :is="
                                            item.collapsible ? 'button' : 'div'
                                        "
                                        :type="
                                            item.collapsible
                                                ? 'button'
                                                : undefined
                                        "
                                        class="gap-2 px-6 py-2.5 text-sm font-semibold flex w-full items-center text-left text-foreground outline-none"
                                        :class="
                                            item.collapsible
                                                ? 'cursor-pointer hover:bg-muted/60 focus-visible:ring-[3px] focus-visible:ring-ring/50'
                                                : ''
                                        "
                                        :aria-expanded="
                                            item.collapsible
                                                ? !item.collapsed
                                                : undefined
                                        "
                                        @click="
                                            item.collapsible &&
                                            toggleGroup(item.key)
                                        "
                                    >
                                        <ChevronRight
                                            v-if="item.collapsible"
                                            class="size-4 shrink-0 text-muted-foreground transition-transform"
                                            :class="
                                                item.collapsed
                                                    ? ''
                                                    : 'rotate-90'
                                            "
                                            aria-hidden="true"
                                        />
                                        <span>{{
                                            item.label ||
                                            t('kinetix.group_none')
                                        }}</span>
                                        <span
                                            class="text-xs font-normal text-muted-foreground"
                                            >({{ item.count }})</span
                                        >
                                    </component>
                                </td>
                            </tr>

                            <tr
                                v-else
                                class="group transition-colors"
                                :data-state="
                                    isRowSelected(item.record.id)
                                        ? 'selected'
                                        : undefined
                                "
                                :draggable="canReorder || undefined"
                                :tabindex="
                                    isRowClickable(item.record) ? 0 : undefined
                                "
                                :class="[
                                    table.isStriped && item.index % 2 === 1
                                        ? 'bg-muted/30'
                                        : 'bg-transparent',
                                    // A <tr> can't paint a box-shadow ring under
                                    // border-collapse, so the focus indicator is an
                                    // inset outline + the hover tint.
                                    isRowClickable(item.record)
                                        ? 'cursor-pointer hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring/50'
                                        : 'hover:bg-muted/30',
                                    'data-[state=selected]:bg-muted',
                                    draggingId != null &&
                                    draggingId === item.record.id
                                        ? KINETIX_DROP_PREVIEW_CLASS
                                        : '',
                                ]"
                                @click="handleRowClick(item.record, $event)"
                                @keydown="handleRowKeydown(item.record, $event)"
                                @dragstart="
                                    canReorder && onDragStart(item.index)
                                "
                                @dragover="
                                    canReorder && onDragOver(item.index, $event)
                                "
                                @drop="canReorder && onDrop()"
                                @dragend="canReorder && onDragEnd()"
                            >
                                <td
                                    v-if="canReorder"
                                    class="w-8 px-2 py-4 text-muted-foreground"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        :aria-label="t('kinetix.reorder')"
                                        class="p-0.5 flex cursor-grab items-center justify-center rounded-md transition-colors outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 active:cursor-grabbing"
                                        @keydown.up.prevent="
                                            moveRowKeyboard(item.index, -1)
                                        "
                                        @keydown.down.prevent="
                                            moveRowKeyboard(item.index, 1)
                                        "
                                    >
                                        <GripVertical
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </td>
                                <td
                                    v-if="table.bulkActions.length > 0"
                                    class="w-10 px-4 py-4"
                                    @click.stop
                                >
                                    <KinetixCheckbox
                                        :checked="isRowSelected(item.record.id)"
                                        :aria-label="t('kinetix.select_row')"
                                        @change="
                                            toggleRow(item.record.id, $event)
                                        "
                                    />
                                </td>
                                <td
                                    v-for="col in columnsToRender"
                                    :key="col.name"
                                    class="px-6 py-4 text-sm font-medium whitespace-nowrap"
                                    :class="[
                                        col.alignment === 'center'
                                            ? 'text-center'
                                            : '',
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
                                        :record="item.record"
                                        :value="item.record.values[col.name]"
                                        :row-index="item.index"
                                    >
                                        <KinetixTableCell
                                            :key="cellRevision"
                                            :col="col"
                                            :record="item.record"
                                            :row-index="item.index"
                                            @update-cell="updateCell"
                                        />
                                    </slot>
                                </td>

                                <!-- Record row actions. The cell swallows clicks so
                                 the "⋯" trigger, its items and the buttons never
                                 double as a row click. -->
                                <td
                                    v-if="table.recordActions.length > 0"
                                    class="px-6 py-4 text-sm font-medium text-right whitespace-nowrap"
                                    :class="
                                        table.stickyActions
                                            ? 'right-0 sticky z-10 border-l border-border bg-card group-hover:bg-muted/30'
                                            : ''
                                    "
                                    @click.stop
                                >
                                    <div
                                        class="gap-2 flex items-center justify-end"
                                    >
                                        <template
                                            v-for="(action, idx) in item.record
                                                .actions"
                                            :key="idx"
                                        >
                                            <KinetixActionDropdown
                                                v-if="action.type === 'group'"
                                                :group="action"
                                                :record="item.record"
                                                @action-click="
                                                    handleActionClick
                                                "
                                            />
                                            <button
                                                v-else
                                                :disabled="actionProcessing"
                                                :class="
                                                    recordActionClass(action)
                                                "
                                                :title="
                                                    isIconOnlyAction(action)
                                                        ? action.label
                                                        : undefined
                                                "
                                                :aria-label="
                                                    isIconOnlyAction(action)
                                                        ? action.label
                                                        : undefined
                                                "
                                                @click.stop="
                                                    handleActionClick(
                                                        action,
                                                        item.record,
                                                    )
                                                "
                                            >
                                                <component
                                                    :is="
                                                        resolveIcon(action.icon)
                                                    "
                                                    v-if="
                                                        resolveIcon(action.icon)
                                                    "
                                                />
                                                <span
                                                    v-if="
                                                        !isIconOnlyAction(
                                                            action,
                                                        )
                                                    "
                                                    >{{ action.label }}</span
                                                >
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <!-- Empty State: the configured card (heading /
                             description / icon / CTAs) or the default line. -->
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="
                                    columnsToRender.length +
                                    (table.recordActions.length > 0 ? 1 : 0) +
                                    (table.bulkActions.length > 0 ? 1 : 0) +
                                    (canReorder ? 1 : 0)
                                "
                                :class="
                                    table.emptyState
                                        ? 'p-4'
                                        : 'px-6 py-12 text-sm text-center text-muted-foreground'
                                "
                            >
                                <KinetixEmptyState
                                    v-if="table.emptyState"
                                    :icon="table.emptyState.icon"
                                    :title="
                                        table.emptyState.heading ??
                                        t('kinetix.no_records_found')
                                    "
                                    :description="table.emptyState.description"
                                >
                                    <template
                                        v-for="(action, i) in table.emptyState
                                            .actions"
                                        :key="`empty-${i}`"
                                    >
                                        <KinetixButton
                                            :variant="
                                                action.color
                                                    ? actionButtonVariant(
                                                          action.color,
                                                      )
                                                    : 'default'
                                            "
                                            size="sm"
                                            :disabled="actionProcessing"
                                            :loading="
                                                actionProcessing &&
                                                actionProcessingName ===
                                                    action.name
                                            "
                                            @click="handleActionClick(action)"
                                        >
                                            <template #icon>
                                                <component
                                                    :is="
                                                        resolveIcon(action.icon)
                                                    "
                                                    v-if="
                                                        resolveIcon(action.icon)
                                                    "
                                                />
                                            </template>
                                            {{ action.label }}
                                        </KinetixButton>
                                    </template>
                                </KinetixEmptyState>
                                <template v-else>
                                    {{ t('kinetix.no_records_found') }}
                                </template>
                            </td>
                        </tr>
                    </tbody>

                    <!-- Summary footer -->
                    <KinetixTableSummaryRow
                        v-if="aggregates.hasSummaries.value"
                        :columns-to-render="columnsToRender"
                        :summaries="aggregates.summaries.value"
                        :reorderable="canReorder"
                        :has-bulk-actions="table.bulkActions.length > 0"
                        :has-record-actions="table.recordActions.length > 0"
                    />
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
                        @action-click="handleActionClick"
                    />
                    <KinetixButton
                        v-else
                        :variant="
                            action.color
                                ? actionButtonVariant(action.color)
                                : 'default'
                        "
                        size="sm"
                        :disabled="actionProcessing"
                        :loading="
                            actionProcessing &&
                            actionProcessingName === action.name
                        "
                        @click="handleActionClick(action)"
                    >
                        <template #icon>
                            <component
                                :is="resolveIcon(action.icon)"
                                v-if="resolveIcon(action.icon)"
                            />
                        </template>
                        {{ action.label }}
                    </KinetixButton>
                </template>
            </div>

            <!-- Footer Pagination -->
            <KinetixTablePagination
                v-if="table.isPaginated && table.pagination"
                :pagination="table.pagination"
                :pagination-page-options="table.paginationPageOptions"
                @change-page="triggerReload({ page: $event })"
                @change-cursor="triggerReload({ cursor: $event })"
                @change-per-page="
                    triggerReload({ perPage: $event, page: 1, cursor: null })
                "
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
                :processing="actionProcessing"
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
                :processing="bulkProcessing"
                @confirm="onBulkConfirm"
                @cancel="onBulkCancel"
            />

            <!-- Simple-resource create/edit + view modals on the shared
             KinetixModal shell (shadcn v4 dialog line). The form is fetched
             fresh from the server for edits by default; create opens
             instantly from the shipped blueprint. -->
            <KinetixModal
                :open="isRecordFormOpen"
                :title="
                    recordLabel ||
                    (isRecordEditing ? t('kinetix.edit') : t('kinetix.create'))
                "
                max-width="sm:max-w-2xl"
                :processing="recordProcessing"
                scroll-body
                placement="top"
                @update:open="(value) => !value && closeRecordForm()"
            >
                <div v-if="isRecordLoading" class="space-y-4">
                    <div class="h-9 animate-pulse rounded-md bg-muted"></div>
                    <div class="h-9 animate-pulse rounded-md bg-muted"></div>
                    <div
                        class="h-9 animate-pulse w-2/3 rounded-md bg-muted"
                    ></div>
                </div>

                <!-- The modal IS the surface — flat drops Section card chrome. -->
                <KinetixForm
                    v-else-if="recordForm"
                    :id="recordFormId"
                    :form="recordForm"
                    flat
                    @submit="submitRecordForm"
                >
                    <template #default><span class="hidden"></span></template>
                </KinetixForm>

                <!-- DRY: the SHARED KinetixButton — and the actions live in the
                     modal's pinned footer, so a long schema never scrolls Save
                     out of reach. -->
                <template #footer>
                    <KinetixButton
                        variant="outline"
                        size="sm"
                        :disabled="recordProcessing"
                        @click="closeRecordForm"
                    >
                        {{ t('kinetix.cancel') }}
                    </KinetixButton>
                    <KinetixButton
                        v-if="recordForm"
                        type="submit"
                        size="sm"
                        :form="recordFormId"
                        :loading="recordProcessing"
                    >
                        {{ t('kinetix.save') }}
                    </KinetixButton>
                </template>
            </KinetixModal>

            <!-- Server-side form action modal (FormAction): hosts the action's
                 KinetixForm; submit POSTs to the signed form-action endpoint,
                 which validates server-side and reloads the table. -->
            <KinetixModal
                :open="isFormActionOpen"
                :title="
                    formActionAction?.modalHeading ||
                    formActionAction?.label ||
                    ''
                "
                :description="formActionAction?.modalDescription"
                max-width="sm:max-w-2xl"
                :processing="formActionProcessing"
                scroll-body
                placement="top"
                @update:open="(value) => !value && closeFormAction()"
            >
                <KinetixForm
                    v-if="formActionForm"
                    :id="formActionFormId"
                    :form="formActionForm"
                    flat
                    @submit="submitFormAction"
                >
                    <template #default><span class="hidden"></span></template>
                </KinetixForm>

                <template #footer>
                    <KinetixButton
                        variant="outline"
                        size="sm"
                        :disabled="formActionProcessing"
                        @click="closeFormAction"
                    >
                        {{
                            formActionAction?.modalCancelActionLabel ||
                            t('kinetix.cancel')
                        }}
                    </KinetixButton>
                    <KinetixButton
                        v-if="formActionForm"
                        type="submit"
                        size="sm"
                        :form="formActionFormId"
                        :loading="formActionProcessing"
                    >
                        {{
                            formActionAction?.modalSubmitActionLabel ||
                            t('kinetix.save')
                        }}
                    </KinetixButton>
                </template>
            </KinetixModal>

            <!-- Simple-resource view modal (read-only infolist, server-resolved). -->
            <KinetixModal
                :open="isRecordInfolistOpen"
                :title="recordLabel || t('kinetix.view')"
                max-width="sm:max-w-3xl"
                scroll-body
                placement="top"
                @update:open="(value) => !value && closeRecordInfolist()"
            >
                <div v-if="isRecordLoading" class="space-y-4">
                    <div
                        class="h-6 animate-pulse w-1/3 rounded-md bg-muted"
                    ></div>
                    <div class="h-24 animate-pulse rounded-md bg-muted"></div>
                </div>
                <!-- The modal IS the surface — no card-in-modal, and flat
                     drops the card chrome of any Section/Tabs in the schema. -->
                <KinetixInfolist
                    v-else-if="recordInfolist"
                    :infolist="recordInfolist"
                    :surface="false"
                    flat
                />
            </KinetixModal>

            <!-- Simple-resource delete confirmation. -->
            <KinetixConfirmModal
                v-model:open="isRecordDeleteOpen"
                :heading="
                    recordPendingDelete?.modalHeading ??
                    t('kinetix.confirm_heading')
                "
                :description="recordPendingDelete?.modalDescription"
                :icon="recordPendingDelete?.modalIcon"
                :color="recordPendingDelete?.color ?? 'danger'"
                :submit-label="
                    recordPendingDelete?.modalSubmitActionLabel ??
                    t('kinetix.delete')
                "
                :cancel-label="recordPendingDelete?.modalCancelActionLabel"
                :processing="recordProcessing"
                @confirm="confirmRecordDelete"
                @cancel="cancelRecordDelete"
            />
        </div>
    </div>
</template>

<style scoped>
.kinetix-table-wrapper {
    width: 100%;
}

/* shadcn-style scrollbar: thin, rounded, muted thumb. An app with a themed
   global scrollbar can reuse its own design by setting --kx-scrollbar-thumb /
   --kx-scrollbar-thumb-hover (anywhere up the tree); the defaults keep the
   shadcn look — tokens resolve in shadcn-vue v4 apps and via the published
   kinetix.css fallback. */
.kinetix-scroll-x {
    scrollbar-width: thin;
    scrollbar-color: var(--kx-scrollbar-thumb, var(--color-border, #d4d4d8))
        transparent;
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
    background-color: var(--kx-scrollbar-thumb, var(--color-border, #d4d4d8));
}
.kinetix-scroll-x:hover::-webkit-scrollbar-thumb {
    background-color: var(
        --kx-scrollbar-thumb-hover,
        var(--color-muted-foreground, #a1a1aa)
    );
}
</style>
