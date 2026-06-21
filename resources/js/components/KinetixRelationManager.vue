<script setup lang="ts">
import type { KinetixRelationManagerData } from "@/types";
import KinetixTable from "./KinetixTable.vue";

defineProps<{
  manager: KinetixRelationManagerData;
}>();
</script>

<template>
  <section class="space-y-3">
    <h2
      class="text-lg font-semibold tracking-tight text-foreground"
    >
      {{ manager.title }}
    </h2>

    <KinetixTable :table="manager.table" />
  </section>
</template>
