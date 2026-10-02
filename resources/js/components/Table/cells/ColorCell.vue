<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixCopyable from '../../primitives/KinetixCopyable.vue';

defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();
</script>

<template>
    <!-- Copyable: swatch + code together are the copy trigger. -->
    <component
        :is="col.isCopyable ? KinetixCopyable : 'span'"
        v-bind="
            col.isCopyable ? { value: String(record.values[col.name]) } : {}
        "
    >
        <span class="gap-2 inline-flex items-center">
            <span
                class="w-5 h-5 shadow-sm shrink-0 rounded-md border border-border"
                :style="{ backgroundColor: record.values[col.name] }"
                aria-hidden="true"
            />
            <span class="text-xs font-mono text-muted-foreground">{{
                record.values[col.name]
            }}</span>
        </span>
    </component>
</template>
