<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixNumberField from '../../KinetixNumberField.vue';

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
        <KinetixNumberField
            compact
            :value="record.values[col.name]"
            :config="(col.numberConfig as any) ?? null"
            :placeholder="col.placeholder ?? ''"
            @update:value="emit('update-cell', record.id, col.name, $event)"
        />
    </div>
</template>
