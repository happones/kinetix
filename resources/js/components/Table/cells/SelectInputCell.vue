<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixSelect from '../../KinetixSelect.vue';

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
    <div class="inline-flex min-w-[120px] items-center">
        <KinetixSelect
            :value="record.values[col.name]"
            :options="col.options"
            @update:value="emit('update-cell', record.id, col.name, $event)"
        />
    </div>
</template>
