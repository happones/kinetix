<script setup lang="ts">
import { computed } from 'vue';
import {
    gridColumnVars,
    resolveColumns,
} from '@/composables/useKinetixResponsiveGrid';
import type { KinetixInfolistData } from '@/types/kinetix';
import './kinetix-grid.css';
import KinetixInfolistEntries from './KinetixInfolistEntries.vue';

const props = withDefaults(
    defineProps<{
        infolist: KinetixInfolistData;
        /**
         * Give a BARE schema (no top-level Section/Tabs/Fieldset/Grid) a card
         * surface so detail pages never render floating label/value pairs.
         * Hosts that already provide the surface (the table's View modal)
         * pass `false`. Schemas with their own layout nodes are never wrapped.
         */
        surface?: boolean;
        /**
         * Chrome-free Sections/Tabs for infolists hosted inside a modal —
         * the panel is already the surface. Implies no bare-schema wrap.
         */
        flat?: boolean;
    }>(),
    {
        surface: true,
        flat: false,
    },
);

const LAYOUT_TYPES = new Set(['section', 'tabs', 'fieldset', 'grid']);

const hasLayout = computed(() =>
    (props.infolist.schema ?? []).some((entry) =>
        LAYOUT_TYPES.has(entry.type ?? ''),
    ),
);

const wrap = computed(() => props.surface && !props.flat && !hasLayout.value);
</script>

<template>
    <div
        class="kinetix-grid-host"
        :class="
            wrap
                ? 'rounded-xl p-6 shadow-sm border border-border bg-card text-card-foreground'
                : undefined
        "
    >
        <div
            class="kinetix-grid grid"
            :class="wrap ? 'gap-x-4 gap-y-5' : 'gap-4'"
            :style="gridColumnVars(resolveColumns(infolist.columns))"
        >
            <KinetixInfolistEntries
                :schema="infolist.schema"
                :parent-columns="resolveColumns(infolist.columns)"
                :flat="flat"
            />
        </div>
    </div>
</template>
