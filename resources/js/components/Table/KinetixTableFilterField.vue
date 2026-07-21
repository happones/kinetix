<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import type { KinetixTableFilter } from '@/types';
import FilterAddressField from './filters/FilterAddressField.vue';
import FilterCheckboxField from './filters/FilterCheckboxField.vue';
import FilterDateField from './filters/FilterDateField.vue';
import FilterDateRangeField from './filters/FilterDateRangeField.vue';
import FilterMultiSelectField from './filters/FilterMultiSelectField.vue';
import FilterNumberRangeField from './filters/FilterNumberRangeField.vue';
import FilterPeriodField from './filters/FilterPeriodField.vue';
import FilterSelectField from './filters/FilterSelectField.vue';

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

/**
 * Map each filter type to the field component that renders it. Resolving by
 * lookup (O(1)) rather than a `v-if`/`v-else-if` chain keeps the render fn flat,
 * avoids re-evaluating every branch condition on each update, and makes adding a
 * filter type a one-line map entry. Each field shares the same
 * `{ filter, value } -> update` contract.
 */
const FIELD_COMPONENTS: Record<KinetixTableFilter['type'], Component> = {
    select: FilterSelectField,
    ternary: FilterSelectField,
    checkbox: FilterCheckboxField,
    'multi-select': FilterMultiSelectField,
    date: FilterDateField,
    datetime: FilterDateField,
    'date-range': FilterDateRangeField,
    'number-range': FilterNumberRangeField,
    month: FilterPeriodField,
    year: FilterPeriodField,
    week: FilterPeriodField,
    address: FilterAddressField,
};

const fieldComponent = computed<Component | null>(
    () => FIELD_COMPONENTS[props.filter.type] ?? null,
);
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
