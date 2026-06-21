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
import {
  DropdownMenuRoot,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
} from "reka-ui";
import { ref } from "vue";
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
    return "text-rose-600 dark:text-rose-400 focus:text-rose-600 dark:focus:text-rose-400";
  }

  if (color === "success") {
    return "text-emerald-600 dark:text-emerald-400 focus:text-emerald-600 dark:focus:text-emerald-400";
  }

  if (color === "warning") {
    return "text-amber-600 dark:text-amber-400 focus:text-amber-600 dark:focus:text-amber-400";
  }

  return "text-foreground";
};

const onItemClick = (action: KinetixAction) => {
  requestAction(action);
};
</script>

<template>
  <div class="inline-block">
    <DropdownMenuRoot v-model:open="isOpen">
      <DropdownMenuTrigger
        type="button"
        class="inline-flex items-center justify-center gap-1.5 rounded-md border border-border bg-popover px-2.5 h-9 text-sm font-medium text-foreground hover:bg-accent transition-colors focus:outline-none focus:ring-1 focus:ring-ring"
        :aria-label="group.label || t('kinetix.more_actions')"
      >
        <component :is="resolveIcon(group.icon)" class="h-4 w-4" />
        <span v-if="group.label">{{ group.label }}</span>
      </DropdownMenuTrigger>

      <DropdownMenuPortal>
        <DropdownMenuContent
          align="end"
          :side-offset="4"
          class="z-50 min-w-[11rem] rounded-lg border border-border bg-popover p-1 shadow-lg outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2"
        >
          <DropdownMenuItem
            v-for="(action, i) in group.actions || []"
            :key="action.name ?? i"
            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-accent focus:bg-accent focus:text-accent-foreground outline-none transition-colors cursor-default select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
            :class="getItemColorClass(action.color)"
            @select="onItemClick(action)"
          >
            <component
              :is="resolveIcon(action.icon)"
              v-if="action.icon"
              class="h-4 w-4"
            />
            {{ action.label }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenuPortal>
    </DropdownMenuRoot>

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
