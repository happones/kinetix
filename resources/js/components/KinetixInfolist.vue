<script setup lang="ts">
import {
    gridColumnVars,
    resolveColumns,
} from '@/composables/useKinetixResponsiveGrid';
import type { KinetixInfolistData } from '@/types/kinetix';
import './kinetix-grid.css';
import KinetixInfolistEntries from './KinetixInfolistEntries.vue';

defineProps<{
    infolist: KinetixInfolistData;
}>();
</script>

<template>
    <div class="kinetix-grid-host">
        <div
            class="kinetix-grid gap-4 grid"
            :style="gridColumnVars(resolveColumns(infolist.columns))"
        >
            <KinetixInfolistEntries
                :schema="infolist.schema"
                :parent-columns="resolveColumns(infolist.columns)"
            />
        </div>
    </div>
</template>
