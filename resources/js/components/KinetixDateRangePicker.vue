<script setup lang="ts">
import { CalendarIcon } from "@lucide/vue";
import {
  PopoverContent,
  PopoverPortal,
  PopoverRoot,
  PopoverTrigger,
} from "reka-ui";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import KinetixRangeCalendar from "./KinetixRangeCalendar.vue";
import { cn } from "./primitives/cn";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";

type Range = { from?: string | null; to?: string | null } | null;

/**
 * Date-range field. Renders the shadcn range calendar in a popover by default,
 * or two native <input type="date"> via `native`. Value is `{ from, to }`.
 */
const props = withDefaults(
  defineProps<{
    value?: Range;
    native?: boolean;
    disabled?: boolean;
    placeholder?: string | null;
    locale?: string | null;
    weekdayFormat?: "narrow" | "short" | "long" | null;
    numberOfMonths?: number;
    fixedWeeks?: boolean;
    minValue?: string | null;
    maxValue?: string | null;
  }>(),
  {
    value: null,
    native: false,
    disabled: false,
    placeholder: null,
    locale: null,
    weekdayFormat: null,
    numberOfMonths: 1,
    fixedWeeks: false,
    minValue: null,
    maxValue: null,
  },
);

const emit = defineEmits<{ (e: "update:value", value: Range): void }>();

const { t } = useI18n();
const open = ref(false);

const fmt = (d?: string | null) => {
  if (!d) {
    return null;
  }
  const [y, m, day] = d.slice(0, 10).split("-").map(Number);
  return new Date(y, m - 1, day).toLocaleDateString(props.locale || undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

const label = computed(() => {
  const from = fmt(props.value?.from);
  const to = fmt(props.value?.to);
  if (!from && !to) {
    return null;
  }
  return `${from ?? "…"} – ${to ?? "…"}`;
});

const setPart = (part: "from" | "to", v: string) => {
  emit("update:value", { ...(props.value ?? {}), [part]: v || null });
};

const onCalendar = (range: Range) => {
  emit("update:value", range);
  if (range?.from && range?.to) {
    open.value = false;
  }
};
</script>

<template>
  <div v-if="native" class="flex items-center gap-2">
    <input
      type="date"
      :value="value?.from ?? ''"
      :disabled="disabled"
      :min="minValue || undefined"
      :max="maxValue || undefined"
      :class="inputClass"
      @change="setPart('from', ($event.target as HTMLInputElement).value)"
    />
    <span class="text-muted-foreground">–</span>
    <input
      type="date"
      :value="value?.to ?? ''"
      :disabled="disabled"
      :min="minValue || undefined"
      :max="maxValue || undefined"
      :class="inputClass"
      @change="setPart('to', ($event.target as HTMLInputElement).value)"
    />
  </div>

  <PopoverRoot v-else v-model:open="open">
    <PopoverTrigger
      :disabled="disabled"
      :class="
        cn(
          buttonVariants({ variant: 'outline' }),
          'w-full justify-start text-left font-normal',
          !label && 'text-muted-foreground',
        )
      "
    >
      <CalendarIcon class="mr-2 h-4 w-4" />
      {{ label ?? placeholder ?? t("kinetix.pick_date_range") }}
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent
        align="start"
        :side-offset="4"
        class="z-50 w-auto p-0 outline-none"
      >
        <KinetixRangeCalendar
          :value="value"
          :locale="locale"
          :weekday-format="weekdayFormat"
          :number-of-months="numberOfMonths"
          :fixed-weeks="fixedWeeks"
          :min-value="minValue"
          :max-value="maxValue"
          @update:value="onCalendar"
        />
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
