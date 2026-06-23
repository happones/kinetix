<script setup lang="ts">
import { cn } from "./cn";

// Mirrors shadcn-vue new-york-v4 `ui/card/CardTitle.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
  <div
    data-slot="card-title"
    :class="cn('leading-none font-semibold', props.class)"
  >
    <slot />
  </div>
</template>
