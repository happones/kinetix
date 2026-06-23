<script setup lang="ts">
import { Download, X, ZoomIn, ZoomOut } from "@lucide/vue";
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { cn } from "./primitives/cn";
import { buttonVariants } from "@/composables/useShadcnVariants";

const { t } = useI18n();

/**
 * Global file-preview lightbox. Mount once in your layout:
 *   <KinetixFilePreview />
 *
 * Opens on the `kinetix:preview` window event, dispatched by image columns
 * (`ImageColumn::preview()`) and `PreviewAction`. Shows images (click to zoom)
 * and PDFs in a shadcn dialog, with a download button.
 */

interface PreviewDetail {
  url: string;
  type?: string | null; // 'auto' | 'image' | 'pdf'
  downloadUrl?: string | null;
  label?: string | null;
  downloadLabel?: string | null;
}

const open = ref(false);
const zoomed = ref(false);
const detail = ref<PreviewDetail | null>(null);

const IMAGE_EXT = /\.(png|jpe?g|gif|webp|svg|avif|bmp|ico)(\?|#|$)/i;
const PDF_EXT = /\.pdf(\?|#|$)/i;

const resolvedType = computed<"image" | "pdf" | "other">(() => {
  const d = detail.value;

  if (!d) {
    return "other";
  }

  if (d.type === "image" || d.type === "pdf") {
    return d.type;
  }

  if (IMAGE_EXT.test(d.url)) {
    return "image";
  }

  if (PDF_EXT.test(d.url)) {
    return "pdf";
  }

  return "other";
});

const downloadHref = computed(
  () => detail.value?.downloadUrl ?? detail.value?.url ?? "",
);

function onPreview(event: Event): void {
  const next = (event as CustomEvent<PreviewDetail>).detail;

  if (!next?.url) {
    return;
  }

  detail.value = next;
  zoomed.value = false;
  open.value = true;
}

onMounted(() => window.addEventListener("kinetix:preview", onPreview));
onBeforeUnmount(() => window.removeEventListener("kinetix:preview", onPreview));
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay
        class="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
      />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 flex max-h-[90vh] w-[92vw] max-w-4xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-lg outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
      >
        <div
          class="flex items-center justify-between gap-2 border-b border-border p-3"
        >
          <DialogTitle class="truncate px-1 text-sm font-medium">
            {{ detail?.label ?? "" }}
          </DialogTitle>
          <div class="flex items-center gap-1">
            <button
              v-if="resolvedType === 'image'"
              type="button"
              :class="cn(buttonVariants({ variant: 'ghost', size: 'icon-sm' }))"
              @click="zoomed = !zoomed"
            >
              <ZoomOut v-if="zoomed" class="h-4 w-4" />
              <ZoomIn v-else class="h-4 w-4" />
            </button>
            <a
              :href="downloadHref"
              :download="resolvedType !== 'pdf' ? '' : undefined"
              target="_blank"
              rel="noopener"
              :class="cn(buttonVariants({ variant: 'ghost', size: 'icon-sm' }))"
            >
              <Download class="h-4 w-4" />
            </a>
            <DialogClose
              :class="cn(buttonVariants({ variant: 'ghost', size: 'icon-sm' }))"
            >
              <X class="h-4 w-4" />
            </DialogClose>
          </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto bg-muted/30">
          <div
            v-if="resolvedType === 'image'"
            class="flex h-full min-h-[50vh] items-center justify-center p-4"
            :class="zoomed ? 'cursor-zoom-out' : 'cursor-zoom-in'"
            @click="zoomed = !zoomed"
          >
            <img
              :src="detail?.url"
              :alt="detail?.label ?? ''"
              class="origin-center transition-transform duration-200"
              :class="
                zoomed
                  ? 'max-w-none scale-150'
                  : 'max-h-[78vh] max-w-full object-contain'
              "
            />
          </div>

          <object
            v-else-if="resolvedType === 'pdf'"
            :data="detail?.url"
            type="application/pdf"
            class="h-[78vh] w-full"
          >
            <iframe
              :src="detail?.url"
              class="h-[78vh] w-full border-0"
              title="PDF preview"
            ></iframe>
          </object>

          <div
            v-else
            class="flex min-h-[40vh] flex-col items-center justify-center gap-3 p-8 text-center text-sm text-muted-foreground"
          >
            <span>{{ t("kinetix.preview_unavailable") }}</span>
            <a
              :href="downloadHref"
              target="_blank"
              rel="noopener"
              :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }))"
            >
              <Download class="mr-2 h-4 w-4" />
              {{ detail?.downloadLabel ?? t("kinetix.download") }}
            </a>
          </div>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
