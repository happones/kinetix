<script setup lang="ts">
import { ArrowUp, ArrowDown, ArrowUpDown } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import KinetixCheckbox from '../KinetixCheckbox.vue';

interface Column {
    name: string;
    label: string;
    isSortable: boolean;
    alignment?: 'left' | 'center' | 'right' | null;
}

const props = defineProps<{
    columnsToRender: Column[];
    sort: string | null;
    direction: 'asc' | 'desc' | null;
    hasBulkActions: boolean;
    hasRecordActions: boolean;
    allOnPageSelected: boolean;
    stickyActions?: boolean;
    reorderable?: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle-all-on-page', checked: boolean): void;
    (e: 'toggle-sort', column: string): void;
}>();

const { t } = useI18n();

const getSortIcon = (name: string) => {
    if (props.sort !== name) {
        return ArrowUpDown;
    }

    return props.direction === 'asc' ? ArrowUp : ArrowDown;
};

/**
 * The ARIA sort state announced on a sortable column's <th>: 'none' until the
 * column is the active sort, then the active direction.
 */
const getAriaSort = (
    col: Column,
): 'ascending' | 'descending' | 'none' | undefined => {
    if (!col.isSortable) {
        return undefined;
    }

    if (props.sort !== col.name) {
        return 'none';
    }

    return props.direction === 'asc' ? 'ascending' : 'descending';
};
</script>

<template>
    <thead class="bg-muted/40">
        <tr>
            <th v-if="reorderable" scope="col" class="w-8 px-2 py-3">
                <span class="sr-only">{{ t('kinetix.reorder') }}</span>
            </th>
            <th v-if="hasBulkActions" scope="col" class="w-10 px-4 py-3">
                <KinetixCheckbox
                    :checked="allOnPageSelected"
                    :aria-label="t('kinetix.select_all')"
                    @change="emit('toggle-all-on-page', $event)"
                />
            </th>
            <th
                v-for="col in columnsToRender"
                :key="col.name"
                scope="col"
                :aria-sort="getAriaSort(col)"
                class="px-6 py-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                :class="[
                    col.alignment === 'center' ? 'text-center' : '',
                    col.alignment === 'right' ? 'text-right' : 'text-left',
                ]"
            >
                <button
                    v-if="col.isSortable"
                    type="button"
                    class="gap-1 inline-flex items-center rounded-md transition-colors outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @click="emit('toggle-sort', col.name)"
                >
                    {{ col.label }}
                    <component
                        :is="getSortIcon(col.name)"
                        class="h-3.5 w-3.5"
                        aria-hidden="true"
                    />
                </button>
                <span v-else>{{ col.label }}</span>
            </th>
            <th
                v-if="hasRecordActions"
                scope="col"
                class="px-6 py-3 relative"
                :class="
                    stickyActions
                        ? 'right-0 sticky z-20 border-l border-border bg-muted'
                        : ''
                "
            >
                <span class="sr-only">{{ t('kinetix.actions') }}</span>
            </th>
        </tr>
    </thead>
</template>
