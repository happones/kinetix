<script setup lang="ts">
import { Circle, MoreVertical } from "@lucide/vue";
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
import { resolveIcon as resolveKinetixIcon } from "@/composables/useKinetixIcons";
import { statusInteractiveTextClass } from "@/composables/useStatusColor";
import type { KinetixAction } from "@/types";
import KinetixConfirmModal from "./KinetixConfirmModal.vue";

defineProps<{
  group: KinetixAction;
}>();

const { t } = useI18n();
const { pendingAction, isConfirmOpen, requestAction, confirm, cancel } =
  useActionConfirmation();

const isOpen = ref(false);

// No name → the dropdown trigger's default (vertical ellipsis); unknown → Circle.
const resolveIcon = (name?: string | null) =>
  name ? (resolveKinetixIcon(name) ?? Circle) : MoreVertical;

const getItemColorClass = (color?: string | null) =>
  statusInteractiveTextClass(color);

const onItemClick = (action: KinetixAction) => {
  requestAction(action);
};
</script>

<template>
  <div class="inline-block">
    <DropdownMenuRoot v-model:open="isOpen">
      <DropdownMenuTrigger
        type="button"
        class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 h-9 px-3 has-[>svg]:px-2.5"
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
