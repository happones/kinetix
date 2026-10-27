<script setup lang="ts">
import { computed } from 'vue';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixLightboxImage from '../../KinetixLightboxImage.vue';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const url = computed(() => props.record.values[props.col.name] || null);
const size = computed(() => {
    const pixels = `${props.col.size || 40}px`;

    return { width: pixels, height: pixels };
});
const shape = computed(() =>
    props.col.isCircular ? 'rounded-full' : 'rounded-lg border border-border',
);
/** The column's previewable images page as one gallery within this table. */
const group = computed(() =>
    props.col.isPreviewGallery === false
        ? null
        : `kinetix-column:${props.col.name}`,
);
</script>

<template>
    <div class="inline-flex items-center">
        <KinetixLightboxImage
            v-if="col.isPreviewable && url"
            :src="url"
            :alt="col.label"
            :caption="col.label"
            :group="group"
            scope="table"
            :circular="col.isCircular"
            @click.stop
        >
            <img
                :src="url"
                :alt="col.label"
                class="object-cover"
                :class="shape"
                :style="size"
            />
        </KinetixLightboxImage>
        <img
            v-else
            :src="record.values[col.name]"
            class="object-cover"
            :class="shape"
            :style="size"
        />
    </div>
</template>
