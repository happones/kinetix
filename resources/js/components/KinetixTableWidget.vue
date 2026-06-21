<script setup lang="ts">
import { computed } from "vue";
import type { KinetixWidget } from "@/types";

const props = defineProps<{
  widget: KinetixWidget;
}>();

const headers = computed<string[]>(() => {
  if (!props.widget.data || !Array.isArray(props.widget.data.headers)) {
    return [];
  }

  return props.widget.data.headers;
});

const rows = computed<any[]>(() => {
  if (!props.widget.data || !Array.isArray(props.widget.data.rows)) {
    return [];
  }

  return props.widget.data.rows;
});

const getRowValues = (row: any) => {
  if (Array.isArray(row)) {
    return row;
  }

  if (typeof row === "object" && row !== null) {
    return Object.values(row);
  }

  return [row];
};
</script>

<template>
  <div
    data-slot="card"
    class="kinetix-table-card border border-border bg-card text-card-foreground flex flex-col gap-6 rounded-xl py-6 shadow-sm transition-all duration-300 hover:shadow-md overflow-hidden"
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

    <div data-slot="card-content" class="overflow-x-auto -mx-6 -mb-6">
      <div class="inline-block min-w-full align-middle">
        <table class="min-w-full divide-y divide-border">
          <thead class="bg-muted/40">
            <tr>
              <th
                v-for="(header, i) in headers"
                :key="i"
                scope="col"
                class="px-6 py-3 text-left text-xs font-semibold text-muted-foreground uppercase tracking-wider"
              >
                {{ header }}
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border bg-transparent">
            <tr
              v-for="(row, rowIndex) in rows"
              :key="rowIndex"
              class="hover:bg-accent/50 transition-colors"
            >
              <td
                v-for="(val, colIndex) in getRowValues(row)"
                :key="colIndex"
                class="px-6 py-4 text-sm text-foreground whitespace-nowrap"
              >
                {{ val }}
              </td>
            </tr>
            <tr v-if="rows.length === 0">
              <td
                :colspan="headers.length || 1"
                class="px-6 py-10 text-center text-sm text-muted-foreground"
              >
                No records found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kinetix-table-card {
  width: 100%;
}
</style>
