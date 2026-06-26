<script setup lang="ts">
import { Circle } from "@lucide/vue";
import { useActionConfirmation } from "@/composables/useKinetixActions";
import { useKinetixHotkeys } from "@/composables/useKinetixHotkeys";
import { resolveIcon as resolveKinetixIcon } from "@/composables/useKinetixIcons";
import {
  actionButtonSize,
  actionButtonVariant,
  buttonVariants,
} from "@/composables/useShadcnVariants";
import { cn } from "./primitives/cn";
import type { KinetixAction } from "@/types";
import KinetixActionDropdown from "./KinetixActionDropdown.vue";
import KinetixConfirmModal from "./KinetixConfirmModal.vue";

const props = withDefaults(
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

// Register keyboard shortcuts declared on header actions (auto-cleaned on unmount).
const { register } = useKinetixHotkeys();
for (const action of props.actions) {
  if (action.shortcut && action.type !== "group") {
    register({
      keys: action.shortcut,
      label: action.label,
      handler: () => requestAction(action),
    });
  }
}

// Unknown (but non-empty) names fall back to a neutral circle.
const resolveIcon = (name?: string | null) =>
  name ? (resolveKinetixIcon(name) ?? Circle) : null;

// shadcn-vue (new-york) button UI for page-level actions (create/edit/delete…).
const actionClass = (action: KinetixAction) =>
  cn(
    buttonVariants({
      variant:
        action.viewType === "link" ? "link" : actionButtonVariant(action.color),
      size: actionButtonSize(action.size),
    }),
    "cursor-pointer",
  );
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
      <p v-if="description" class="mt-1 text-sm text-muted-foreground">
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
          :class="actionClass(action)"
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
