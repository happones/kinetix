<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { UploadCloud, Loader2, ArrowRight } from "@lucide/vue";
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import type { KinetixImportPreview } from "@/types";
import KinetixCheckbox from "./KinetixCheckbox.vue";
import KinetixSelect from "./KinetixSelect.vue";

const props = withDefaults(
  defineProps<{
    importer: string;
    routePrefix?: string | null;
  }>(),
  {
    routePrefix: null,
  },
);

const { t } = useI18n();
const page = usePage();

const prefix = computed(
  () =>
    (page.props.kinetix_config as any)?.route_prefix ??
    props.routePrefix ??
    "_kinetix",
);

const file = ref<File | null>(null);
const preview = ref<KinetixImportPreview | null>(null);
const mapping = reactive<Record<string, number | null>>({});
const loading = ref(false);
const starting = ref(false);
const errorMessage = ref<string | null>(null);

const options = reactive({
  delimiter: ",",
  enclosure: '"',
  skipLines: 0,
  hasHeader: true,
});

const delimiterOptions = [
  { value: ",", label: "Comma ( , )" },
  { value: ";", label: "Semicolon ( ; )" },
  { value: "\t", label: "Tab" },
  { value: "|", label: "Pipe ( | )" },
];

const enclosureOptions = [
  { value: '"', label: 'Double quote ( " )' },
  { value: "'", label: "Single quote ( ' )" },
  { value: "", label: "None" },
];

const delimiterOptionsMap = computed(() => {
  const map: Record<string, string> = {};
  delimiterOptions.forEach((opt) => {
    map[opt.value] = opt.label;
  });

  return map;
});

const enclosureOptionsMap = computed(() => {
  const map: Record<string, string> = {};
  enclosureOptions.forEach((opt) => {
    map[opt.value] = opt.label;
  });

  return map;
});

const xsrfToken = (): string => {
  const match = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="));

  return match ? decodeURIComponent(match.split("=")[1]) : "";
};

const applyPreview = (data: KinetixImportPreview) => {
  preview.value = data;
  options.delimiter = data.options.delimiter;
  options.enclosure = data.options.enclosure;
  options.skipLines = data.options.skipLines;
  options.hasHeader = data.options.hasHeader;

  // Reset mapping to the server-computed, collision-free suggestions.
  Object.keys(mapping).forEach((key) => delete mapping[key]);

  for (const column of data.columns) {
    mapping[column.name] = data.autoMapping[column.name] ?? null;
  }
};

const onFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement;
  file.value = target.files?.[0] ?? null;
};

const upload = async () => {
  if (!file.value) {
    return;
  }

  loading.value = true;
  errorMessage.value = null;

  const body = new FormData();
  body.append("file", file.value);
  body.append("importer", props.importer);
  body.append("delimiter", options.delimiter);
  body.append("enclosure", options.enclosure);
  body.append("skipLines", String(options.skipLines));
  body.append("hasHeader", options.hasHeader ? "1" : "0");

  try {
    const response = await fetch(`/${prefix.value}/imports/upload`, {
      method: "POST",
      headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
      body,
    });

    if (!response.ok) {
      throw await response.json().catch(() => ({}));
    }

    applyPreview(await response.json());
  } catch (error: any) {
    errorMessage.value = error?.message ?? t("kinetix.import_failed");
  } finally {
    loading.value = false;
  }
};

const applyOptions = async () => {
  if (!preview.value) {
    return;
  }

  loading.value = true;
  errorMessage.value = null;

  try {
    const response = await fetch(`/${prefix.value}/imports/preview`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-XSRF-TOKEN": xsrfToken(),
      },
      body: JSON.stringify({
        importer: props.importer,
        fileToken: preview.value.fileToken,
        ...options,
      }),
    });

    if (!response.ok) {
      throw await response.json().catch(() => ({}));
    }

    applyPreview(await response.json());
  } catch (error: any) {
    errorMessage.value = error?.message ?? t("kinetix.import_failed");
  } finally {
    loading.value = false;
  }
};

// Indices already claimed by other target columns — used to prevent collisions.
const usedIndexes = (exceptColumn: string): Set<number> => {
  const used = new Set<number>();

  for (const [name, index] of Object.entries(mapping)) {
    if (name !== exceptColumn && index !== null && index !== undefined) {
      used.add(index);
    }
  }

  return used;
};

const setMapping = (column: string, value: string) => {
  mapping[column] = value === "" ? null : Number(value);
};

const getMappingOptions = (headers: string[]) => {
  const record: Record<string, string> = {
    "": t("kinetix.not_mapped"),
  };

  headers.forEach((header, index) => {
    record[String(index)] = header;
  });

  return record;
};

const getDisabledMappingKeys = (columnName: string) => {
  const used = usedIndexes(columnName);

  return Array.from(used).map(String);
};

// Reverse lookup: which target column label (if any) a header is mapped to.
const columnForHeader = (index: number): string | null => {
  if (!preview.value) {
    return null;
  }

  for (const column of preview.value.columns) {
    if (mapping[column.name] === index) {
      return column.label;
    }
  }

  return null;
};

const canStart = computed(() => {
  if (!preview.value) {
    return false;
  }

  return preview.value.columns
    .filter((column) => column.isRequired)
    .every(
      (column) =>
        mapping[column.name] !== null && mapping[column.name] !== undefined,
    );
});

