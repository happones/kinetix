<script setup lang="ts">
import { parseDate } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import { computed } from 'vue';
import { cn } from './primitives/cn';

/**
 * A calendar that selects a whole week: clicking any day highlights its entire
 * (region-aware) week row. The selected `value` day is just the anchor used to
 * compute + highlight the week. `weekStartsOn`: 0=Sun … 6=Sat (default 1=Mon).
 */
const props = withDefaults(
    defineProps<{
        /** Anchor day (ISO 'Y-m-d') whose week is highlighted. */
        value?: string | null;
        weekStartsOn?: number;
        locale?: string | null;
        numberOfMonths?: number;
    }>(),
    { value: null, weekStartsOn: 1, locale: null, numberOfMonths: 1 },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

// Cast the numeric prop to Reka's WeekStartsOn union here (keeps the union out of
// the template, where `|` is otherwise parsed as a deprecated Vue filter).
const weekStartsOnValue = computed(
    () => props.weekStartsOn as 0 | 1 | 2 | 3 | 4 | 5 | 6,
);

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

const toDateValue = (s?: string | null): DateValue | undefined => {
    if (!s) {
        return undefined;
    }

    try {
        return parseDate(s.slice(0, 10));
    } catch {
        return undefined;
    }
};

const selected = computed({
    get: () => toDateValue(props.value),
    set: (next) => emit('update:value', next ? next.toString() : null),
});

// [start, end] ISO strings of the highlighted week (aligned to weekStartsOn).
const week = computed<{ start: string; end: string } | null>(() => {
    if (!props.value) {
        return null;
    }

    const d = new Date(props.value.slice(0, 10) + 'T00:00:00');
    const offset = (d.getDay() - props.weekStartsOn + 7) % 7;
    const start = new Date(d);
    start.setDate(d.getDate() - offset);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);

    return { start: iso(start), end: iso(end) };
});

const inWeek = (date: DateValue): boolean => {
    if (!week.value) {
        return false;
    }

    const s = date.toString();

    return s >= week.value.start && s <= week.value.end;
};
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        v-model="selected"
        :number-of-months="numberOfMonths"
        :locale="locale || undefined"
        :week-starts-on="weekStartsOnValue"
        class="p-3 rounded-md border border-border bg-popover"
    >
        <CalendarHeader class="flex items-center justify-between">
            <CalendarPrev
                class="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
            >
                <ChevronLeft class="h-4 w-4" />
            </CalendarPrev>
            <CalendarHeading class="text-sm font-medium text-foreground" />
            <CalendarNext
                class="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
            >
                <ChevronRight class="h-4 w-4" />
            </CalendarNext>
        </CalendarHeader>

        <div class="gap-4 pt-3 sm:flex-row sm:gap-4 flex flex-col">
            <CalendarGrid
                v-for="month in grid"
                :key="month.value.toString()"
                class="w-full border-collapse select-none"
            >
                <CalendarGridHead>
                    <CalendarGridRow class="flex">
                        <CalendarHeadCell
                            v-for="day in weekDays"
                            :key="day"
                            class="w-8 font-normal text-center text-[11px] text-muted-foreground"
                        >
                            {{ day }}
                        </CalendarHeadCell>
                    </CalendarGridRow>
                </CalendarGridHead>
                <CalendarGridBody>
                    <CalendarGridRow
                        v-for="(weekDates, index) in month.rows"
                        :key="`week-${index}`"
                        class="mt-1 flex w-full"
                    >
                        <CalendarCell
                            v-for="weekDate in weekDates"
                            :key="weekDate.toString()"
                            :date="weekDate"
                            class="p-0 text-sm relative text-center"
                        >
                            <CalendarCellTrigger
                                :day="weekDate"
                                :month="month.value"
                                :class="
                                    cn(
                                        'h-8 w-8 text-sm flex items-center justify-center rounded-md text-foreground hover:bg-accent data-[disabled]:opacity-40 data-[outside-view]:text-muted-foreground/50',
                                        inWeek(weekDate) &&
                                            'bg-primary text-primary-foreground hover:bg-primary',
                                    )
                                "
                            />
                        </CalendarCell>
                    </CalendarGridRow>
                </CalendarGridBody>
            </CalendarGrid>
        </div>
    </CalendarRoot>
</template>
