<script setup lang="ts">
import KinetixMonthPicker from '../../KinetixMonthPicker.vue';
import KinetixWeekPicker from '../../KinetixWeekPicker.vue';
import KinetixYearPicker from '../../KinetixYearPicker.vue';
import type { KinetixTableFilter } from '@/types';

defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();
</script>

<template>
    <KinetixMonthPicker
        v-if="filter.type === 'month'"
        :value="(value as string) || null"
        :native="!filter.useCalendar"
        :locale="filter.locale"
        :min-value="filter.minValue"
        :max-value="filter.maxValue"
        @update:value="emit('update', $event)"
    />
    <KinetixYearPicker
        v-else-if="filter.type === 'year'"
        :value="(value as string) || null"
        :native="!filter.useCalendar"
        :min-value="filter.minValue"
        :max-value="filter.maxValue"
        @update:value="emit('update', $event)"
    />
    <KinetixWeekPicker
        v-else-if="filter.type === 'week'"
        :value="(value as string) || null"
        :native="!filter.useCalendar"
        :locale="filter.locale"
        :week-starts-on="filter.weekStartsOn ?? 1"
        :min-value="filter.minValue"
        :max-value="filter.maxValue"
        @update:value="emit('update', $event)"
    />
</template>
