<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();
</script>

<template>
    <div class="inline-flex items-center">
        <component
            :is="col.view"
            :record="record"
            :value="record.values[col.name]"
            v-bind="record.viewProps[col.name] || {}"
        />
    </div>
</template>
