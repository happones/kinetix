<script setup lang="ts">
import {
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from "@lucide/vue";
import { useI18n } from "vue-i18n";
import KinetixSelect from "../KinetixSelect.vue";

interface PaginationData {
  currentPage: number;
  lastPage: number;
  from: number | null;
  to: number | null;
  total: number;
  perPage: number;
}

defineProps<{
  pagination: PaginationData;
  paginationPageOptions: number[];
}>();

const emit = defineEmits<{
  (e: "change-page", page: number): void;
  (e: "change-per-page", perPage: number): void;
}>();

const { t } = useI18n();

const getPerPageOptions = (options?: number[]) => {
  const record: Record<string, string> = {};

  if (options) {
    options.forEach((opt) => {
      record[String(opt)] = String(opt);
    });
  }

  return record;
};
</script>

<template>
  <div
    class="flex flex-col sm:flex-row items-center justify-between gap-4 px-6 py-4 border-t border-border bg-muted/20"
  >
    <div class="text-xs text-muted-foreground font-medium">
      <span v-if="pagination.total > 0">
        {{
          t("kinetix.showing_records", {
            from: pagination.from,
            to: pagination.to,
            total: pagination.total,
          })
        }}
      </span>
      <span v-else>{{ t("kinetix.no_records") }}</span>
    </div>

    <div class="flex items-center gap-4">
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="inline-flex items-center justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 size-8 rounded-md text-muted-foreground"
          :disabled="pagination.currentPage === 1"
          @click="emit('change-page', 1)"
        >
          <ChevronsLeft class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 size-8 rounded-md text-muted-foreground"
          :disabled="pagination.currentPage === 1"
          @click="emit('change-page', pagination.currentPage - 1)"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>
        <span class="text-xs text-muted-foreground font-medium mx-2">
          {{
            t("kinetix.page_of", {
              current: pagination.currentPage,
              total: pagination.lastPage,
            })
          }}
        </span>
        <button
          type="button"
          class="inline-flex items-center justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 size-8 rounded-md text-muted-foreground"
          :disabled="pagination.currentPage === pagination.lastPage"
          @click="emit('change-page', pagination.currentPage + 1)"
        >
          <ChevronRight class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="inline-flex items-center justify-center whitespace-nowrap text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 size-8 rounded-md text-muted-foreground"
          :disabled="pagination.currentPage === pagination.lastPage"
          @click="emit('change-page', pagination.lastPage)"
        >
          <ChevronsRight class="h-4 w-4" />
        </button>
      </div>

      <!-- Page Size selector -->
      <div class="flex items-center gap-2 text-xs text-muted-foreground">
        <span>{{ t("kinetix.per_page") }}</span>
        <div class="w-16">
          <KinetixSelect
            :value="pagination.perPage"
            :options="getPerPageOptions(paginationPageOptions)"
            @update:value="emit('change-per-page', Number($event))"
          />
        </div>
      </div>
    </div>
  </div>
</template>
