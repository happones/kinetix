<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

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
        <input
            :type="col.inputType || 'text'"
            :value="record.values[col.name]"
            :placeholder="col.placeholder ?? ''"
            class="text-xs rounded px-2.5 py-1.5 w-32 border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            @change="
                emit(
                    'update-cell',
                    record.id,
                    col.name,
                    ($event.target as HTMLInputElement).value,
                )
            "
        />
    </div>
</template>
