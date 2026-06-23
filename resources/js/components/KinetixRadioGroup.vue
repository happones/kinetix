<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from "reka-ui";

withDefaults(
  defineProps<{
    value?: string | number | null;
    options?: Record<string, string> | null;
    disabled?: boolean;
    inline?: boolean;
  }>(),
  { value: null, options: () => ({}), disabled: false, inline: false },
);

const emit = defineEmits<{
  (e: "update:value", value: string): void;
}>();
</script>

<template>
  <RadioGroupRoot
    :model-value="value !== null && value !== undefined ? String(value) : ''"
    :disabled="disabled"
    :class="
      inline ? 'flex flex-wrap items-center gap-4' : 'flex flex-col gap-2'
    "
    @update:model-value="emit('update:value', $event as string)"
  >
    <label
      v-for="(label, val) in options"
      :key="val"
      class="flex items-center gap-2 text-sm text-foreground"
      :class="disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'"
    >
      <RadioGroupItem
        :value="String(val)"
        class="flex aspect-square size-4 shrink-0 items-center justify-center rounded-full border border-input text-primary shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 dark:bg-input/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary"
      >
        <RadioGroupIndicator class="flex items-center justify-center">
          <span class="h-2 w-2 rounded-full bg-primary" />
        </RadioGroupIndicator>
      </RadioGroupItem>
      {{ label }}
    </label>
  </RadioGroupRoot>
</template>
