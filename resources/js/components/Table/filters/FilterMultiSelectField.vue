<script setup lang="ts">
import type { KinetixTableFilter } from '@/types';
import KinetixCheckboxList from '../../KinetixCheckboxList.vue';

defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();
</script>

<template>
    <KinetixCheckboxList
        :value="(value as string[]) || []"
        :options="filter.options"
        :searchable="filter.isSearchable"
        :search-token="filter.searchToken"
        @update:value="emit('update', $event)"
    />
</template>
