<script setup lang="ts">
import type { KinetixWidget } from "@/types";

defineProps<{
  widget: KinetixWidget;
}>();
</script>

<template>
  <div
    data-slot="card"
    class="kinetix-custom-card border border-border bg-card text-card-foreground flex flex-col gap-6 rounded-xl py-6 shadow-sm transition-all duration-300 hover:shadow-md"
  >
    <div
      v-if="widget.title || widget.description"
      data-slot="card-header"
      class="@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-1.5 px-6"
    >
      <h3
        v-if="widget.title"
        data-slot="card-title"
        class="text-base font-semibold leading-none text-foreground"
      >
        {{ widget.title }}
      </h3>
      <p
        v-if="widget.description"
        data-slot="card-description"
        class="text-xs text-muted-foreground"
      >
        {{ widget.description }}
      </p>
    </div>

    <div data-slot="card-content" class="kinetix-custom-content px-6">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.kinetix-custom-card {
  width: 100%;
}
</style>
