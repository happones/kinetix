<script setup lang="ts">
import { cn } from "./cn";

// Mirrors shadcn-vue new-york-v4 `ui/card/Card.vue`.
const props = defineProps<{ class?: string }>();
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-6 rounded-xl border border-border py-6 shadow-sm',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