const startImport = async () => {
  if (!preview.value || !canStart.value) {
    return;
  }

  starting.value = true;
  errorMessage.value = null;

  try {
    const response = await fetch(`/${prefix.value}/imports/start`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-XSRF-TOKEN": xsrfToken(),
      },
      body: JSON.stringify({
        importer: props.importer,
        fileToken: preview.value.fileToken,
        mapping,
        ...options,
      }),
    });

    if (!response.ok) {
      throw await response.json().catch(() => ({}));
    }

    toast.success(t("kinetix.import_started"));
    preview.value = null;
    file.value = null;
  } catch (error: any) {
    errorMessage.value = error?.message ?? t("kinetix.import_failed");
  } finally {
    starting.value = false;
  }
};
</script>

<template>
  <div class="space-y-5">
    <!-- File upload -->
    <div class="rounded-xl border border-dashed border-input p-6 text-center">
      <UploadCloud class="mx-auto h-8 w-8 text-muted-foreground" />
      <div class="mt-3 flex items-center justify-center gap-3">
        <input
          type="file"
          accept=".csv,.txt,.tsv,.xls,.xlsx"
          class="text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary-foreground"
          @change="onFileChange"
        />
        <button
          type="button"
          class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          :disabled="!file || loading"
          @click="upload"
        >
          <Loader2 v-if="loading" class="h-4 w-4 animate-spin" />
          {{ t("kinetix.upload") }}
        </button>
      </div>
    </div>

    <p
      v-if="errorMessage"
      class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
    >
      {{ errorMessage }}
    </p>

    <template v-if="preview">
      <!-- CSV options -->
      <div class="rounded-xl border border-border p-4">
        <h3 class="mb-3 text-sm font-semibold text-foreground">
          {{ t("kinetix.csv_options") }}
        </h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <div
            class="flex flex-col gap-1 text-xs font-medium text-muted-foreground"
          >
            {{ t("kinetix.delimiter") }}
            <KinetixSelect
              :value="options.delimiter"
              :options="delimiterOptionsMap"
              @update:value="options.delimiter = $event"
            />
          </div>
          <div
            class="flex flex-col gap-1 text-xs font-medium text-muted-foreground"
          >
            {{ t("kinetix.enclosure") }}
            <KinetixSelect
              :value="options.enclosure"
              :options="enclosureOptionsMap"
              @update:value="options.enclosure = $event"
            />
          </div>
          <label
            class="flex flex-col gap-1 text-xs font-medium text-muted-foreground"
          >
            {{ t("kinetix.omit_lines") }}
            <input
              v-model.number="options.skipLines"
              type="number"
              min="0"
              class="h-9 rounded-md border border-border bg-popover px-2 text-sm"
            />
          </label>
          <div class="flex items-end gap-3">
            <label class="flex items-center gap-2 text-sm text-foreground">
              <KinetixCheckbox
                :checked="options.hasHeader"
                @change="options.hasHeader = $event"
              />
              {{ t("kinetix.has_header") }}
            </label>
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between">
          <span class="text-xs text-muted-foreground">
            {{ t("kinetix.rows_detected", { count: preview.totalRows }) }}
          </span>
          <button
            type="button"
            class="inline-flex h-8 items-center rounded-md border border-border px-3 text-xs font-medium hover:bg-accent disabled:opacity-50"
            :disabled="loading"
            @click="applyOptions"
          >
            {{ t("kinetix.apply") }}
          </button>
        </div>
      </div>

      <!-- Column mapping -->
      <div class="rounded-xl border border-border p-4">
        <h3 class="mb-3 text-sm font-semibold text-foreground">
          {{ t("kinetix.column_mapping") }}
        </h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div
            v-for="column in preview.columns"
            :key="column.name"
            class="flex items-center gap-2"
          >
            <span class="w-1/2 truncate text-sm text-foreground">
              {{ column.label }}
              <span v-if="column.isRequired" class="text-rose-500">*</span>
            </span>
            <ArrowRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <div class="w-1/2">
              <KinetixSelect
                :value="mapping[column.name] ?? ''"
                :options="getMappingOptions(preview.headers)"
                :disabled-keys="getDisabledMappingKeys(column.name)"
                :class="
                  column.isRequired && mapping[column.name] === null
                    ? 'border-rose-300 dark:border-rose-800'
                    : ''
                "
                @update:value="setMapping(column.name, $event)"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Preview table -->
      <div class="overflow-x-auto rounded-xl border border-border">
        <table class="min-w-full text-sm">
          <thead class="bg-muted/40">
            <tr>
              <th
                v-for="(header, index) in preview.headers"
                :key="index"
                class="px-3 py-2 text-left font-semibold text-foreground whitespace-nowrap"
              >
                <div>{{ header }}</div>
                <div
                  v-if="columnForHeader(index)"
                  class="mt-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400"
                >
                  → {{ columnForHeader(index) }}
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, rowIndex) in preview.rows"
              :key="rowIndex"
              class="border-t border-border"
            >
              <td
                v-for="(header, colIndex) in preview.headers"
                :key="colIndex"
                class="px-3 py-2 text-muted-foreground whitespace-nowrap"
                :class="
                  columnForHeader(colIndex)
                    ? 'bg-emerald-50/40 dark:bg-emerald-950/10'
                    : ''
                "
              >
                {{ row[colIndex] }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Start -->
      <div class="flex justify-end">
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-md bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-600/90 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!canStart || starting"
          @click="startImport"
        >
          <Loader2 v-if="starting" class="h-4 w-4 animate-spin" />
          {{ starting ? t("kinetix.importing") : t("kinetix.start_import") }}
        </button>
      </div>
    </template>
  </div>
</template>
