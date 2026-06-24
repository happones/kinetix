<script setup lang="ts">
import { Label } from "reka-ui";
import { cn } from "./primitives/cn";

/**
 * shadcn-vue new-york-v4 `Label`, built on Reka UI's `Label`. Use it for form
 * field labels instead of a raw `<label>` so styling, disabled state and
 * `for`-association stay consistent across the toolkit.
 */
const props = defineProps<{
  for?: string;
  class?: string;
}>();
</script>

<template>
  <Label
    data-slot="label"
    :for="props.for"
    :class="
      cn(
        'flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
        props.class,
      )
    "
  >
    <slot />
  </Label>
</template>
