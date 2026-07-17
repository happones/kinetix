<script setup lang="ts">
import KinetixMonthPicker from '../../KinetixMonthPicker.vue';
import KinetixWeekPicker from '../../KinetixWeekPicker.vue';
import KinetixYearPicker from '../../KinetixYearPicker.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- Month / Year / Week pickers (shadcn or native via ->native()) -->
    <KinetixMonthPicker
        v-if="comp.type === 'month-picker'"
        :value="value"
        :native="!comp.useCalendar"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        :min-value="comp.minValue"
        :max-value="comp.maxValue"
        @update:value="emit('update', $event)"
    />
    <KinetixYearPicker
        v-else-if="comp.type === 'year-picker'"
        :value="value"
        :native="!comp.useCalendar"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :min-value="comp.minValue"
        :max-value="comp.maxValue"
        @update:value="emit('update', $event)"
    />
    <KinetixWeekPicker
        v-else-if="comp.type === 'week-picker'"
        :value="value"
        :native="!comp.useCalendar"
        :disabled="comp.isDisabled"
        :placeholder="comp.placeholder"
        :locale="comp.dateLocale"
        :week-starts-on="comp.weekStartsOn ?? 1"
        :min-value="comp.minValue"
        :max-value="comp.maxValue"
        @update:value="emit('update', $event)"
    />
</template>
