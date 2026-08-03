<script setup lang="ts">
import { CheckCircle2, Edit3, Eye, Plus, Trash2, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { statusTextClass } from '@/composables/useKinetixStatusColor';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const STANDARD_ICONS: Record<string, Component> = {
    edit: Edit3,
    delete: Trash2,
    view: Eye,
    create: Plus,
    plus: Plus,
    check: CheckCircle2,
    'check-circle': CheckCircle2,
    x: XCircle,
    'x-circle': XCircle,
};

const icon = computed<Component | null>(() => {
    const name = props.record.icons[props.col.name];

    return name ? (STANDARD_ICONS[name.toLowerCase()] ?? null) : null;
});
</script>

<template>
    <div class="inline-flex items-center justify-center">
        <component
            :is="icon"
            class="h-5 w-5"
            :class="
                statusTextClass(
                    record.iconColors[col.name],
                    'text-muted-foreground',
                )
            "
        />
    </div>
</template>
