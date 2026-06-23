<script setup lang="ts">
import { CheckCircle2, XCircle, Edit3, Trash2, Eye, Plus } from "@lucide/vue";
import KinetixCheckbox from "../KinetixCheckbox.vue";
import KinetixSelect from "../KinetixSelect.vue";
import {
  statusBadgeClass as getBadgeColorClass,
  statusTextClass,
} from "@/composables/useStatusColor";

const getIconColorClass = (color?: string | null) =>
  statusTextClass(color, "text-muted-foreground");

interface Column {
  name: string;
  label: string;
  type: string;
  isBadge?: boolean;
  alignment?: "left" | "center" | "right" | null;
  isCircular?: boolean;
  isPreviewable?: boolean;
  size?: number | null;
  isCopyable?: boolean;
  options?: Record<string, string> | null;
  inputType?: string | null;
  placeholder?: string | null;
}

interface RecordDescription {
  text: string | null;
  position: "above" | "below";
}

interface TableRecord {
  id: string | number;
  values: Record<string, any>;
  descriptions: Record<string, RecordDescription | null>;
  icons: Record<string, string | null>;
  iconColors: Record<string, string | null>;
  badgeColors: Record<string, string | null>;
}

const props = defineProps<{
  col: Column;
  record: TableRecord;
  rowIndex: number;
}>();

const openImagePreview = (): void => {
  const url = props.record.values[props.col.name];

  if (!url) {
    return;
  }

  window.dispatchEvent(
    new CustomEvent("kinetix:preview", {
      detail: { url, type: "image", label: props.col.label },
    }),
  );
};

const emit = defineEmits<{
  (
    e: "update-cell",
    recordId: string | number,
    colName: string,
    value: any,
  ): void;
  (e: "copy-to-clipboard", value: string): void;
}>();

// Standard icon mappings
const standardIconMap: Record<string, any> = {
  edit: Edit3,
  delete: Trash2,
  view: Eye,
  create: Plus,
  plus: Plus,
  check: CheckCircle2,
  "check-circle": CheckCircle2,
  x: XCircle,
  "x-circle": XCircle,
};

const resolveIcon = (name?: string) => {
  if (!name) {
    return null;
  }

  return standardIconMap[name.toLowerCase()] || null;
};

// Colors mapping
</script>

<template>
  <!-- Text Badge Mode -->
  <span
    v-if="col.type === 'text' && col.isBadge"
    class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full"
    :class="getBadgeColorClass(record.badgeColors[col.name])"
  >
    {{ record.values[col.name] }}
  </span>

  <!-- Text Normal Mode -->
  <div v-else-if="col.type === 'text' && !col.isBadge" class="flex flex-col">
    <span
      v-if="
        record.descriptions[col.name] &&
        record.descriptions[col.name]?.position === 'above'
      "
      class="text-[11px] text-muted-foreground mb-0.5"
    >
      {{ record.descriptions[col.name]?.text }}
    </span>
    <span>{{ record.values[col.name] }}</span>
    <span
      v-if="
        record.descriptions[col.name] &&
        record.descriptions[col.name]?.position === 'below'
      "
      class="text-[11px] text-muted-foreground mt-0.5"
    >
      {{ record.descriptions[col.name]?.text }}
    </span>
  </div>

  <!-- Icon Mode -->
  <div
    v-else-if="col.type === 'icon' && record.icons[col.name]"
    class="inline-flex items-center justify-center"
  >
    <component
      :is="resolveIcon(record.icons[col.name] || '')"
      class="h-5 w-5"
      :class="getIconColorClass(record.iconColors[col.name])"
    />
  </div>

  <!-- Image Mode -->
  <div
    v-else-if="col.type === 'image' && record.values[col.name]"
    class="inline-flex items-center"
  >
    <img
      :src="record.values[col.name]"
      class="object-cover"
      :class="[
        col.isCircular ? 'rounded-full' : 'rounded-lg border border-border',
        col.isPreviewable
          ? 'cursor-zoom-in transition-shadow hover:ring-2 hover:ring-ring'
          : '',
      ]"
      :style="{
        width: (col.size || 40) + 'px',
        height: (col.size || 40) + 'px',
      }"
      @click.stop="col.isPreviewable ? openImagePreview() : undefined"
    />
  </div>

  <!-- Color Mode -->
  <div
    v-else-if="col.type === 'color' && record.values[col.name]"
    class="inline-flex items-center gap-2"
  >
    <div
      class="w-5 h-5 rounded-md border border-border shadow-sm shrink-0 cursor-pointer"
      :style="{ backgroundColor: record.values[col.name] }"
      @click="
        col.isCopyable && emit('copy-to-clipboard', record.values[col.name])
      "
      :title="col.isCopyable ? 'Click to copy color code' : undefined"
    />
    <span class="text-xs text-muted-foreground font-mono">{{
      record.values[col.name]
    }}</span>
  </div>

  <!-- Editable: Select Mode -->
  <div
    v-else-if="col.type === 'select-input'"
    class="inline-flex items-center min-w-[120px]"
  >
    <KinetixSelect
      :value="record.values[col.name]"
      :options="col.options"
      @update:value="emit('update-cell', record.id, col.name, $event)"
    />
  </div>

  <!-- Editable: Toggle Mode -->
  <div v-else-if="col.type === 'toggle-input'" class="inline-flex items-center">
    <button
      type="button"
      class="relative inline-flex h-[1.15rem] w-8 shrink-0 cursor-pointer items-center rounded-full border border-transparent shadow-xs transition-all outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
      :class="record.values[col.name] ? 'bg-primary' : 'bg-input dark:bg-input/80'"
      @click="
        emit('update-cell', record.id, col.name, !record.values[col.name])
      "
    >
      <span
        class="pointer-events-none block size-4 rounded-full bg-background ring-0 transition-transform"
        :class="
          record.values[col.name]
            ? 'translate-x-[calc(100%-2px)] dark:bg-primary-foreground'
            : 'translate-x-0 dark:bg-foreground'
        "
      />
    </button>
  </div>

  <!-- Editable: Text Input Mode -->
  <div v-else-if="col.type === 'text-input'" class="inline-flex items-center">
    <input
      :type="col.inputType || 'text'"
      :value="record.values[col.name]"
      :placeholder="col.placeholder ?? ''"
      class="text-xs rounded border border-border bg-background px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-ring w-32 text-foreground"
      @change="
        emit(
          'update-cell',
          record.id,
          col.name,
          ($event.target as HTMLInputElement).value,
        )
      "
    />
  </div>

  <!-- Editable: Checkbox Mode -->
  <div
    v-else-if="col.type === 'checkbox-input'"
    class="inline-flex items-center"
  >
    <KinetixCheckbox
      :checked="!!record.values[col.name]"
      @change="emit('update-cell', record.id, col.name, $event)"
    />
  </div>
</template>
