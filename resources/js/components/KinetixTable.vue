<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import {
  Search,
  Filter as FilterIcon,
  SlidersHorizontal,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  ArrowUp,
  ArrowDown,
  ArrowUpDown,
  CheckCircle2,
  XCircle,
  Trash2,
  Edit3,
  Eye,
  Plus,
} from "@lucide/vue";
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import {
  executeAction,
  useActionConfirmation,
} from "@/composables/useKinetixActions";
import type {
  KinetixTableData,
  KinetixTableRecord,
  KinetixAction,
} from "@/types";
import KinetixActionDropdown from "./KinetixActionDropdown.vue";
import KinetixCheckbox from "./KinetixCheckbox.vue";
import KinetixConfirmModal from "./KinetixConfirmModal.vue";
import KinetixRangeCalendar from "./KinetixRangeCalendar.vue";

const props = defineProps<{
  table: KinetixTableData;
}>();

const { t } = useI18n();

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

// Standard icon mappings
const standardIconMap: Record<string, any> = {
  edit: Edit3,
  delete: Trash2,
  view: Eye,
  create: Plus,
  plus: Plus,
  check: CheckCircle2,
  "check-circle": CheckCircle2,
  x: XCircle,
  "x-circle": XCircle,
};

const resolveIcon = (name?: string) => {
  if (!name) {
    return null;
  }

  return standardIconMap[name.toLowerCase()] || null;
};

// Colors mapping
const getBadgeColorClass = (color?: string) => {
  if (color === "success") {
    return "text-emerald-700 bg-emerald-50 border border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/30 dark:border-emerald-800";
  }

  if (color === "danger") {
    return "text-rose-700 bg-rose-50 border border-rose-200 dark:text-rose-300 dark:bg-rose-950/30 dark:border-rose-800";
  }

  if (color === "warning") {
    return "text-amber-700 bg-amber-50 border border-amber-200 dark:text-amber-300 dark:bg-amber-950/30 dark:border-amber-800";
  }

  if (color === "info") {
    return "text-sky-700 bg-sky-50 border border-sky-200 dark:text-sky-300 dark:bg-sky-950/30 dark:border-sky-800";
  }

  return "text-muted-foreground bg-muted border border-border";
};

const getIconColorClass = (color?: string) => {
  if (color === "success") {
    return "text-emerald-500";
  }

  if (color === "danger") {
    return "text-rose-500";
  }

  if (color === "warning") {
    return "text-amber-500";
  }

  if (color === "info") {
    return "text-sky-500";
  }

  return "text-muted-foreground";
};

