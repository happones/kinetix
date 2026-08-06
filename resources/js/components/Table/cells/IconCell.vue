<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { statusTextClass } from '@/composables/useKinetixStatusColor';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

// The shared Kinetix icon map — any name IconColumn::options() declares works,
// not just a hardcoded handful.
const icon = computed<Component | null>(() => {
    const name = props.record.icons[props.col.name];

    return name ? (resolveIcon(name) ?? null) : null;
});

const sizePx = computed(() => {
    const size = props.col.size;

    return typeof size === 'number' && size > 0 ? `${size}px` : undefined;
});
</script>

<template>
    <div
        class="inline-flex items-center justify-center"
        :title="col.tooltip ?? undefined"
    >
        <component
            :is="icon"
            class="h-5 w-5"
            :style="sizePx ? { width: sizePx, height: sizePx } : undefined"
            :class="
                statusTextClass(
                    record.iconColors[col.name],
                    'text-muted-foreground',
                )
            "
        />
    </div>
</template>
