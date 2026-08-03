<script setup lang="ts">
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
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
    <span
        class="px-2 py-0.5 text-xs font-semibold inline-flex items-center rounded-full"
        :class="statusBadgeClass(record.badgeColors[col.name])"
    >
        {{ record.values[col.name] }}
    </span>
</template>
