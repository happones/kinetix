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
        <button
            type="button"
            class="w-8 shadow-xs relative inline-flex h-[1.15rem] shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-all outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :class="
                record.values[col.name]
                    ? 'bg-primary'
                    : 'bg-input dark:bg-input/80'
            "
            @click="
                emit(
                    'update-cell',
                    record.id,
                    col.name,
                    !record.values[col.name],
                )
            "
        >
            <span
                class="size-4 pointer-events-none block rounded-full bg-background ring-0 transition-transform"
                :class="
                    record.values[col.name]
                        ? 'translate-x-[calc(100%-2px)] dark:bg-primary-foreground'
                        : 'translate-x-0 dark:bg-foreground'
                "
            />
        </button>
    </div>
</template>
