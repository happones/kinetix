<script setup lang="ts">
import { computed } from "vue";
import { Search } from "@lucide/vue";
import { useI18n } from "vue-i18n";
import { isMac } from "@/composables/useKinetixHotkeys";

/**
 * Header trigger for the Spotlight command palette — pair it with
 * <KinetixSpotlight> mounted once in your layout. On click it opens the palette
 * (the ⌘K / Ctrl+K shortcut still works independently). On small screens it
 * collapses to an icon button (notification-trigger style); from `sm` up it
 * shows a search-box style hint with the keyboard shortcut.
 */
const { t } = useI18n();
const shortcut = computed(() => (isMac() ? "⌘K" : "Ctrl K"));

function open(): void {
  window.dispatchEvent(new CustomEvent("kinetix:spotlight"));
}
</script>

<template>
  <button
    type="button"
    :aria-label="t('kinetix.spotlight_placeholder')"
    :title="`${t('kinetix.spotlight_placeholder')} (${shortcut})`"
    class="flex items-center gap-2 rounded-md text-muted-foreground transition-colors hover:bg-accent focus:outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px] h-9 w-9 justify-center sm:h-9 sm:w-auto sm:min-w-[12rem] sm:justify-start sm:border sm:border-input sm:bg-background sm:px-3 sm:hover:bg-accent/50"
    @click="open"
  >
    <Search class="h-4 w-4 shrink-0" />
    <span class="hidden flex-1 text-left text-sm sm:inline">
      {{ t("kinetix.spotlight_placeholder") }}
    </span>
    <kbd
      class="hidden items-center gap-0.5 rounded border border-border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground sm:inline-flex"
    >
      {{ shortcut }}
    </kbd>
  </button>
</template>
