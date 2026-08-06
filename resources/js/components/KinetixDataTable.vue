<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search, SlidersHorizontal } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { useKinetixClientTable } from '@/composables/useKinetixClientTable';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixAction,
    KinetixTableData,
    KinetixTableRecord,
} from '@/types/kinetix';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixTableCell from './Table/KinetixTableCell.vue';
import KinetixTableHead from './Table/KinetixTableHead.vue';
import KinetixTablePagination from './Table/KinetixTablePagination.vue';

/**
 * Client-side ("TanStack") variant of KinetixTable. Rendered by KinetixTable
 * (async-loaded) only when a table opts into `Table::clientSide()`, so the
 * TanStack dependency stays code-split off the server-driven path.
 *
 * Search, sort and pagination run entirely in the browser over the full row set
 * (via `useKinetixClientTable`). It reuses the shared Head/Cell/Pagination
 * components, so column rendering is identical to the server-driven table. It
 * deliberately omits server-only features (filters, saved views, polling,
 * reorder, bulk actions) — for those, use the default server-driven mode.
 */
const props = defineProps<{
    table: KinetixTableData;
}>();

const { t } = useI18n();

const showColumns = ref(false);

// Column visibility (local; client-side has no server round-trip).
const visibleColumnNames = ref<Set<string>>(
    new Set(
        props.table.columns
            .filter((c) => !c.isToggledHiddenByDefault)
            .map((c) => c.name),
    ),
);
const isColumnVisible = (name: string) => visibleColumnNames.value.has(name);
const columnsToRender = computed(() =>
    props.table.columns.filter((c) => isColumnVisible(c.name)),
);

const toggleColumn = (name: string) => {
    const next = new Set(visibleColumnNames.value);

    if (next.has(name)) {
        if (next.size > 1) {
            next.delete(name);
        }
    } else {
        next.add(name);
    }

    visibleColumnNames.value = next;
};

const client = useKinetixClientTable({
    records: () => props.table.records,
    columns: () => columnsToRender.value,
    pageSize: props.table.state?.perPage ?? 10,
});

// Announce result counts on search/sort/page changes (client-side filtering
// moves rows with no focus change, so assistive tech hears the new count).
const { announce } = useKinetixAnnounce();

watch(
    [
        client.search,
        client.sortName,
        client.sortDirection,
        () => client.pagination.value.currentPage,
    ],
    () => {
        const { total, from, to } = client.pagination.value;

        announce(
            total === 0
                ? t('kinetix.no_records')
                : t('kinetix.showing_records', { from, to, total }),
        );
    },
);

const recordActionClass = (action: {
    color?: string | null;
    isIconButton?: boolean;
}) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'ghost',
        size: action.isIconButton ? 'icon-sm' : 'sm',
    });

const {
    pendingAction,
    isConfirmOpen,
    processing,
    requestAction,
    confirm,
    cancel,
} = useActionConfirmation();
const handleActionClick = (
    action: KinetixAction,
    record?: KinetixTableRecord,
) => requestAction(action, record ? { record } : {});

// Solid primary button for toolbar actions (mirrors the server-driven table).
const primaryActionClass = (action: { color?: string | null }) =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'default',
        size: 'sm',
    });

const handleRowClick = (record: KinetixTableRecord, event: MouseEvent) => {
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
</script>

<template>
    <div
        class="rounded-xl shadow-sm border border-border bg-card text-card-foreground"
    >
        <!-- Header -->
        <div
            v-if="table.heading || table.description || table.columns.length"
            class="sm:flex-row gap-4 px-6 py-4 flex flex-col items-start justify-between border-b border-border"
        >
            <div v-if="table.heading || table.description">
                <h3 v-if="table.heading" class="font-semibold text-foreground">
                    {{ table.heading }}
                </h3>
                <p
                    v-if="table.description"
                    class="text-sm mt-1 text-muted-foreground"
                >
                    {{ table.description }}
                </p>
            </div>

            <div
                class="gap-2 sm:self-auto flex flex-wrap items-center self-end"
            >
                <!-- Client-side search -->
                <div
                    v-if="table.columns.some((c) => c.isSearchable)"
                    class="relative min-w-[200px]"
                >
                    <Search
                        class="left-3 top-2.5 h-4 w-4 absolute text-muted-foreground"
                    />
                    <input
                        v-model="client.search.value"
                        type="text"
                        :placeholder="t('kinetix.search_records')"
                        class="pl-9 pr-4 py-2 text-sm rounded-lg w-full border border-border bg-muted/40 text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                <!-- Toolbar (header) actions -->
                <template
                    v-for="(action, i) in table.toolbarActions"
                    :key="`toolbar-${i}`"
                >
                    <KinetixActionDropdown
                        v-if="action.type === 'group'"
                        :group="action"
                        @action-click="handleActionClick"
                    />
                    <button
                        v-else
                        type="button"
                        :disabled="processing"
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

                <!-- Column visibility -->
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
                                class="w-56 rounded-lg p-3 shadow-lg z-[var(--kinetix-z-popover,120)] border border-border bg-popover outline-none"
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

        <!-- Table -->
        <div class="kinetix-scroll-x overflow-x-auto">
            <table class="min-w-full divide-y divide-border">
                <KinetixTableHead
                    :columns-to-render="columnsToRender"
                    :sort="client.sortName.value"
                    :direction="client.sortDirection.value"
                    :has-bulk-actions="false"
                    :has-record-actions="table.recordActions.length > 0"
                    :all-on-page-selected="false"
                    :sticky-actions="table.stickyActions"
                    @toggle-sort="client.toggleSort"
                />
                <tbody
                    class="divide-y divide-border"
                    :class="{ 'divide-none': table.isStriped }"
                >
                    <tr
                        v-for="(record, rowIndex) in client.pageRecords.value"
                        :key="record.id"
                        class="group transition-colors"
                        :class="[
                            record.recordUrl ? 'cursor-pointer' : '',
                            table.isStriped && rowIndex % 2 === 1
                                ? 'bg-muted/30'
                                : 'bg-transparent',
                            record.recordUrl
                                ? 'hover:bg-muted/40'
                                : 'hover:bg-muted/30',
                        ]"
                        @click="handleRowClick(record, $event)"
                    >
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
                            <KinetixTableCell
                                :col="col"
                                :record="record"
                                :row-index="rowIndex"
                            />
                        </td>

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
                                        :record="record"
                                        @action-click="handleActionClick"
                                    />
                                    <button
                                        v-else
                                        :disabled="processing"
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
                                            handleActionClick(action, record)
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

                    <!-- Empty state -->
                    <tr v-if="client.pageRecords.value.length === 0">
                        <td
                            :colspan="
                                columnsToRender.length +
                                (table.recordActions.length > 0 ? 1 : 0)
                            "
                            class="px-6 py-12 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.no_records_found') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Client-side pagination -->
        <KinetixTablePagination
            v-if="table.isPaginated && client.pagination.value.total > 0"
            :pagination="client.pagination.value"
            :pagination-page-options="table.paginationPageOptions"
            @change-page="client.setPage"
            @change-per-page="client.setPerPage"
        />

        <KinetixConfirmModal
            v-model:open="isConfirmOpen"
            :heading="pendingAction?.modalHeading"
            :description="pendingAction?.modalDescription"
            :icon="pendingAction?.modalIcon"
            :color="pendingAction?.color"
            :submit-label="pendingAction?.modalSubmitActionLabel"
            :cancel-label="pendingAction?.modalCancelActionLabel"
            :processing="processing"
            @confirm="confirm"
            @cancel="cancel"
        />
    </div>
</template>
