<script setup lang="ts">
import {
    statusFillClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';
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
    <div class="gap-2.5 inline-flex items-center">
        <span
            v-if="record.values[col.name] !== null"
            class="text-sm font-medium"
            :class="
                statusTextClass(record.progressColors[col.name] || 'primary')
            "
        >
            {{ record.values[col.name] }}
        </span>
        <div
            v-if="record.progress[col.name] !== null"
            class="w-12 h-1 overflow-hidden rounded-full bg-muted"
        >
            <div
                class="h-full rounded-full transition-all duration-300"
                :class="
                    statusFillClass(
                        record.progressColors[col.name] || 'primary',
                    )
                "
                :style="{ width: `${record.progress[col.name]}%` }"
            />
        </div>
    </div>
</template>
