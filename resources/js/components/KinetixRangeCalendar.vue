<script setup lang="ts">
import { parseDate } from "@internationalized/date";
import type { DateValue } from "@internationalized/date";
import { ChevronLeft, ChevronRight } from "@lucide/vue";
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
} from "reka-ui";
import { computed } from "vue";

const props = withDefaults(
  defineProps<{
    value?: { from?: string | null; to?: string | null } | null;
    numberOfMonths?: number;
    locale?: string | null;
    weekdayFormat?: "narrow" | "short" | "long" | null;
    fixedWeeks?: boolean;
    minValue?: string | null;
    maxValue?: string | null;
  }>(),
  {
    value: null,
    numberOfMonths: 1,
    locale: null,
    weekdayFormat: null,
    fixedWeeks: false,
    minValue: null,
    maxValue: null,
  },
);

const emit = defineEmits<{
  (e: "update:value", value: { from: string | null; to: string | null }): void;
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
    emit("update:value", {
      from: next?.start ? next.start.toString() : null,
      to: next?.end ? next.end.toString() : null,
    });
  },
});
</script>

<template>
  <RangeCalendarRoot
    v-slot="{ grid, weekDays }"
    v-model="range"
    :number-of-months="numberOfMonths"
    :locale="locale || undefined"
    :weekday-format="weekdayFormat || undefined"
    :fixed-weeks="fixedWeeks"
    :min-value="minDate"
    :max-value="maxDate"
    class="rounded-md border border-border bg-popover p-3"
  >
    <RangeCalendarHeader class="flex items-center justify-between">
      <RangeCalendarPrev
        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
      >
        <ChevronLeft class="h-4 w-4" />
      </RangeCalendarPrev>
      <RangeCalendarHeading class="text-sm font-medium text-foreground" />
      <RangeCalendarNext
        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
      >
        <ChevronRight class="h-4 w-4" />
      </RangeCalendarNext>
    </RangeCalendarHeader>

    <div class="flex flex-col gap-4 pt-3 sm:flex-row sm:gap-4">
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
              class="w-8 text-center text-[11px] font-normal text-muted-foreground"
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
              class="relative p-0 text-center text-sm"
            >
              <RangeCalendarCellTrigger
                :day="weekDate"
                :month="month.value"
                class="flex h-8 w-8 items-center justify-center rounded-md text-sm text-foreground hover:bg-accent data-[outside-view]:text-muted-foreground/50 data-[disabled]:opacity-40 data-[highlighted]:bg-accent data-[selected]:bg-primary data-[selected]:text-primary-foreground"
              />
            </RangeCalendarCell>
          </RangeCalendarGridRow>
        </RangeCalendarGridBody>
      </RangeCalendarGrid>
    </div>
  </RangeCalendarRoot>
</template>
