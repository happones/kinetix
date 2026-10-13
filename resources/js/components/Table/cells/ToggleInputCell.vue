<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixSwitch from '../../primitives/KinetixSwitch.vue';

defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const emit = defineEmits<{
    (
        e: 'update-cell',
        recordId: string | number,
        colName: string,
        value: any,
    ): void;
}>();
</script>

<template>
    <div class="inline-flex items-center">
        <!-- The shared switch, so the cell is announced as a switch with its
             state and its column's name rather than as an unnamed button. -->
        <KinetixSwitch
            :model-value="!!record.values[col.name]"
            :aria-label="col.label"
            @update:model-value="
                (value) => emit('update-cell', record.id, col.name, value)
            "
        />
    </div>
</template>
