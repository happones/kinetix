<script setup lang="ts">
import {
  Check,
  CheckCircle2,
  XCircle,
  Trash2,
  Edit3,
  Eye,
  Plus,
  Download,
  Upload,
  Pencil,
  Settings,
  Circle,
} from "@lucide/vue";
import { useActionConfirmation } from "@/composables/useKinetixActions";
import type { KinetixAction } from "@/types";
import KinetixActionDropdown from "./KinetixActionDropdown.vue";
import KinetixConfirmModal from "./KinetixConfirmModal.vue";

withDefaults(
  defineProps<{
    heading?: string | null;
    description?: string | null;
    actions?: KinetixAction[];
  }>(),
  {
    heading: null,
    description: null,
    actions: () => [],
  },
);

const { pendingAction, isConfirmOpen, requestAction, confirm, cancel } =
  useActionConfirmation();

const standardIconMap: Record<string, any> = {
  edit: Edit3,
  pencil: Pencil,
  delete: Trash2,
  trash: Trash2,
  view: Eye,
  eye: Eye,
  create: Plus,
  plus: Plus,
  check: Check,
  "check-circle": CheckCircle2,
  x: XCircle,
  "x-circle": XCircle,
  download: Download,
  upload: Upload,
  settings: Settings,
};

const resolveIcon = (name?: string | null) => {
  if (!name) {
    return null;
  }

  return standardIconMap[name.toLowerCase()] || Circle;
};

const getButtonClass = (action: KinetixAction) => {
  if (action.viewType === "link") {
    return "text-foreground hover:text-foreground underline-offset-4 hover:underline bg-transparent";
  }

  if (action.color === "danger") {
    return "bg-rose-600 text-white hover:bg-rose-600/90";
  }

  if (action.color === "success") {
    return "bg-emerald-600 text-white hover:bg-emerald-600/90";
  }

  if (action.color === "warning") {
    return "bg-amber-500 text-white hover:bg-amber-500/90";
  }

  if (action.color === "gray") {
    return "border border-border bg-popover text-foreground hover:bg-accent";
  }

  return "bg-primary text-primary-foreground hover:bg-primary/90";
};

const getSizeClass = (size?: string) => {
  if (size === "xs") {
    return "h-7 px-2.5 text-xs";
  }

  if (size === "md") {
    return "h-10 px-5 text-sm";
  }

  if (size === "lg") {
    return "h-11 px-6 text-base";
  }

  return "h-9 px-4 text-sm";
};
</script>

<template>
  <div
    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6"
  >
    <div v-if="heading || description" class="min-w-0">
      <h1
        v-if="heading"
        class="text-xl font-semibold tracking-tight text-foreground"
      >
        {{ heading }}
      </h1>
      <p
        v-if="description"
        class="mt-1 text-sm text-muted-foreground"
      >
        {{ description }}
      </p>
    </div>

    <div class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="before-actions" />

      <template v-for="(action, i) in actions" :key="action.name ?? i">
        <KinetixActionDropdown v-if="action.type === 'group'" :group="action" />

        <a
          v-else
          :href="
            action.viewType === 'link' && action.url ? action.url : undefined
          "
          role="button"
          class="inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 cursor-pointer"
          :class="[getButtonClass(action), getSizeClass(action.size)]"
          @click.prevent="requestAction(action)"
        >
          <component
            :is="resolveIcon(action.icon)"
            v-if="action.icon && action.iconPosition !== 'after'"
            class="h-4 w-4"
          />
          {{ action.label }}
          <component
            :is="resolveIcon(action.icon)"
            v-if="action.icon && action.iconPosition === 'after'"
            class="h-4 w-4"
          />
        </a>
      </template>

      <slot />
    </div>

    <KinetixConfirmModal
      v-model:open="isConfirmOpen"
      :heading="pendingAction?.modalHeading"
      :description="pendingAction?.modalDescription"
      :icon="pendingAction?.modalIcon"
      :color="pendingAction?.color"
      :submit-label="pendingAction?.modalSubmitActionLabel"
      :cancel-label="pendingAction?.modalCancelActionLabel"
      @confirm="confirm"
      @cancel="cancel"
    />
  </div>
</template>
