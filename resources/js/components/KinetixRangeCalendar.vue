<script setup lang="ts">
import { parseDate } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    RangeCalendarCell,
    RangeCalendarCellTrigger,
    RangeCalendarGrid,
    RangeCalendarGridBody,
    RangeCalendarGridHead,
    RangeCalendarGridRow,
    RangeCalendarHeadCell,
    RangeCalendarHeader,
    RangeCalendarHeading,
    RangeCalendarNext,
    RangeCalendarPrev,
    RangeCalendarRoot,
} from 'reka-ui';
import { computed } from 'vue';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import {
    useKinetixTimezone,
    zonedTodayIso,
} from '@/composables/useKinetixTimezone';

const props = withDefaults(
    defineProps<{
        value?: { from?: string | null; to?: string | null } | null;
        numberOfMonths?: number;
        locale?: string | null;
        weekdayFormat?: 'narrow' | 'short' | 'long' | null;
        fixedWeeks?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
        /**
         * IANA timezone whose "today" decides the initially displayed month
         * when there is no value. Defaults to the app timezone Kinetix shares
         * (`config('app.timezone')`), then the browser clock.
         */
        timezone?: string | null;
    }>(),
    {
        value: null,
        numberOfMonths: 1,
        locale: null,
        weekdayFormat: null,
        fixedWeeks: false,
        minValue: null,
        maxValue: null,
        timezone: null,
    },
);

const emit = defineEmits<{
    (
        e: 'update:value',
        value: { from: string | null; to: string | null },
    ): void;
}>();

const toDateValue = (iso?: string | null): DateValue | undefined => {
    if (!iso) {
        return undefined;
    }

    try {
        return parseDate(iso);
    } catch {
        return undefined;
    }
};

const minDate = computed(() => toDateValue(props.minValue));
const maxDate = computed(() => toDateValue(props.maxValue));

// Bridge ISO date strings (the filter value) ↔ Reka's { start, end } DateValue range.
const range = computed({
    get: () => ({
        start: toDateValue(props.value?.from),
        end: toDateValue(props.value?.to),
    }),
    set: (next) => {
        emit('update:value', {
            from: next?.start ? next.start.toString() : null,
            to: next?.end ? next.end.toString() : null,
        });
    },
});

// With no value, open on "today" AS THE APP TIMEZONE SEES IT — around
// midnight the browser's month can differ from the app's.
const effectiveTimezone = useKinetixTimezone(() => props.timezone);
const initialPlaceholder = computed<DateValue | undefined>(
    () =>
        toDateValue(props.value?.from ?? undefined) ??
        toDateValue(zonedTodayIso(effectiveTimezone.value)),
);
</script>

<template>
    <RangeCalendarRoot
        v-slot="{ grid, weekDays }"
        v-model="range"
        :default-placeholder="initialPlaceholder"
        :number-of-months="numberOfMonths"
        :locale="locale || undefined"
        :weekday-format="weekdayFormat || undefined"
        :fixed-weeks="fixedWeeks"
        :min-value="minDate"
        :max-value="maxDate"
        class="p-3 rounded-md border border-border bg-popover"
    >
        <RangeCalendarHeader class="flex items-center justify-between">
            <RangeCalendarPrev
                :class="
                    buttonVariants({ variant: 'ghost', size: 'icon-sm' }) +
                    ' text-muted-foreground'
                "
            >
                <ChevronLeft class="h-4 w-4" />
            </RangeCalendarPrev>
            <RangeCalendarHeading class="text-sm font-medium text-foreground" />
            <RangeCalendarNext
                :class="
                    buttonVariants({ variant: 'ghost', size: 'icon-sm' }) +
                    ' text-muted-foreground'
                "
            >
                <ChevronRight class="h-4 w-4" />
            </RangeCalendarNext>
        </RangeCalendarHeader>

        <div class="gap-4 pt-3 sm:flex-row sm:gap-4 flex flex-col">
            <RangeCalendarGrid
                v-for="month in grid"
                :key="month.value.toString()"
                class="w-full border-collapse select-none"
            >
                <RangeCalendarGridHead>
                    <RangeCalendarGridRow class="flex">
                        <RangeCalendarHeadCell
                            v-for="day in weekDays"
                            :key="day"
                            class="w-8 font-normal text-center text-[11px] text-muted-foreground"
                        >
                            {{ day }}
                        </RangeCalendarHeadCell>
                    </RangeCalendarGridRow>
                </RangeCalendarGridHead>
                <RangeCalendarGridBody>
                    <RangeCalendarGridRow
                        v-for="(weekDates, index) in month.rows"
                        :key="`week-${index}`"
                        class="mt-1 flex w-full"
                    >
                        <RangeCalendarCell
                            v-for="weekDate in weekDates"
                            :key="weekDate.toString()"
                            :date="weekDate"
                            class="p-0 text-sm relative text-center"
                        >
                            <RangeCalendarCellTrigger
                                :day="weekDate"
                                :month="month.value"
                                class="h-8 w-8 text-sm flex items-center justify-center rounded-md text-foreground hover:bg-accent data-[disabled]:opacity-40 data-[highlighted]:bg-accent data-[outside-view]:text-muted-foreground/50 data-[selected]:bg-primary data-[selected]:text-primary-foreground"
                            />
                        </RangeCalendarCell>
                    </RangeCalendarGridRow>
                </RangeCalendarGridBody>
            </RangeCalendarGrid>
        </div>
    </RangeCalendarRoot>
</template>
