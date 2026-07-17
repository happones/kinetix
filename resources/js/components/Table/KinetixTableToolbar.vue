<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useShadcnVariants';
import type { KinetixAction, KinetixTableData } from '@/types';
import KinetixActionDropdown from '../KinetixActionDropdown.vue';
import KinetixSavedViews from '../KinetixSavedViews.vue';
import KinetixTableColumnToggle from './KinetixTableColumnToggle.vue';
import KinetixTableFilters from './KinetixTableFilters.vue';

const props = defineProps<{
    table: KinetixTableData;
    searchQuery: string;
    activeFilters: Record<string, unknown>;
    currentViewState: Record<string, unknown>;
    isColumnVisible: (name: string) => boolean;
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

const primaryActionClass = (action: { color?: string | null }): string =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'default',
        size: 'sm',
    });

const hasSearch = computed<boolean>(() =>
    props.table.columns.some((c) => c.isSearchable),
);

const hasToggleableColumns = computed<boolean>(() =>
    props.table.columns.some((c) => c.isToggleable),
);

const forwardSetFilter = (name: string, value: unknown): void => {
    emit('set-filter', name, value);
};
</script>

<template>
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

        <div class="gap-2 md:self-auto flex flex-wrap items-center self-end">
            <!-- Search bar if any column is searchable -->
            <div v-if="hasSearch" class="relative min-w-[200px]">
                <Search
                    class="left-3 top-2.5 h-4 w-4 absolute text-muted-foreground"
                />
                <input
                    :value="searchQuery"
                    type="text"
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

            <!-- Saved views (presets of search/filters/sort/columns) -->
            <KinetixSavedViews
                v-if="table.savedViewsKey"
                :view-key="table.savedViewsKey"
                :current-state="currentViewState"
                @apply="emit('apply-view', $event)"
            />

            <!-- Custom header toolbar actions -->
            <template v-for="(action, i) in table.toolbarActions" :key="i">
                <KinetixActionDropdown
                    v-if="action.type === 'group'"
                    :group="action"
                />
                <button
                    v-else
                    :class="primaryActionClass(action)"
                    @click="emit('action-click', action)"
                >
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon"
                    />
                    {{ action.label }}
                </button>
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
</template>
