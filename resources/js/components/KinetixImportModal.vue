<script setup lang="ts">
import { X } from "@lucide/vue";
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import KinetixImporter from "./KinetixImporter.vue";
import { cn } from "./primitives/cn";
import { buttonVariants } from "@/composables/useShadcnVariants";

/**
 * Global import dialog. Mount once in your layout:
 *   <KinetixImportModal />
 *
 * Opens on the `kinetix:open-importer` window event (dispatched by
 * `ImportAction::make()->importer(...)`), showing `KinetixImporter` for the
 * importer carried in the event detail.
 */

const { t } = useI18n();
const open = ref(false);
const token = ref<string | null>(null);

function onOpen(event: Event): void {
  const detail = (event as CustomEvent<{ importer?: string }>).detail;

  if (!detail?.importer) {
    return;
  }

  token.value = detail.importer;
  open.value = true;
}

onMounted(() => window.addEventListener("kinetix:open-importer", onOpen));
onBeforeUnmount(() => window.removeEventListener("kinetix:open-importer", onOpen));
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay
        class="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
      />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[92vw] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-auto rounded-xl border border-border bg-card p-6 text-card-foreground shadow-lg outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
      >
        <div class="mb-4 flex items-center justify-between">
          <DialogTitle class="text-lg font-semibold leading-none tracking-tight">
            {{ t("kinetix.import") }}
          </DialogTitle>
          <DialogClose :class="cn(buttonVariants({ variant: 'ghost', size: 'icon-sm' }))">
            <X class="h-4 w-4" />
          </DialogClose>
        </div>

        <KinetixImporter v-if="token" :importer="token" />
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
