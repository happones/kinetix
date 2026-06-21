<script setup lang="ts">
import {
  CheckCircle2,
  XCircle,
  Check,
  X,
  Copy,
  ExternalLink,
  Circle,
  Star,
  Mail,
  Phone,
  Calendar,
  User,
  Info,
} from "@lucide/vue";
import { reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { KinetixInfolistEntry } from "@/types";

defineProps<{
  schema: KinetixInfolistEntry[];
}>();

const { t } = useI18n();

// Active tab index per Tabs entry, keyed by its position in this schema list.
const activeTab = reactive<Record<number, number>>({});
const currentTab = (entryIndex: number) => activeTab[entryIndex] ?? 0;
const setActiveTab = (entryIndex: number, tabIndex: number) => {
  activeTab[entryIndex] = tabIndex;
};

// Static icon map keeps Lucide imports tree-shakeable and avoids dynamic resolution.
const standardIconMap: Record<string, any> = {
  check: Check,
  "check-circle": CheckCircle2,
  x: X,
  "x-circle": XCircle,
  circle: Circle,
  star: Star,
  mail: Mail,
  phone: Phone,
  calendar: Calendar,
  user: User,
  info: Info,
};

const resolveIcon = (name?: string | null) => {
  if (!name) {
    return null;
  }

  return standardIconMap[name.toLowerCase()] || Circle;
};

// Tailwind-safe color classes (no dynamic class names to survive JIT purging).
const getBadgeColorClass = (color?: string | null) => {
  if (color === "success") {
    return "text-emerald-700 bg-emerald-50 border border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/30 dark:border-emerald-800";
  }

  if (color === "danger") {
    return "text-rose-700 bg-rose-50 border border-rose-200 dark:text-rose-300 dark:bg-rose-950/30 dark:border-rose-800";
  }

  if (color === "warning") {
    return "text-amber-700 bg-amber-50 border border-amber-200 dark:text-amber-300 dark:bg-amber-950/30 dark:border-amber-800";
  }

  if (color === "info" || color === "primary") {
    return "text-sky-700 bg-sky-50 border border-sky-200 dark:text-sky-300 dark:bg-sky-950/30 dark:border-sky-800";
  }

  return "text-muted-foreground bg-muted border border-border";
};

const getTextColorClass = (color?: string | null) => {
  if (color === "success") {
    return "text-emerald-600 dark:text-emerald-400";
  }

  if (color === "danger") {
    return "text-rose-600 dark:text-rose-400";
  }

  if (color === "warning") {
    return "text-amber-600 dark:text-amber-400";
  }

  if (color === "info" || color === "primary") {
    return "text-sky-600 dark:text-sky-400";
  }

  return "text-foreground";
};

const getIconColorClass = (color?: string | null) => {
  if (color === "success") {
    return "text-emerald-500";
  }

  if (color === "danger") {
    return "text-rose-500";
  }

  if (color === "warning") {
    return "text-amber-500";
  }

  if (color === "info" || color === "primary") {
    return "text-sky-500";
  }

  return "text-muted-foreground";
};

const getColumnSpan = (span: KinetixInfolistEntry["columnSpan"]) => {
  if (span === "full") {
    return "1 / -1";
  }

  if (typeof span === "number") {
    return `span ${span} / span ${span}`;
  }

  return undefined;
};

const isEmpty = (value: unknown) =>
  value === null || value === undefined || value === "";

const copiedName = ref<string | null>(null);

const copyToClipboard = (entry: KinetixInfolistEntry) => {
  const value = entry.state;

  if (isEmpty(value)) {
    return;
  }

  navigator.clipboard?.writeText(String(value)).then(() => {
    copiedName.value = entry.name ?? null;
    setTimeout(() => {
      if (copiedName.value === (entry.name ?? null)) {
        copiedName.value = null;
      }
    }, 1500);
  });
};
</script>

<template>
  <template v-for="(entry, index) in schema" :key="entry.name ?? index">
    <!-- Grid layout -->
    <div
      v-if="entry.type === 'grid'"
      class="grid gap-4"
      :style="{
        gridTemplateColumns: `repeat(${entry.columns || 12}, minmax(0, 1fr))`,
        gridColumn: getColumnSpan(entry.columnSpan),
      }"
    >
      <KinetixInfolistEntries :schema="entry.schema || []" />
    </div>

    <!-- Section card layout -->
    <div
      v-else-if="entry.type === 'section'"
      class="rounded-xl border border-border bg-popover shadow-sm"
      :style="{ gridColumn: getColumnSpan(entry.columnSpan) }"
    >
      <div
        v-if="entry.heading || entry.description"
        class="p-6 pb-4 border-b border-border"
      >
        <div class="flex items-center gap-2">
          <component
            :is="resolveIcon(entry.icon)"
            v-if="entry.icon"
            class="h-4 w-4 text-muted-foreground"
          />
          <h3 class="font-semibold leading-none tracking-tight text-foreground">
            {{ entry.heading }}
          </h3>
        </div>
        <p
          v-if="entry.description"
          class="text-sm text-muted-foreground mt-1.5"
        >
          {{ entry.description }}
        </p>
      </div>
      <div class="p-6">
        <div
          class="grid gap-x-4 gap-y-5"
          :style="{
            gridTemplateColumns: `repeat(${entry.columns || 12}, minmax(0, 1fr))`,
          }"
        >
          <KinetixInfolistEntries :schema="entry.schema || []" />
        </div>
      </div>
    </div>

    <!-- Fieldset layout -->
    <fieldset
      v-else-if="entry.type === 'fieldset'"
      class="rounded-xl border border-border px-5 pb-5 pt-2"
      :style="{ gridColumn: getColumnSpan(entry.columnSpan) }"
    >
      <legend
        v-if="entry.heading"
        class="px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
      >
        {{ entry.heading }}
      </legend>
      <div
        class="mt-2 grid gap-x-4 gap-y-5"
        :style="{
          gridTemplateColumns: `repeat(${entry.columns || 12}, minmax(0, 1fr))`,
        }"
      >
        <KinetixInfolistEntries :schema="entry.schema || []" />
      </div>
    </fieldset>

    <!-- Tabs layout -->
    <div
      v-else-if="entry.type === 'tabs'"
      class="rounded-xl border border-border bg-popover shadow-sm"
      :style="{ gridColumn: getColumnSpan(entry.columnSpan) }"
    >
      <div
        role="tablist"
        class="flex flex-wrap gap-1 border-b border-border px-2 pt-2"
      >
        <button
          v-for="(tab, tabIndex) in entry.schema || []"
          :key="tabIndex"
          type="button"
          role="tab"
          :aria-selected="currentTab(index) === tabIndex"
          class="inline-flex items-center gap-1.5 rounded-t-md px-3 py-2 text-sm font-medium transition-colors"
          :class="
            currentTab(index) === tabIndex
              ? 'border-b-2 border-primary text-foreground'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="setActiveTab(index, tabIndex)"
        >
          <component
            :is="resolveIcon(tab.icon)"
            v-if="tab.icon"
            class="h-4 w-4"
          />
          {{ tab.heading }}
        </button>
      </div>
      <div class="p-6">
        <template v-for="(tab, tabIndex) in entry.schema || []" :key="tabIndex">
          <div
            v-show="currentTab(index) === tabIndex"
            class="grid gap-x-4 gap-y-5"
            :style="{
              gridTemplateColumns: `repeat(${tab.columns || 12}, minmax(0, 1fr))`,
            }"
          >
            <KinetixInfolistEntries :schema="tab.schema || []" />
          </div>
        </template>
      </div>
    </div>

    <!-- Entry wrapper -->
    <div
      v-else
      :style="{ gridColumn: getColumnSpan(entry.columnSpan) }"
      class="min-w-0"
      :class="
        entry.isInline
          ? 'flex items-center justify-between gap-4'
          : 'flex flex-col gap-1.5'
      "
    >
      <span
        v-if="entry.label"
        class="text-xs font-medium uppercase tracking-wide text-muted-foreground"
      >
        {{ entry.label }}
      </span>

      <!-- Empty placeholder -->
      <span v-if="isEmpty(entry.state)" class="text-sm text-muted-foreground">
        {{ entry.placeholder ?? "—" }}
      </span>

      <!-- Icon entry -->
      <component
        :is="resolveIcon(entry.icon)"
        v-else-if="entry.type === 'icon'"
        :class="getIconColorClass(entry.color)"
        :style="{
          width: `${entry.size || 24}px`,
          height: `${entry.size || 24}px`,
        }"
      />

      <!-- Image entry -->
      <img
        v-else-if="entry.type === 'image'"
        :src="String(entry.state)"
        :alt="entry.label ?? ''"
        class="object-cover border border-border"
        :class="entry.isCircular ? 'rounded-full' : 'rounded-lg'"
        :style="{
          width: `${entry.size || 96}px`,
          height: `${entry.size || 96}px`,
        }"
      />

      <!-- Color entry -->
      <div v-else-if="entry.type === 'color'" class="flex items-center gap-2">
        <span
          class="h-6 w-6 rounded-md border border-border shadow-sm"
          :style="{ backgroundColor: String(entry.state) }"
        />
        <span class="text-sm font-mono text-foreground">
          {{ entry.state }}
        </span>
        <button
          v-if="entry.isCopyable"
          type="button"
          class="text-muted-foreground hover:text-foreground transition-colors"
          :title="t('kinetix.copy')"
          @click="copyToClipboard(entry)"
        >
          <Check
            v-if="copiedName === entry.name"
            class="h-3.5 w-3.5 text-emerald-500"
          />
          <Copy v-else class="h-3.5 w-3.5" />
        </button>
      </div>

      <!-- Badge text entry -->
      <span
        v-else-if="entry.type === 'text' && entry.isBadge"
        class="inline-flex w-fit items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium"
        :class="getBadgeColorClass(entry.color)"
      >
        <component
          :is="resolveIcon(entry.icon)"
          v-if="entry.icon"
          class="h-3 w-3"
        />
        {{ entry.state }}
      </span>

      <!-- Linked / plain text entry -->
      <a
        v-else-if="entry.type === 'text' && entry.url"
        :href="entry.url"
        :target="entry.openUrlInNewTab ? '_blank' : undefined"
        :rel="entry.openUrlInNewTab ? 'noopener noreferrer' : undefined"
        class="inline-flex w-fit items-center gap-1 text-sm font-medium text-sky-600 dark:text-sky-400 hover:underline"
      >
        <component
          :is="resolveIcon(entry.icon)"
          v-if="entry.icon"
          class="h-3.5 w-3.5"
        />
        {{ entry.state }}
        <ExternalLink v-if="entry.openUrlInNewTab" class="h-3 w-3" />
      </a>

      <!-- Plain text entry -->
      <div v-else class="flex items-center gap-2">
        <component
          :is="resolveIcon(entry.icon)"
          v-if="entry.icon"
          class="h-3.5 w-3.5"
          :class="getIconColorClass(entry.color)"
        />
        <span class="text-sm" :class="getTextColorClass(entry.color)">
          {{ entry.state }}
        </span>
        <button
          v-if="entry.isCopyable"
          type="button"
          class="text-muted-foreground hover:text-foreground transition-colors"
          :title="t('kinetix.copy')"
          @click="copyToClipboard(entry)"
        >
          <Check
            v-if="copiedName === entry.name"
            class="h-3.5 w-3.5 text-emerald-500"
          />
          <Copy v-else class="h-3.5 w-3.5" />
        </button>
      </div>
    </div>
  </template>
</template>
