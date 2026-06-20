<script setup lang="ts">
import type { KinetixWidget } from "@/types";

defineProps<{
  widget: KinetixWidget;
}>();
</script>

<template>
  <div
    class="kinetix-custom-card border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900/50 backdrop-blur-sm rounded-xl p-6 transition-all duration-300 hover:shadow-md"
  >
    <div v-if="widget.title || widget.description" class="mb-4">
      <h3
        v-if="widget.title"
        class="text-base font-semibold text-neutral-900 dark:text-white leading-6"
      >
        {{ widget.title }}
      </h3>
      <p
        v-if="widget.description"
        class="text-xs text-neutral-500 dark:text-neutral-400 mt-1"
      >
        {{ widget.description }}
      </p>
    </div>

    <div class="kinetix-custom-content">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.kinetix-custom-card {
  width: 100%;
}
</style>
