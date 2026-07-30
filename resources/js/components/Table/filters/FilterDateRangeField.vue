<script setup lang="ts">
import type { KinetixTableFilter } from '@/types/kinetix';
import KinetixRangeCalendar from '../../KinetixRangeCalendar.vue';

type DateRangeValue = { from?: string | null; to?: string | null } | null;

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const NATIVE_INPUT_CLASS =
    'text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/** Merge a single bound into the current `{ from, to }` value. */
const setPart = (part: 'from' | 'to', partValue: string): void => {
    emit('update', { ...((props.value as object) || {}), [part]: partValue });
};

const bound = (part: 'from' | 'to'): string =>
    ((props.value as Record<string, string>) || {})[part] || '';
</script>

<template>
    <!-- shadcn calendar range -->
    <KinetixRangeCalendar
        v-if="filter.useCalendar"
        :value="value as DateRangeValue"
        :number-of-months="filter.numberOfMonths"
        :locale="filter.locale"
        :weekday-format="filter.weekdayFormat"
        :fixed-weeks="filter.fixedWeeks"
        :min-value="filter.minValue"
        :max-value="filter.maxValue"
        @update:value="emit('update', $event)"
    />

    <!-- Native from/to inputs -->
    <div v-else class="gap-2 flex items-center">
        <input
            type="date"
            :value="bound('from')"
            :class="NATIVE_INPUT_CLASS"
            @change="setPart('from', ($event.target as HTMLInputElement).value)"
        />
        <span class="text-xs text-muted-foreground">–</span>
        <input
            type="date"
            :value="bound('to')"
            :class="NATIVE_INPUT_CLASS"
            @change="setPart('to', ($event.target as HTMLInputElement).value)"
        />
    </div>
</template>
