<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { actionButtonVariant } from '@/composables/useKinetixShadcnVariants';
import type { KinetixAction, KinetixTableData } from '@/types/kinetix';
import KinetixActionDropdown from '../KinetixActionDropdown.vue';
import KinetixButton from '../KinetixButton.vue';
import KinetixSavedViews from '../KinetixSavedViews.vue';
import KinetixTableColumnToggle from './KinetixTableColumnToggle.vue';
import KinetixTableFilters from './KinetixTableFilters.vue';

const props = defineProps<{
    table: KinetixTableData;
    searchQuery: string;
    activeFilters: Record<string, unknown>;
    currentViewState: Record<string, unknown>;
    isColumnVisible: (name: string) => boolean;
    /** True while any table action is in flight — disables every action button. */
    processing?: boolean;
    /** Name of the in-flight action — its button shows the spinner. */
    processingAction?: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:searchQuery', value: string): void;
    (e: 'search-input'): void;
    (e: 'apply-view', state: Record<string, unknown>): void;
    (e: 'action-click', action: KinetixAction): void;
    (e: 'set-filter', name: string, value: unknown): void;
    (e: 'clear-filters'): void;
    (e: 'toggle-column', name: string): void;
}>();

const { t } = useI18n();

const hasSearch = computed<boolean>(() =>
    props.table.columns.some((c) => c.isSearchable),
);

const hasToggleableColumns = computed<boolean>(() =>
    props.table.columns.some((c) => c.isToggleable),
);

const forwardSetFilter = (name: string, value: unknown): void => {
    emit('set-filter', name, value);
};

/**
 * Toolbar arrangement. 'auto' adapts to the TABLE's own width via container
 * queries (stacked below ~640px, one row above); 'inline'/'stacked' pin one
 * arrangement at every width (Table::toolbarLayout()).
 */
const layoutClass = computed<string>(() => {
    const layout = props.table.toolbarLayout ?? 'auto';

    if (layout === 'inline') {
        return 'is-inline';
    }

    if (layout === 'stacked') {
        return 'is-stacked';
    }

    return 'is-auto';
});
</script>

<template>
    <div class="kinetix-toolbar-host">
        <div
            data-slot="card-header"
            class="kinetix-toolbar p-6 gap-4 border-b border-border"
            :class="layoutClass"
        >
            <div
                v-if="table.heading || table.description"
                class="kinetix-toolbar-heading min-w-0"
            >
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

            <div class="kinetix-toolbar-controls">
                <!-- Search bar if any column is searchable -->
                <div v-if="hasSearch" class="kinetix-toolbar-search relative">
                    <Search
                        class="left-3 top-2.5 h-4 w-4 absolute text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        :value="searchQuery"
                        type="search"
                        :placeholder="t('kinetix.search_records')"
                        class="pl-9 pr-4 py-2 text-sm rounded-lg w-full border border-border bg-muted/40 text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @input="
                            emit(
                                'update:searchQuery',
                                ($event.target as HTMLInputElement).value,
                            );
                            emit('search-input');
                        "
                    />
                </div>

                <div class="kinetix-toolbar-buttons">
                    <!-- Saved views (presets of search/filters/sort/columns) -->
                    <KinetixSavedViews
                        v-if="table.savedViewsKey"
                        :view-key="table.savedViewsKey"
                        :current-state="currentViewState"
                        @apply="emit('apply-view', $event)"
                    />

                    <!-- Custom header toolbar actions -->
                    <template
                        v-for="(action, i) in table.toolbarActions"
                        :key="i"
                    >
                        <KinetixActionDropdown
                            v-if="action.type === 'group'"
                            :group="action"
                            @action-click="
                                (a: KinetixAction) => emit('action-click', a)
                            "
                        />
                        <KinetixButton
                            v-else
                            :variant="
                                action.color
                                    ? actionButtonVariant(action.color)
                                    : 'default'
                            "
                            size="sm"
                            :disabled="processing"
                            :loading="
                                processing && processingAction === action.name
                            "
                            @click="emit('action-click', action)"
                        >
                            <template #icon>
                                <component
                                    :is="resolveIcon(action.icon)"
                                    v-if="action.icon"
                                />
                            </template>
                            {{ action.label }}
                        </KinetixButton>
                    </template>

                    <!-- Filters popover -->
                    <KinetixTableFilters
                        v-if="table.filters.length > 0"
                        :filters="table.filters"
                        :active-filters="activeFilters"
                        @set-filter="forwardSetFilter"
                        @clear="emit('clear-filters')"
                    />

                    <!-- Columns toggler -->
                    <KinetixTableColumnToggle
                        v-if="hasToggleableColumns"
                        :columns="table.columns"
                        :is-column-visible="isColumnVisible"
                        @toggle="emit('toggle-column', $event)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * Toolbar arrangement, measured against the TABLE's own width (container
 * query) rather than the viewport — a table in a narrow pane stacks even on a
 * wide screen. Base = stacked: heading, then a full-width search, then the
 * control buttons wrapping in a row. Inline = everything on one row, heading
 * left, controls right, search capped so buttons keep room.
 */
.kinetix-toolbar-host {
    container-type: inline-size;
}
.kinetix-toolbar {
    display: flex;
    flex-direction: column;
}
.kinetix-toolbar-controls {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.5rem;
}
.kinetix-toolbar-search {
    width: 100%;
}
.kinetix-toolbar-buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}
.kinetix-toolbar-buttons:empty {
    display: none;
}

/* The one-row arrangement, shared by is-inline (always) and is-auto (wide). */
.kinetix-toolbar.is-inline {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
}
.kinetix-toolbar.is-inline .kinetix-toolbar-controls {
    margin-left: auto;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
}
.kinetix-toolbar.is-inline .kinetix-toolbar-search {
    width: auto;
    min-width: 200px;
    max-width: 20rem;
    flex: 1 1 200px;
}

@container (min-width: 640px) {
    .kinetix-toolbar.is-auto {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
    .kinetix-toolbar.is-auto .kinetix-toolbar-controls {
        margin-left: auto;
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 0.5rem;
    }
    .kinetix-toolbar.is-auto .kinetix-toolbar-search {
        width: auto;
        min-width: 200px;
        max-width: 20rem;
        flex: 1 1 200px;
    }
}
</style>
