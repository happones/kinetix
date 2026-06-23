<script setup lang="ts">
import { computed } from "vue";
import type { KinetixWidget } from "@/types";
import Card from "./primitives/Card.vue";
import CardHeader from "./primitives/CardHeader.vue";
import CardTitle from "./primitives/CardTitle.vue";
import CardDescription from "./primitives/CardDescription.vue";
import CardContent from "./primitives/CardContent.vue";

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
  <Card
    class="kinetix-table-card overflow-hidden transition-all duration-300 hover:shadow-md"
  >
    <CardHeader v-if="widget.title || widget.description">
      <CardTitle v-if="widget.title" class="text-base">{{
        widget.title
      }}</CardTitle>
      <CardDescription v-if="widget.description" class="text-xs">
        {{ widget.description }}
      </CardDescription>
    </CardHeader>

    <CardContent class="overflow-x-auto -mx-6 -mb-6">
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
    </CardContent>
  </Card>
</template>

<style scoped>
.kinetix-table-card {
  width: 100%;
}
</style>