// Reload data from server. Params are namespaced by the table's queryPrefix so
// multiple tables (e.g. relation managers) coexist; any unrelated/foreign query
// params already in the URL are preserved.
const triggerReload = (newParams: Record<string, any>) => {
  const prefix = props.table.queryPrefix ?? "";

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
    ["search", "sort", "direction", "perPage", "page"].some(
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

// Sorting
const isSorted = (name: string) => {
  return props.table.state.sort === name;
};

const getSortIcon = (name: string) => {
  if (!isSorted(name)) {
    return ArrowUpDown;
  }

  if (props.table.state.direction === "asc") {
    return ArrowUp;
  }

  return ArrowDown;
};

const toggleSort = (name: string) => {
  if (isSorted(name)) {
    const nextDir = props.table.state.direction === "asc" ? "desc" : "asc";
    triggerReload({ sort: name, direction: nextDir });

    return;
  }

  triggerReload({ sort: name, direction: "asc" });
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
  part: "from" | "to" | "min" | "max",
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

// Pagination links helper
const paginationPages = computed(() => {
  if (!props.table.pagination) {
    return [];
  }

  const current = props.table.pagination.currentPage;
  const last = props.table.pagination.lastPage;
  const range = [];

  for (
    let i = Math.max(1, current - 2);
    i <= Math.min(last, current + 2);
    i++
  ) {
    range.push(i);
  }

  return range;
});

// Row Click
const handleRowClick = (record: KinetixTableRecord, event: MouseEvent) => {
  // Avoid redirect if clicking a button or checkbox
  const target = event.target as HTMLElement;

  if (
    target.closest("button") ||
    target.closest("a") ||
    target.closest("input") ||
    target.closest("select")
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
  return (page.props.kinetix_config as any)?.route_prefix ?? "_kinetix";
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
    const response = await fetch(`/${routePrefix.value}/tables/cell-update`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN":
          (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
            ?.content || "",
      },
      body: JSON.stringify({
        model: props.table.model,
        recordId: recordId,
        column: columnName,
        value: newValue,
      }),
    });

    const data = await response.json();

    if (data.status === "success") {
      router.reload({ preserveScroll: true });
    }
  } catch (e) {
    console.error("Cell update failed:", e);
  }
};
</script>

<template>
  <div
    class="kinetix-table-wrapper border border-border bg-card backdrop-blur-sm rounded-xl overflow-hidden shadow-sm"
  >
    <!-- Header Controls -->
    <div
      class="p-6 border-b border-border flex flex-col md:flex-row md:items-center justify-between gap-4"
    >
      <div>
        <h3
          v-if="table.heading"
          class="text-base font-bold text-foreground leading-6"
        >
          {{ table.heading }}
        </h3>
        <p
          v-if="table.description"
          class="text-xs text-muted-foreground mt-1"
        >
          {{ table.description }}
        </p>
      </div>

      <!-- Toolbar Actions and Search/Filter Options -->
      <div class="flex items-center gap-2 flex-wrap self-end md:self-auto">
        <!-- Search bar if any column is searchable -->
        <div
          v-if="table.columns.some((c) => c.isSearchable)"
          class="relative min-w-[200px]"
        >
          <Search class="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="t('kinetix.search_records')"
            class="pl-9 pr-4 py-2 text-sm w-full rounded-lg border border-border bg-muted/40 text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            @input="onSearchInput"
          />
        </div>

        <!-- Custom Header Toolbar Actions -->
        <template v-for="(action, i) in table.toolbarActions" :key="i">
          <KinetixActionDropdown
            v-if="action.type === 'group'"
            :group="action"
          />
          <button
            v-else
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
            @click="handleActionClick(action)"
          >
            <component
              :is="resolveIcon(action.icon)"
              v-if="action.icon"
              class="h-3.5 w-3.5"
            />
            {{ action.label }}
          </button>
        </template>

        <!-- Filters Popover Trigger -->
        <div v-if="table.filters.length > 0" class="relative">
          <button
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-border text-foreground bg-background hover:bg-accent transition-colors"
            :class="{
              'border-primary bg-primary/10 text-primary':
                Object.keys(activeFilters).length > 0,
            }"
            @click="
              showFilters = !showFilters;
              showColumns = false;
            "
          >
            <FilterIcon class="h-3.5 w-3.5" />
            {{ t("kinetix.filters") }}
            <span
              v-if="Object.keys(activeFilters).length > 0"
              class="ml-1 w-4 h-4 text-[10px] font-bold rounded-full bg-primary text-primary-foreground flex items-center justify-center shrink-0"
            >
              {{ Object.keys(activeFilters).length }}
            </span>
          </button>

          <!-- Popover Panel -->
          <div
            v-if="showFilters"
            class="absolute right-0 mt-2 w-72 rounded-lg border border-border bg-popover p-4 shadow-lg z-20"
          >
            <div
              class="flex items-center justify-between border-b border-border pb-2 mb-3"
            >
              <span
                class="text-xs font-bold text-foreground uppercase tracking-wider"
                >{{ t("kinetix.table_filters") }}</span
              >
              <button
                class="text-xs text-muted-foreground hover:text-foreground"
                @click="clearFilters"
              >
                {{ t("kinetix.reset") }}
              </button>
            </div>
            <div class="space-y-4">
              <div
                v-for="filter in table.filters"
                :key="filter.name"
                class="flex flex-col gap-1.5"
              >
                <label
                  class="text-xs font-semibold text-muted-foreground"
                  >{{ filter.label }}</label
                >

                <select
                  v-if="filter.type === 'select' || filter.type === 'ternary'"
                  :value="activeFilters[filter.name] || ''"
                  class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                  @change="
                    setFilter(
                      filter.name,
                      ($event.target as HTMLSelectElement).value,
                    )
                  "
                >
                  <option value="">{{ t("kinetix.all") }}</option>
                  <option
                    v-for="(lbl, val) in filter.options"
                    :key="val"
                    :value="val"
                  >
                    {{ lbl }}
                  </option>
                </select>

                <div
                  v-if="filter.type === 'checkbox'"
                  class="flex items-center gap-2 mt-1"
                >
                  <KinetixCheckbox
                    :id="'filter-' + filter.name"
                    :checked="!!activeFilters[filter.name]"
                    @change="setFilter(filter.name, $event)"
                  />
                  <label
                    :for="'filter-' + filter.name"
                    class="text-xs text-foreground cursor-pointer select-none"
                  >
                    {{ t("kinetix.enable_filter") }}
                  </label>
                </div>

                <!-- Date range — shadcn calendar variant -->
                <KinetixRangeCalendar
                  v-if="filter.type === 'date-range' && filter.useCalendar"
                  :value="activeFilters[filter.name]"
                  :number-of-months="filter.numberOfMonths"
                  :locale="filter.locale"
                  :weekday-format="filter.weekdayFormat"
                  :fixed-weeks="filter.fixedWeeks"
                  :min-value="filter.minValue"
                  :max-value="filter.maxValue"
                  @update:value="setFilter(filter.name, $event)"
                />

                <!-- Single date -->
                <input
                  v-if="filter.type === 'date'"
                  type="date"
                  :value="activeFilters[filter.name] || ''"
                  class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                  @change="
                    setFilter(
                      filter.name,
                      ($event.target as HTMLInputElement).value,
                    )
                  "
                />

                <!-- Single datetime -->
                <input
                  v-if="filter.type === 'datetime'"
                  type="datetime-local"
                  :value="activeFilters[filter.name] || ''"
                  class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                  @change="
                    setFilter(
                      filter.name,
                      ($event.target as HTMLInputElement).value,
                    )
                  "
                />

                <!-- Date range — native inputs -->
                <div
                  v-if="filter.type === 'date-range' && !filter.useCalendar"
                  class="flex items-center gap-2"
                >
                  <input
                    type="date"
                    :value="(activeFilters[filter.name] || {}).from || ''"
                    class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                    @change="
                      setRangePart(
                        filter.name,
                        'from',
                        ($event.target as HTMLInputElement).value,
                      )
                    "
                  />
                  <span class="text-xs text-muted-foreground">–</span>
                  <input
                    type="date"
                    :value="(activeFilters[filter.name] || {}).to || ''"
                    class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                    @change="
                      setRangePart(
                        filter.name,
                        'to',
                        ($event.target as HTMLInputElement).value,
                      )
                    "
                  />
                </div>

                <!-- Number range -->
                <div
                  v-if="filter.type === 'number-range'"
                  class="flex items-center gap-2"
                >
                  <input
                    type="number"
                    :placeholder="t('kinetix.min')"
                    :value="(activeFilters[filter.name] || {}).min ?? ''"
                    class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                    @input="
                      setRangePart(
                        filter.name,
                        'min',
                        ($event.target as HTMLInputElement).value,
                      )
                    "
                  />
                  <span class="text-xs text-muted-foreground">–</span>
                  <input
                    type="number"
                    :placeholder="t('kinetix.max')"
                    :value="(activeFilters[filter.name] || {}).max ?? ''"
                    class="w-full text-xs rounded-md border border-border bg-background text-foreground p-2 focus:outline-none"
                    @input="
                      setRangePart(
                        filter.name,
                        'max',
                        ($event.target as HTMLInputElement).value,
                      )
                    "
                  />
                </div>

                <!-- Multi-select -->
                <div
                  v-if="filter.type === 'multi-select'"
                  class="flex flex-col gap-1.5 max-h-44 overflow-y-auto pr-1"
                >
                  <label
                    v-for="(lbl, val) in filter.options"
                    :key="val"
                    class="flex items-center gap-2 text-xs text-foreground cursor-pointer select-none"
                  >
                    <KinetixCheckbox
                      :checked="isMultiSelected(filter.name, String(val))"
                      @change="toggleMulti(filter.name, String(val), $event)"
                    />
                    {{ lbl }}
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Columns Toggler Dropdown -->
        <div v-if="table.columns.some((c) => c.isToggleable)" class="relative">
          <button
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-border text-foreground bg-background hover:bg-accent transition-colors"
            @click="
              showColumns = !showColumns;
              showFilters = false;
            "
          >
            <SlidersHorizontal class="h-3.5 w-3.5" />
            {{ t("kinetix.columns") }}
          </button>

          <!-- Columns Panel -->
          <div
            v-if="showColumns"
            class="absolute right-0 mt-2 w-56 rounded-lg border border-border bg-popover p-3 shadow-lg z-20"
          >
            <div
              class="text-xs font-bold text-foreground border-b border-border pb-2 mb-2 uppercase tracking-wider"
            >
              {{ t("kinetix.toggle_columns") }}
            </div>
            <div class="space-y-2 max-h-60 overflow-y-auto">
              <div
                v-for="col in table.columns.filter((c) => c.isToggleable)"
                :key="col.name"
                class="flex items-center gap-2 py-0.5 hover:bg-accent rounded px-1.5"
              >
                <KinetixCheckbox
                  :id="'col-' + col.name"
                  :checked="isColumnVisible(col.name)"
                  @change="toggleColumn(col.name)"
                />
                <label
                  :for="'col-' + col.name"
                  class="text-xs text-foreground cursor-pointer select-none flex-1 py-1"
                >
                  {{ col.label }}
                </label>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bulk action bar (visible when rows are selected) -->
    <div
      v-if="table.bulkActions.length > 0 && selectionCount > 0"
      class="flex flex-wrap items-center gap-3 border-b border-border bg-muted/40 px-6 py-3"
    >
      <span class="text-xs font-semibold text-muted-foreground">
        {{ t("kinetix.selected", { count: selectionCount }) }}
      </span>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="(action, i) in table.bulkActions"
          :key="i"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
          :class="
            action.color === 'danger'
              ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
              : 'bg-primary text-primary-foreground hover:bg-primary/90'
          "
          @click="requestBulkAction(action)"
        >
          <component
            :is="resolveIcon(action.icon)"
            v-if="action.icon"
            class="h-3.5 w-3.5"
          />
          {{ action.label }}
        </button>
      </div>
      <button
        type="button"
        class="ml-auto text-xs text-muted-foreground hover:text-foreground"
        @click="clearSelection"
      >
        {{ t("kinetix.clear_selection") }}
      </button>
    </div>

    <!-- HTML Table -->
    <div class="overflow-x-auto">
      <table
        class="min-w-full divide-y divide-border"
      >
        <thead class="bg-muted/40">
          <tr>
            <th
              v-if="table.bulkActions.length > 0"
              scope="col"
              class="w-10 px-4 py-3"
            >
              <KinetixCheckbox
                :checked="allOnPageSelected"
                @change="toggleAllOnPage($event)"
              />
            </th>
            <th
              v-for="col in columnsToRender"
              :key="col.name"
              scope="col"
              class="px-6 py-3 text-xs font-semibold text-muted-foreground uppercase tracking-wider"
              :class="[
                col.alignment === 'center' ? 'text-center' : '',
                col.alignment === 'right' ? 'text-right' : 'text-left',
              ]"
            >
              <button
                v-if="col.isSortable"
                class="inline-flex items-center gap-1 hover:text-foreground transition-colors"
                @click="toggleSort(col.name)"
              >
                {{ col.label }}
                <component :is="getSortIcon(col.name)" class="h-3.5 w-3.5" />
              </button>
              <span v-else>{{ col.label }}</span>
            </th>
            <th
              v-if="table.recordActions.length > 0"
              scope="col"
              class="relative px-6 py-3"
            >
              <span class="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody
          class="divide-y divide-border"
          :class="{ 'divide-none': table.isStriped }"
        >
          <tr
            v-for="(record, rowIndex) in table.records"
            :key="record.id"
            class="transition-colors group"
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
                col.alignment === 'right' ? 'text-right' : 'text-left',
                col.type === 'text' && !col.isBadge
                  ? 'text-foreground'
                  : '',
              ]"
            >
              <!-- Text Badge Mode -->
              <span
                v-if="col.type === 'text' && col.isBadge"
                class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full"
                :class="getBadgeColorClass(record.badgeColors[col.name])"
              >
                {{ record.values[col.name] }}
              </span>

              <!-- Text Normal Mode -->
              <div
                v-if="col.type === 'text' && !col.isBadge"
                class="flex flex-col"
              >
                <span
                  v-if="
                    record.descriptions[col.name] &&
                    record.descriptions[col.name].position === 'above'
                  "
                  class="text-[11px] text-muted-foreground mb-0.5"
                >
                  {{ record.descriptions[col.name].text }}
                </span>
                <span>{{ record.values[col.name] }}</span>
                <span
                  v-if="
                    record.descriptions[col.name] &&
                    record.descriptions[col.name].position === 'below'
                  "
                  class="text-[11px] text-muted-foreground mt-0.5"
                >
                  {{ record.descriptions[col.name].text }}
                </span>
              </div>

              <!-- Icon Mode -->
              <div
                v-if="col.type === 'icon' && record.icons[col.name]"
                class="inline-flex items-center justify-center"
              >
                <component
                  :is="resolveIcon(record.icons[col.name] || '')"
                  class="h-5 w-5"
                  :class="getIconColorClass(record.iconColors[col.name])"
                />
              </div>

              <!-- Image Mode -->
              <div
                v-if="col.type === 'image' && record.values[col.name]"
                class="inline-flex items-center"
              >
                <img
                  :src="record.values[col.name]"
                  class="object-cover"
                  :class="
                    col.isCircular
                      ? 'rounded-full'
                      : 'rounded-lg border border-border'
                  "
                  :style="{
                    width: (col.size || 40) + 'px',
                    height: (col.size || 40) + 'px',
                  }"
                />
              </div>

              <!-- Color Mode -->
              <div
                v-if="col.type === 'color' && record.values[col.name]"
                class="inline-flex items-center gap-2"
              >
                <div
                  class="w-5 h-5 rounded-md border border-border shadow-sm shrink-0 cursor-pointer"
                  :style="{ backgroundColor: record.values[col.name] }"
                  @click="
                    col.isCopyable && copyToClipboard(record.values[col.name])
                  "
                  :title="
                    col.isCopyable ? 'Click to copy color code' : undefined
                  "
                />
                <span class="text-xs text-muted-foreground font-mono">{{
                  record.values[col.name]
                }}</span>
              </div>

              <!-- Editable: Select Mode -->
              <div
                v-if="col.type === 'select-input'"
                class="inline-flex items-center"
              >
                <select
                  :value="record.values[col.name]"
                  class="text-xs rounded border border-border bg-background text-foreground p-1.5 focus:outline-none focus:ring-1 focus:ring-ring"
                  @change="
                    updateCell(
                      record.id,
                      col.name,
                      ($event.target as HTMLSelectElement).value,
                    )
                  "
                >
                  <option
                    v-for="(lbl, val) in col.options"
                    :key="val"
                    :value="val"
                  >
                    {{ lbl }}
                  </option>
                </select>
              </div>

              <!-- Editable: Toggle Mode -->
              <div
                v-if="col.type === 'toggle-input'"
                class="inline-flex items-center"
              >
                <button
                  type="button"
                  class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-1 focus:ring-ring"
                  :class="
                    record.values[col.name]
                      ? 'bg-primary'
                      : 'bg-muted'
                  "
                  @click="
                    updateCell(record.id, col.name, !record.values[col.name])
                  "
                >
                  <span
                    class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="
                      record.values[col.name]
                        ? 'translate-x-4'
                        : 'translate-x-0'
                    "
                  />
                </button>
              </div>

              <!-- Editable: Text Input Mode -->
              <div
                v-if="col.type === 'text-input'"
                class="inline-flex items-center"
              >
                <input
                  :type="col.inputType || 'text'"
                  :value="record.values[col.name]"
                  :placeholder="col.placeholder"
                  class="text-xs rounded border border-border bg-background px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-ring w-32 text-foreground"
                  @change="
                    updateCell(
                      record.id,
                      col.name,
                      ($event.target as HTMLInputElement).value,
                    )
                  "
                />
              </div>

              <!-- Editable: Checkbox Mode -->
              <div
                v-if="col.type === 'checkbox-input'"
                class="inline-flex items-center"
              >
                <KinetixCheckbox
                  :checked="!!record.values[col.name]"
                  @change="updateCell(record.id, col.name, $event)"
                />
              </div>
            </td>

            <!-- Record Row Actions -->
            <td
              v-if="table.recordActions.length > 0"
              class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium"
            >
              <div class="flex items-center justify-end gap-2">
                <template v-for="(action, idx) in record.actions" :key="idx">
                  <KinetixActionDropdown
                    v-if="action.type === 'group'"
                    :group="action"
                  />
                  <button
                    v-else
                    class="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors"
                    @click.stop="handleActionClick(action)"
                  >
                    <component
                      :is="resolveIcon(action.icon)"
                      v-if="action.icon"
                      class="h-3.5 w-3.5"
                    />
                    <span>{{ action.label }}</span>
                  </button>
                </template>
              </div>
            </td>
          </tr>

          <!-- Empty State -->
          <tr v-if="table.records.length === 0">
            <td
              :colspan="
                columnsToRender.length +
                (table.recordActions.length > 0 ? 1 : 0) +
                (table.bulkActions.length > 0 ? 1 : 0)
              "
              class="px-6 py-12 text-center text-sm text-muted-foreground"
            >
              {{ t("kinetix.no_records_found") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Footer Pagination -->
    <div
      v-if="table.isPaginated && table.pagination"
      class="p-6 border-t border-border flex flex-col sm:flex-row items-center justify-between gap-4"
    >
      <div class="text-xs text-muted-foreground">
        {{ t("kinetix.total") }}
        <span class="font-bold text-foreground">{{
          table.pagination.total
        }}</span>
        {{ t("kinetix.records") }}
      </div>

      <!-- Page Buttons -->
      <div class="flex items-center gap-1">
        <button
          class="p-2 rounded-lg border border-border text-muted-foreground hover:bg-accent disabled:opacity-50 transition-colors"
          :disabled="table.pagination.currentPage === 1"
          @click="triggerReload({ page: 1 })"
        >
          <ChevronsLeft class="h-4 w-4" />
        </button>
        <button
          class="p-2 rounded-lg border border-border text-muted-foreground hover:bg-accent disabled:opacity-50 transition-colors"
          :disabled="table.pagination.currentPage === 1"
          @click="triggerReload({ page: table.pagination.currentPage - 1 })"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>

        <button
          v-for="p in paginationPages"
          :key="p"
          class="w-9 h-9 rounded-lg text-xs font-semibold border transition-all"
          :class="[
            p === table.pagination.currentPage
              ? 'bg-primary text-primary-foreground border-foreground dark:border-white shadow-sm'
              : 'border-border text-muted-foreground hover:bg-accent',
          ]"
          @click="triggerReload({ page: p })"
        >
          {{ p }}
        </button>

        <button
          class="p-2 rounded-lg border border-border text-muted-foreground hover:bg-accent disabled:opacity-50 transition-colors"
          :disabled="table.pagination.currentPage === table.pagination.lastPage"
          @click="triggerReload({ page: table.pagination.currentPage + 1 })"
        >
          <ChevronRight class="h-4 w-4" />
        </button>
        <button
          class="p-2 rounded-lg border border-border text-muted-foreground hover:bg-accent disabled:opacity-50 transition-colors"
          :disabled="table.pagination.currentPage === table.pagination.lastPage"
          @click="triggerReload({ page: table.pagination.lastPage })"
        >
          <ChevronsRight class="h-4 w-4" />
        </button>
      </div>

      <!-- Page Size selector -->
      <div
        class="flex items-center gap-2 text-xs text-muted-foreground"
      >
        <span>{{ t("kinetix.per_page") }}</span>
        <select
          :value="table.state.perPage"
          class="rounded-md border border-border bg-background text-foreground px-2.5 py-1.5 focus:outline-none"
          @change="
            triggerReload({
              perPage: ($event.target as HTMLSelectElement).value,
              page: 1,
            })
          "
        >
          <option
            v-for="opt in table.paginationPageOptions"
            :key="opt"
            :value="opt"
          >
            {{ opt }}
          </option>
        </select>
      </div>
    </div>

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
</style>
