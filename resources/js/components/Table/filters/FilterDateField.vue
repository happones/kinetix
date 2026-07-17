<script setup lang="ts">
import KinetixDatePicker from '../../KinetixDatePicker.vue';
import KinetixDateTimePicker from '../../KinetixDateTimePicker.vue';
import type { KinetixTableFilter } from '@/types';

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const NATIVE_INPUT_CLASS =
    'text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const isDateTime = props.filter.type === 'datetime';
</script>

<template>
    <!-- shadcn calendar / picker variants -->
    <KinetixDateTimePicker
        v-if="isDateTime && filter.useCalendar"
        :value="(value as string) || null"
        :locale="filter.locale"
        :minute-step="filter.minuteStep"
        :hour12="filter.hour12"
        @update:value="emit('update', $event)"
    />
    <KinetixDatePicker
        v-else-if="filter.useCalendar"
        :value="(value as string) || null"
        :locale="filter.locale"
        :min-value="filter.minValue"
        :max-value="filter.maxValue"
        @update:value="emit('update', $event)"
    />

    <!-- Native fallbacks -->
    <input
        v-else-if="isDateTime"
        type="datetime-local"
        :value="(value as string) || ''"
        :class="NATIVE_INPUT_CLASS"
        @change="emit('update', ($event.target as HTMLInputElement).value)"
    />
    <input
        v-else
        type="date"
        :value="(value as string) || ''"
        :class="NATIVE_INPUT_CLASS"
        @change="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
