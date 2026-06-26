<script setup lang="ts">
import {
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import {
  listHotkeys,
  useKinetixHotkeys,
} from "@/composables/useKinetixHotkeys";

/**
 * Press `?` to open a cheat-sheet of the active keyboard shortcuts (the labelled
 * ones). Mount once in your authenticated layout. Renders nothing until opened.
 */
const { t } = useI18n();
const { register } = useKinetixHotkeys();

const open = ref(false);

onMounted(() => {
  register({
    keys: "?",
    label: t("kinetix.shortcuts_title"),
    handler: () => (open.value = true),
  });
});

const shortcuts = computed(() => listHotkeys());

/** Render a combo/sequence as separate key chips: "g i" → [g][i], "mod+k" → [mod][k]. */
function chips(keys: string): string[] {
  return keys.split(/\s+/).flatMap((step) => step.split("+"));
}
</script>

<template>
  <DialogRoot :open="open" @update:open="open = $event">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-black/50" />
      <DialogContent
        class="fixed left-1/2 top-1/4 z-50 w-full max-w-md -translate-x-1/2 rounded-xl border border-border bg-popover p-4 text-popover-foreground shadow-lg"
      >
        <DialogTitle class="text-lg font-semibold text-foreground">
          {{ t("kinetix.shortcuts_title") }}
        </DialogTitle>
        <DialogDescription class="sr-only">
          {{ t("kinetix.shortcuts_title") }}
        </DialogDescription>

        <ul class="mt-3 space-y-2">
          <li
            v-for="shortcut in shortcuts"
            :key="shortcut.id"
            class="flex items-center justify-between gap-4 text-sm"
          >
            <span class="text-foreground">{{ shortcut.label }}</span>
            <span class="flex items-center gap-1">
              <kbd
                v-for="(chip, i) in chips(shortcut.keys)"
                :key="i"
                class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
              >
                {{ chip }}
              </kbd>
            </span>
          </li>
        </ul>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
