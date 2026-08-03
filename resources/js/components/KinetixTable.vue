<script setup lang="ts">
import { router, usePage, usePoll } from '@inertiajs/vue3';
import { GripVertical } from '@lucide/vue';
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { useKinetixColumnVisibility } from '@/composables/useKinetixColumnVisibility';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { useKinetixRecordModals } from '@/composables/useKinetixRecordModals';
import { useKinetixRowSelection } from '@/composables/useKinetixRowSelection';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useKinetixShadcnVariants';
import { useKinetixTableQuery } from '@/composables/useKinetixTableQuery';
import { useKinetixTableReorder } from '@/composables/useKinetixTableReorder';
import type {
    KinetixAction,
    KinetixTableData,
    KinetixTableRecord,
} from '@/types/kinetix';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixForm from './KinetixForm.vue';
import KinetixInfolist from './KinetixInfolist.vue';
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
    color?: string | null;
    isIconButton?: boolean;
}) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'ghost',
        size: action.isIconButton ? 'icon-sm' : 'sm',
    });

// --- Search + filters state --------------------------------------------------
const searchQuery = ref(props.table.state.search);
const activeFilters = ref<Record<string, any>>({
    ...props.table.state.filters,
});

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

// --- Row click ---------------------------------------------------------------
const handleRowClick = (record: KinetixTableRecord, event: MouseEvent) => {
    // Avoid redirect if clicking a button, link, checkbox, or select.
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

// --- Record action confirmation ----------------------------------------------
const {
    pendingAction,
    isConfirmOpen,
    processing: actionProcessing,
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

    requestAction(action, record ? { record } : {});
};

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
} = useKinetixRowSelection(() => props.table.records);

// --- Inline cell editing -----------------------------------------------------
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
const poll = usePoll(pollInterval || 60000, {}, { autoStart: false });

// Guards <Teleport to="body"> so record modals only mount client-side (SSR-safe),
// matching KinetixConfirmModal.
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;

    if (pollInterval > 0) {
        poll.start();
    }
});

// --- Row reordering ----------------------------------------------------------
const { rows, onDragStart, onDragOver, onDrop } = useKinetixTableReorder({
    records: () => props.table.records,
    reorderable: () => !!props.table.reorderable,
    model: () => props.table.model,
    routePrefix: () => routePrefix.value,
});
</script>

