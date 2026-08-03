<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixCheckbox from '../../KinetixCheckbox.vue';

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
        <KinetixCheckbox
            :checked="!!record.values[col.name]"
            @change="emit('update-cell', record.id, col.name, $event)"
        />
    </div>
</template>
