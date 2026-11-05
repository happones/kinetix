<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import ColorCell from './cells/ColorCell.vue';
import IconCell from './cells/IconCell.vue';
import ImageCell from './cells/ImageCell.vue';
import ProgressCell from './cells/ProgressCell.vue';
import TextBadgeCell from './cells/TextBadgeCell.vue';
import TextCell from './cells/TextCell.vue';
import ViewCell from './cells/ViewCell.vue';
import { KINETIX_TABLE_CELL_LOADERS as LAZY } from './kinetixTableCellLoaders';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
    rowIndex: number;
}>();

const emit = defineEmits<{
    (
        e: 'update-cell',
        recordId: string | number,
        colName: string,
        value: any,
    ): void;
}>();

/**
 * Column-type → cell component map. This runs for every cell of every row, so
 * resolving by lookup (O(1)) rather than a 12-way `v-if`/`v-else-if` chain keeps
 * the hottest render path flat instead of re-evaluating every branch condition
 * per cell. Each cell shares the `{ col, record }` contract and re-emits
 * `update-cell`; copyable cells copy on their own (KinetixCopyable).
 *
 * The input cells are lazy entries (see kinetixTableCellLoaders) that
 * KinetixTable starts downloading at setup for the column types it has.
 */
const CELL_COMPONENTS: Record<string, Component | KinetixLazyComponent> = {
    text: TextCell,
    'text-badge': TextBadgeCell,
    icon: IconCell,
    image: ImageCell,
    color: ColorCell,
    progress: ProgressCell,
    view: ViewCell,
    'select-input': LAZY['select-input'],
    'toggle-input': LAZY['toggle-input'],
    'text-input': LAZY['text-input'],
    'number-input': LAZY['number-input'],
    'checkbox-input': LAZY['checkbox-input'],
};

type CellDataGuard = (
    record: KinetixTableCellRecord,
    colName: string,
) => boolean;

/**
 * Types whose cell renders nothing without backing data — the original branch
 * conditions, kept so an empty icon/image/color column stays blank instead of
 * rendering an empty wrapper.
 */
const REQUIRES_DATA: Record<string, CellDataGuard> = {
    icon: (record, colName) => !!record.icons[colName],
    image: (record, colName) => !!record.values[colName],
    color: (record, colName) => !!record.values[colName],
};

const cellKey = computed<string>(() => {
    if (props.col.type === 'text') {
        return props.col.isBadge ? 'text-badge' : 'text';
    }

    return props.col.type;
});

// Resolved from the key alone: a lazy input cell answers its loading wrapper
// until its code arrives, and swapping it for the real component on a later
// render would remount the input mid-edit.
const resolvedCell = computed<Component | null>(() => {
    const entry = CELL_COMPONENTS[cellKey.value];

    return entry ? kinetixComponentOf(entry) : null;
});

const cellComponent = computed<Component | null>(() => {
    const key = cellKey.value;

    if (key === 'view' && !props.col.view) {
        return null;
    }

    const guard = REQUIRES_DATA[key];

    if (guard && !guard(props.record, props.col.name)) {
        return null;
    }

    return resolvedCell.value;
});

const onUpdateCell = (
    recordId: string | number,
    colName: string,
    value: any,
): void => emit('update-cell', recordId, colName, value);
</script>

<template>
    <component
        :is="cellComponent"
        v-if="cellComponent"
        :col="col"
        :record="record"
        @update-cell="onUpdateCell"
    />
</template>