<template>
    <div class="kinetix-table-root min-w-0 max-w-full">
        <!-- KPI cards (Table::stats()), above the table in both variants. -->
        <KinetixTableStats v-if="table.stats?.length" :stats="table.stats" />

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
                        :reorderable="table.reorderable"
                        @toggle-all-on-page="toggleAllOnPage"
                        @toggle-sort="toggleSort"
                    />
                    <tbody
                        class="divide-y divide-border"
                        :class="{ 'divide-none': table.isStriped }"
                    >
                        <!-- v-memo skips re-rendering rows whose identity, selection,
                         and position are unchanged — a large win on wide/long
                         tables during selection and polling. Server reloads ship
                         fresh record objects, so data changes still re-render. -->
                        <tr
                            v-for="(record, rowIndex) in rows"
                            :key="record.id"
                            v-memo="[
                                record,
                                isRowSelected(record.id),
                                rowIndex,
                            ]"
                            class="group transition-colors"
                            :data-state="
                                isRowSelected(record.id)
                                    ? 'selected'
                                    : undefined
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
                            @dragstart="
                                table.reorderable && onDragStart(rowIndex)
                            "
                            @dragover="
                                table.reorderable &&
                                onDragOver(rowIndex, $event)
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

                            <!-- Record row actions -->
                            <td
                                v-if="table.recordActions.length > 0"
                                class="px-6 py-4 text-sm font-medium text-right whitespace-nowrap"
                                :class="
                                    table.stickyActions
                                        ? 'right-0 sticky z-10 border-l border-border bg-card group-hover:bg-muted/30'
                                        : ''
                                "
                            >
                                <div
                                    class="gap-2 flex items-center justify-end"
                                >
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
                                            :disabled="actionProcessing"
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
                                            @click.stop="
                                                handleActionClick(
                                                    action,
                                                    record,
                                                )
                                            "
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
                    <KinetixTableSummaryRow
                        v-if="table.hasSummaries"
                        :columns-to-render="columnsToRender"
                        :summaries="table.summaries"
                        :reorderable="table.reorderable"
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
                    />
                    <button
                        v-else
                        type="button"
                        :disabled="actionProcessing"
                        :class="
                            buttonVariants({
                                variant: action.color
                                    ? actionButtonVariant(action.color)
                                    : 'default',
                                size: 'sm',
                            })
                        "
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

            <!-- Simple-resource create/edit + view modals, teleported to <body> so
             the overlay is never clipped by the table's own stacking context.
             The form is fetched fresh from the server for edits by default;
             create opens instantly from the shipped blueprint. -->
            <Teleport v-if="isMounted" to="body">
                <div
                    v-if="isRecordFormOpen"
                    class="inset-0 bg-black/50 p-4 fixed z-50 flex items-center justify-center"
                    @click.self="closeRecordForm"
                >
                    <div
                        class="max-w-2xl rounded-xl shadow-xl flex max-h-[90vh] w-full flex-col overflow-hidden border border-border bg-card text-card-foreground"
                    >
                        <div
                            class="p-6 flex items-center justify-between border-b border-border"
                        >
                            <h3 class="font-semibold text-lg">
                                {{
                                    recordLabel ||
                                    (isRecordEditing
                                        ? t('kinetix.edit')
                                        : t('kinetix.create'))
                                }}
                            </h3>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="t('kinetix.close')"
                                @click="closeRecordForm"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="p-6 overflow-y-auto">
                            <div v-if="isRecordLoading" class="space-y-4">
                                <div
                                    class="h-9 animate-pulse rounded-md bg-muted"
                                ></div>
                                <div
                                    class="h-9 animate-pulse rounded-md bg-muted"
                                ></div>
                                <div
                                    class="h-9 animate-pulse w-2/3 rounded-md bg-muted"
                                ></div>
                            </div>

                            <KinetixForm
                                v-else-if="recordForm"
                                :form="recordForm"
                                @submit="submitRecordForm"
                            >
                                <template #default>
                                    <div class="gap-3 mt-6 flex justify-end">
                                        <button
                                            type="button"
                                            :class="
                                                buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })
                                            "
                                            :disabled="recordProcessing"
                                            @click="closeRecordForm"
                                        >
                                            {{ t('kinetix.cancel') }}
                                        </button>
                                        <button
                                            type="submit"
                                            :class="
                                                buttonVariants({ size: 'sm' })
                                            "
                                            :disabled="recordProcessing"
                                        >
                                            {{ t('kinetix.save') }}
                                        </button>
                                    </div>
                                </template>
                            </KinetixForm>
                        </div>
                    </div>
                </div>

                <!-- Simple-resource view modal (read-only infolist, server-resolved). -->
                <div
                    v-if="isRecordInfolistOpen"
                    class="inset-0 bg-black/50 p-4 fixed z-50 flex items-center justify-center"
                    @click.self="closeRecordInfolist"
                >
                    <div
                        class="max-w-3xl rounded-xl shadow-xl flex max-h-[90vh] w-full flex-col overflow-hidden border border-border bg-card text-card-foreground"
                    >
                        <div
                            class="p-6 flex items-center justify-between border-b border-border"
                        >
                            <h3 class="font-semibold text-lg">
                                {{ recordLabel || t('kinetix.view') }}
                            </h3>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="t('kinetix.close')"
                                @click="closeRecordInfolist"
                            >
                                &times;
                            </button>
                        </div>

                        <div class="p-6 overflow-y-auto">
                            <div v-if="isRecordLoading" class="space-y-4">
                                <div
                                    class="h-6 animate-pulse w-1/3 rounded-md bg-muted"
                                ></div>
                                <div
                                    class="h-24 animate-pulse rounded-md bg-muted"
                                ></div>
                            </div>
                            <KinetixInfolist
                                v-else-if="recordInfolist"
                                :infolist="recordInfolist"
                            />
                        </div>
                    </div>
                </div>
            </Teleport>

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
