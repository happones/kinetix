<script setup lang="ts">
import type { KinetixInfolistData } from '@/types/kinetix';
import KinetixInfolistEntries from './KinetixInfolistEntries.vue';

defineProps<{
    infolist: KinetixInfolistData;
}>();
</script>

<template>
    <div
        class="gap-4 grid"
        :style="{
            gridTemplateColumns: `repeat(${infolist.columns || 1}, minmax(0, 1fr))`,
        }"
    >
        <KinetixInfolistEntries :schema="infolist.schema" />
    </div>
</template>
