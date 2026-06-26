<script setup lang="ts">
import { computed } from "vue";
import ScrollArea from "./primitives/ScrollArea.vue";
import { cn } from "./primitives/cn";
import { buttonVariants } from "@/composables/useShadcnVariants";

/**
 * Time-only picker. Renders the shadcn scrollable hour/minute (+ AM/PM) columns
 * by default, or a native <input type="time"> when `native`. Value is a 24-hour
 * 'H:i' string (e.g. "14:30").
 */
const props = withDefaults(
  defineProps<{
    /** Selected value as an 'H:i' string (24-hour storage). */
    value?: string | null;
    native?: boolean;
    hour12?: boolean;
    disabled?: boolean;
    minuteStep?: number;
  }>(),
  { value: null, native: false, hour12: false, disabled: false, minuteStep: 5 },
);

const emit = defineEmits<{
  (e: "update:value", value: string | null): void;
}>();

const pad = (n: number) => String(n).padStart(2, "0");

const hasValue = computed(() => props.value != null && props.value !== "");
const hour24 = computed(() => Number(props.value?.slice(0, 2) ?? "0") || 0);
const minute = computed(() => Number(props.value?.slice(3, 5) ?? "0") || 0);
const isPm = computed(() => hour24.value >= 12);
const displayHour = computed(() => hour24.value % 12 || 12);

const hours = computed(() =>
  props.hour12
    ? Array.from({ length: 12 }, (_, i) => i + 1)
    : Array.from({ length: 24 }, (_, i) => i),
);
const minutes = computed(() =>
  Array.from({ length: Math.ceil(60 / props.minuteStep) }, (_, i) => i * props.minuteStep),
);

const emitTime = (h: number, m: number) => {
  emit("update:value", `${pad(h)}:${pad(m)}`);
};

const setHour24 = (h: number) => emitTime(h, minute.value);
const setHour12 = (h: number) => {
  // Map a clicked 1-12 hour to 24h, keeping the current AM/PM half.
  const next = isPm.value ? (h % 12) + 12 : h % 12;
  emitTime(next, minute.value);
};
const setMinute = (m: number) => emitTime(hour24.value, m);
const setMeridiem = (meridiem: "AM" | "PM") => {
  const h = hour24.value;
  const next =
    meridiem === "PM" && h < 12 ? h + 12 : meridiem === "AM" && h >= 12 ? h - 12 : h;
  emitTime(next, minute.value);
};

const timeBtn = (active: boolean) =>
  cn(
    buttonVariants({ variant: active ? "default" : "ghost", size: "icon-sm" }),
    "aspect-square shrink-0 sm:w-full",
  );
</script>

<template>
  <input
    v-if="native"
    type="time"
    :value="value"
    :disabled="disabled"
    @input="emit('update:value', ($event.target as HTMLInputElement).value || null)"
  />

  <div
    v-else
    class="inline-flex h-[180px] w-fit divide-x divide-border rounded-md border border-border"
  >
    <!-- Hours -->
    <ScrollArea class="h-full" type="always">
      <div class="flex flex-col gap-1 p-2">
        <button
          v-for="h in hours"
          :key="`h-${h}`"
          type="button"
          :disabled="disabled"
          :class="timeBtn(hasValue && (hour12 ? displayHour === h : hour24 === h))"
          @click="hour12 ? setHour12(h) : setHour24(h)"
        >
          {{ pad(h) }}
        </button>
      </div>
    </ScrollArea>
    <!-- Minutes -->
    <ScrollArea class="h-full" type="always">
      <div class="flex flex-col gap-1 p-2">
        <button
          v-for="m in minutes"
          :key="`m-${m}`"
          type="button"
          :disabled="disabled"
          :class="timeBtn(hasValue && minute === m)"
          @click="setMinute(m)"
        >
          {{ pad(m) }}
        </button>
      </div>
    </ScrollArea>
    <!-- AM/PM (12h only) -->
    <ScrollArea v-if="hour12" class="h-full">
      <div class="flex flex-col gap-1 p-2">
        <button
          v-for="meridiem in ['AM', 'PM']"
          :key="meridiem"
          type="button"
          :disabled="disabled"
          :class="
            timeBtn(
              hasValue &&
                ((meridiem === 'AM' && !isPm) || (meridiem === 'PM' && isPm)),
            )
          "
          @click="setMeridiem(meridiem as 'AM' | 'PM')"
        >
          {{ meridiem }}
        </button>
      </div>
    </ScrollArea>
  </div>
</template>
