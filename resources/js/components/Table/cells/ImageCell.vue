<script setup lang="ts">
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const openImagePreview = (): void => {
    const url = props.record.values[props.col.name];

    if (!url) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('kinetix:preview', {
            detail: { url, type: 'image', label: props.col.label },
        }),
    );
};
</script>

<template>
    <div class="inline-flex items-center">
        <img
            :src="record.values[col.name]"
            class="object-cover"
            :class="[
                col.isCircular
                    ? 'rounded-full'
                    : 'rounded-lg border border-border',
                col.isPreviewable
                    ? 'cursor-zoom-in transition-shadow hover:ring-2 hover:ring-ring'
                    : '',
            ]"
            :style="{
                width: (col.size || 40) + 'px',
                height: (col.size || 40) + 'px',
            }"
            @click.stop="col.isPreviewable ? openImagePreview() : undefined"
        />
    </div>
</template>
