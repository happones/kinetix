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
  MoreVertical,
} from "@lucide/vue";
import { onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useActionConfirmation } from "@/composables/useKinetixActions";
import type { KinetixAction } from "@/types";
import KinetixConfirmModal from "./KinetixConfirmModal.vue";

defineProps<{
  group: KinetixAction;
}>();

const { t } = useI18n();
const { pendingAction, isConfirmOpen, requestAction, confirm, cancel } =
  useActionConfirmation();

const isOpen = ref(false);
const root = ref<HTMLElement | null>(null);

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
  "ellipsis-vertical": MoreVertical,
  "more-vertical": MoreVertical,
};

const resolveIcon = (name?: string | null) => {
  if (!name) {
    return MoreVertical;
  }

  return standardIconMap[name.toLowerCase()] || Circle;
};

const getItemColorClass = (color?: string | null) => {
  if (color === "danger") {
    return "text-rose-600 dark:text-rose-400";
  }

  if (color === "success") {
    return "text-emerald-600 dark:text-emerald-400";
  }

  if (color === "warning") {
    return "text-amber-600 dark:text-amber-400";
  }

  return "text-foreground";
};

const onItemClick = (action: KinetixAction) => {
  isOpen.value = false;
  requestAction(action);
};

// Close on outside click / Escape. Listeners are bound only while the menu is
// open and always torn down on unmount — no lingering document handlers.
const onDocumentClick = (event: MouseEvent) => {
  if (root.value && !root.value.contains(event.target as Node)) {
    isOpen.value = false;
  }
};

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === "Escape") {
    isOpen.value = false;
  }
};

watch(isOpen, (open) => {
  if (typeof document === "undefined") {
    return;
  }

  if (open) {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("keydown", onKeydown);

    return;
  }

  document.removeEventListener("click", onDocumentClick);
  document.removeEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
  if (typeof document !== "undefined") {
    document.removeEventListener("click", onDocumentClick);
    document.removeEventListener("keydown", onKeydown);
  }
});
</script>

<template>
  <div ref="root" class="relative inline-block">
    <button
      type="button"
      class="inline-flex items-center justify-center gap-1.5 rounded-md border border-border bg-popover px-2.5 h-9 text-sm font-medium text-foreground hover:bg-accent transition-colors"
      :aria-label="group.label || t('kinetix.more_actions')"
      :aria-expanded="isOpen"
      @click="isOpen = !isOpen"
    >
      <component :is="resolveIcon(group.icon)" class="h-4 w-4" />
      <span v-if="group.label">{{ group.label }}</span>
    </button>

    <Transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 scale-95"
      leave-active-class="transition duration-75 ease-in"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="isOpen"
        class="absolute right-0 z-30 mt-1 min-w-[11rem] origin-top-right rounded-lg border border-border bg-popover p-1 shadow-lg"
      >
        <button
          v-for="(action, i) in group.actions || []"
          :key="action.name ?? i"
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-accent transition-colors"
          :class="getItemColorClass(action.color)"
          @click="onItemClick(action)"
        >
          <component
            :is="resolveIcon(action.icon)"
            v-if="action.icon"
            class="h-4 w-4"
          />
          {{ action.label }}
        </button>
      </div>
    </Transition>

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
