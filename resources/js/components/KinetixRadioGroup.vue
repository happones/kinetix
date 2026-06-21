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
        class="flex aspect-square h-4 w-4 items-center justify-center rounded-full border border-input text-primary shadow-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary"
      >
        <RadioGroupIndicator class="flex items-center justify-center">
          <span class="h-2 w-2 rounded-full bg-primary" />
        </RadioGroupIndicator>
      </RadioGroupItem>
      {{ label }}
    </label>
  </RadioGroupRoot>
</template>
