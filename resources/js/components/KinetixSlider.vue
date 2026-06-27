<script setup lang="ts">
import { SliderRange, SliderRoot, SliderThumb, SliderTrack } from "reka-ui";
import { computed } from "vue";

/** Serialized number config (shared with NumberField). */
interface NumberConfig {
  min?: number | null;
  max?: number | null;
  step?: number | null;
}

/**
 * A single-value range slider built on Reka UI's Slider. Emits a plain number;
 * shows the current value beside the track.
 */
const props = withDefaults(
  defineProps<{
    value?: number | string | null;
    config?: NumberConfig | null;
    disabled?: boolean;
  }>(),
  { value: null, config: null, disabled: false },
);

const emit = defineEmits<{ (e: "update:value", value: number): void }>();

const min = computed(() => props.config?.min ?? 0);
const max = computed(() => props.config?.max ?? 100);
const step = computed(() => props.config?.step ?? 1);

const current = computed<number>(() => {
  const n = Number(props.value);
  return Number.isNaN(n) ? min.value : n;
});

const modelValue = computed<number[]>(() => [current.value]);

function onUpdate(v: number[] | null | undefined): void {
  if (v && v.length) {
    emit("update:value", v[0]);
  }
}
</script>

<template>
  <div class="flex items-center gap-3">
    <SliderRoot
      :model-value="modelValue"
      :min="min"
      :max="max"
      :step="step"
      :disabled="disabled"
      class="relative flex h-5 w-full touch-none items-center select-none"
      :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
      @update:model-value="onUpdate"
    >
      <SliderTrack
        class="relative h-1.5 grow rounded-full bg-muted"
      >
        <SliderRange class="absolute h-full rounded-full bg-primary" />
      </SliderTrack>
      <SliderThumb
        class="block size-4 rounded-full border border-primary bg-background shadow-sm outline-none transition-[color,box-shadow] focus-visible:ring-ring/50 focus-visible:ring-[3px]"
      />
    </SliderRoot>
    <span
      class="w-12 shrink-0 text-right text-sm tabular-nums text-foreground"
      >{{ current }}</span
    >
  </div>
</template>
