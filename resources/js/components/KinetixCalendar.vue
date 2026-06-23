<script setup lang="ts">
import { parseDate } from "@internationalized/date";
import type { DateValue } from "@internationalized/date";
import { ChevronLeft, ChevronRight } from "@lucide/vue";
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
} from "reka-ui";
import { computed } from "vue";

const props = withDefaults(
  defineProps<{
    /** Selected date as an ISO 'Y-m-d' string. */
    value?: string | null;
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
  (e: "update:value", value: string | null): void;
}>();

const toDateValue = (iso?: string | null): DateValue | undefined => {
  if (!iso) {
    return undefined;
  }

  try {
    // Date-only portion so 'Y-m-dTH:i' datetime values still parse.
    return parseDate(iso.slice(0, 10));
  } catch {
    return undefined;
  }
};

const minDate = computed(() => toDateValue(props.minValue));
const maxDate = computed(() => toDateValue(props.maxValue));

// Bridge an ISO date string ↔ Reka's single DateValue model.
const selected = computed({
  get: () => toDateValue(props.value),
  set: (next) => emit("update:value", next ? next.toString() : null),
});
</script>

<template>
  <CalendarRoot
    v-slot="{ grid, weekDays }"
    v-model="selected"
    :number-of-months="numberOfMonths"
    :locale="locale || undefined"
    :weekday-format="weekdayFormat || undefined"
    :fixed-weeks="fixedWeeks"
    :min-value="minDate"
    :max-value="maxDate"
    class="rounded-md border border-border bg-popover p-3"
  >
    <CalendarHeader class="flex items-center justify-between">
      <CalendarPrev
        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
      >
        <ChevronLeft class="h-4 w-4" />
      </CalendarPrev>
      <CalendarHeading class="text-sm font-medium text-foreground" />
      <CalendarNext
        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
      >
        <ChevronRight class="h-4 w-4" />
      </CalendarNext>
    </CalendarHeader>

    <div class="flex flex-col gap-4 pt-3 sm:flex-row sm:gap-4">
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
              class="w-8 text-center text-[11px] font-normal text-muted-foreground"
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
              class="relative p-0 text-center text-sm"
            >
              <CalendarCellTrigger
                :day="weekDate"
                :month="month.value"
                class="flex h-8 w-8 items-center justify-center rounded-md text-sm text-foreground hover:bg-accent data-[outside-view]:text-muted-foreground/50 data-[disabled]:opacity-40 data-[selected]:bg-primary data-[selected]:text-primary-foreground"
              />
            </CalendarCell>
          </CalendarGridRow>
        </CalendarGridBody>
      </CalendarGrid>
    </div>
  </CalendarRoot>
</template>
