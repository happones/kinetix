<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import CheckboxInputCell from './cells/CheckboxInputCell.vue';
import ColorCell from './cells/ColorCell.vue';
import IconCell from './cells/IconCell.vue';
import ImageCell from './cells/ImageCell.vue';
import NumberInputCell from './cells/NumberInputCell.vue';
import ProgressCell from './cells/ProgressCell.vue';
import SelectInputCell from './cells/SelectInputCell.vue';
import TextBadgeCell from './cells/TextBadgeCell.vue';
import TextCell from './cells/TextCell.vue';
import TextInputCell from './cells/TextInputCell.vue';
import ToggleInputCell from './cells/ToggleInputCell.vue';
import ViewCell from './cells/ViewCell.vue';

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
    (e: 'copy-to-clipboard', value: string): void;
}>();

/**
 * Column-type → cell component map. This runs for every cell of every row, so
 * resolving by lookup (O(1)) rather than a 12-way `v-if`/`v-else-if` chain keeps
 * the hottest render path flat instead of re-evaluating every branch condition
 * per cell. Each cell shares the `{ col, record }` contract and re-emits
 * `update-cell` / `copy-to-clipboard`.
 */
const CELL_COMPONENTS: Record<string, Component> = {
    text: TextCell,
    'text-badge': TextBadgeCell,
    icon: IconCell,
    image: ImageCell,
    color: ColorCell,
    progress: ProgressCell,
    view: ViewCell,
    'select-input': SelectInputCell,
    'toggle-input': ToggleInputCell,
    'text-input': TextInputCell,
    'number-input': NumberInputCell,
    'checkbox-input': CheckboxInputCell,
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

const cellComponent = computed<Component | null>(() => {
    const key = cellKey.value;

    if (key === 'view' && !props.col.view) {
        return null;
    }

    const guard = REQUIRES_DATA[key];

    if (guard && !guard(props.record, props.col.name)) {
        return null;
    }

    return CELL_COMPONENTS[key] ?? null;
});

const onUpdateCell = (
    recordId: string | number,
    colName: string,
    value: any,
): void => emit('update-cell', recordId, colName, value);

const onCopyToClipboard = (value: string): void =>
    emit('copy-to-clipboard', value);
</script>

<template>
    <component
        :is="cellComponent"
        v-if="cellComponent"
        :col="col"
        :record="record"
        @update-cell="onUpdateCell"
        @copy-to-clipboard="onCopyToClipboard"
    />
</template>
