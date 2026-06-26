<script setup lang="ts">
import { ArrowUp, ArrowDown, ArrowUpDown } from "@lucide/vue";
import KinetixCheckbox from "../KinetixCheckbox.vue";

interface Column {
  name: string;
  label: string;
  isSortable: boolean;
  alignment?: "left" | "center" | "right" | null;
}

const props = defineProps<{
  columnsToRender: Column[];
  sort: string | null;
  direction: "asc" | "desc" | null;
  hasBulkActions: boolean;
  hasRecordActions: boolean;
  allOnPageSelected: boolean;
  stickyActions?: boolean;
}>();

const emit = defineEmits<{
  (e: "toggle-all-on-page", checked: boolean): void;
  (e: "toggle-sort", column: string): void;
}>();

const getSortIcon = (name: string) => {
  if (props.sort !== name) {
    return ArrowUpDown;
  }

  return props.direction === "asc" ? ArrowUp : ArrowDown;
};
</script>

<template>
  <thead class="bg-muted/40">
    <tr>
      <th v-if="hasBulkActions" scope="col" class="w-10 px-4 py-3">
        <KinetixCheckbox
          :checked="allOnPageSelected"
          @change="emit('toggle-all-on-page', $event)"
        />
      </th>
      <th
        v-for="col in columnsToRender"
        :key="col.name"
        scope="col"
        class="px-6 py-3 text-xs font-semibold text-muted-foreground uppercase tracking-wider"
        :class="[
          col.alignment === 'center' ? 'text-center' : '',
          col.alignment === 'right' ? 'text-right' : 'text-left',
        ]"
      >
        <button
          v-if="col.isSortable"
          type="button"
          class="inline-flex items-center gap-1 hover:text-foreground transition-colors outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px] rounded-md"
          @click="emit('toggle-sort', col.name)"
        >
          {{ col.label }}
          <component :is="getSortIcon(col.name)" class="h-3.5 w-3.5" />
        </button>
        <span v-else>{{ col.label }}</span>
      </th>
      <th
        v-if="hasRecordActions"
        scope="col"
        class="relative px-6 py-3"
        :class="
          stickyActions
            ? 'sticky right-0 z-20 bg-muted border-l border-border'
            : ''
        "
      >
        <span class="sr-only">Actions</span>
      </th>
    </tr>
  </thead>
</template>
