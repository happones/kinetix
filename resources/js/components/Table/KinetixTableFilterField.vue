<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import type { KinetixTableFilter } from '@/types/kinetix';
import { KINETIX_TABLE_FILTER_LOADERS as LAZY } from './kinetixTableFilterLoaders';

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

/**
 * Each filter type maps to the field component that renders it. Resolving by
 * lookup (O(1)) rather than a `v-if`/`v-else-if` chain keeps the render fn flat,
 * avoids re-evaluating every branch condition on each update, and makes adding a
 * filter type a one-line map entry. Each field shares the same
 * `{ filter, value } -> update` contract.
 *
 * Every input is a lazy entry the filters popover starts downloading on
 * intent (see kinetixTableFilterLoaders), so none ships with the table.
 */

// Resolved from the type alone, so a lazy entry's wrapper is never swapped
// for the real component mid-edit (that would remount the input).
const filterType = computed<KinetixTableFilter['type']>(
    () => props.filter.type,
);

const fieldComponent = computed<Component | null>(() => {
    const entry = LAZY[filterType.value];

    return entry ? kinetixComponentOf(entry) : null;
});
</script>

<template>
    <component
        :is="fieldComponent"
        v-if="fieldComponent"
        :filter="filter"
        :value="value"
        @update="emit('update', $event)"
    />
</template>
