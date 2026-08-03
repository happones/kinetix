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
    (e: 'copy-to-clipboard', value: string): void;
}>();
</script>

<template>
    <div class="gap-2 inline-flex items-center">
        <div
            class="w-5 h-5 shadow-sm shrink-0 cursor-pointer rounded-md border border-border"
            :style="{ backgroundColor: record.values[col.name] }"
            @click="
                col.isCopyable &&
                emit('copy-to-clipboard', record.values[col.name])
            "
            :title="col.isCopyable ? 'Click to copy color code' : undefined"
        />
        <span class="text-xs font-mono text-muted-foreground">{{
            record.values[col.name]
        }}</span>
    </div>
</template>
