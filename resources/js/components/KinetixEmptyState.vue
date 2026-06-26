<script setup lang="ts">
import { resolveIcon } from "@/composables/useKinetixIcons";

/**
 * Reusable empty-state block: an icon, a heading, a description and an optional
 * actions slot. Drop it wherever a list/table/section has no data yet. Pure
 * presentational — no backend, no store.
 */
withDefaults(
  defineProps<{
    /** Lucide icon name (see useKinetixIcons) — optional. */
    icon?: string | null;
    title: string;
    description?: string | null;
  }>(),
  { icon: null, description: null },
);
</script>

<template>
  <div
    class="flex flex-col items-center justify-center rounded-lg border border-dashed border-border px-6 py-12 text-center"
  >
    <div
      v-if="resolveIcon(icon)"
      class="mb-4 flex size-12 items-center justify-center rounded-full bg-muted"
    >
      <component :is="resolveIcon(icon)" class="size-6 text-muted-foreground" />
    </div>
    <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
    <p
      v-if="description"
      class="mt-1 max-w-sm text-sm text-muted-foreground"
    >
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-4 flex items-center gap-2">
      <slot />
    </div>
  </div>
</template>
