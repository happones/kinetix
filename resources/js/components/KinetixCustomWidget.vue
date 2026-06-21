<script setup lang="ts">
import type { KinetixWidget } from "@/types";

defineProps<{
  widget: KinetixWidget;
}>();
</script>

<template>
  <div
    class="kinetix-custom-card border border-border bg-card backdrop-blur-sm rounded-xl p-6 transition-all duration-300 hover:shadow-md"
  >
    <div v-if="widget.title || widget.description" class="mb-4">
      <h3
        v-if="widget.title"
        class="text-base font-semibold text-foreground leading-6"
      >
        {{ widget.title }}
      </h3>
      <p
        v-if="widget.description"
        class="text-xs text-muted-foreground mt-1"
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
