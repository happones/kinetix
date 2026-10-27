<script setup lang="ts">
import {
    Download,
    ExternalLink,
    GalleryThumbnails,
    Maximize,
    Minimize,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { DialogClose } from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixLightboxToolbarButton } from '@/types/kinetix';
import { cn } from '../primitives/cn';

/**
 * KinetixLightbox's top bar: the position counter on the start side, the
 * configured buttons and the always-present close button on the end side.
 * The `actions` slot adds host buttons before the built-in ones.
 */
const props = defineProps<{
    buttons: KinetixLightboxToolbarButton[];
    /** "3 / 8"; empty for a single item. */
    counter: string;
    zoomable: boolean;
    canZoomIn: boolean;
    canZoomOut: boolean;
    fullscreen: boolean;
    fullscreenSupported: boolean;
    downloadHref: string | null;
    openHref: string | null;
    hasThumbnails: boolean;
    thumbnailsVisible: boolean;
}>();

const emit = defineEmits<{
    (e: 'zoom-in'): void;
    (e: 'zoom-out'): void;
    (e: 'toggle-fullscreen'): void;
    (e: 'toggle-thumbnails'): void;
}>();

const { t } = useI18n();

const button = cn(buttonVariants({ variant: 'ghost', size: 'icon' }));
const shows = (name: KinetixLightboxToolbarButton): boolean =>
    props.buttons.includes(name);

const showZoom = computed(() => shows('zoom') && props.zoomable);
</script>

<template>
    <div
        class="gap-2 px-2 sm:px-4 pb-2 flex shrink-0 items-center justify-between pt-[max(0.5rem,env(safe-area-inset-top))]"
    >
        <p
            class="px-2 text-sm min-w-0 truncate text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            {{ counter }}
        </p>

        <div class="gap-1 flex shrink-0 flex-wrap items-center justify-end">
            <slot name="actions" />

            <template v-if="showZoom">
                <button
                    type="button"
                    :class="button"
                    :disabled="!canZoomOut"
                    :aria-label="t('kinetix.lightbox_zoom_out')"
                    @click="emit('zoom-out')"
                >
                    <ZoomOut class="size-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    :class="button"
                    :disabled="!canZoomIn"
                    :aria-label="t('kinetix.lightbox_zoom_in')"
                    @click="emit('zoom-in')"
                >
                    <ZoomIn class="size-4" aria-hidden="true" />
                </button>
            </template>

            <button
                v-if="shows('thumbnails') && hasThumbnails"
                type="button"
                :class="button"
                :aria-label="t('kinetix.lightbox_thumbnails')"
                :aria-pressed="thumbnailsVisible"
                @click="emit('toggle-thumbnails')"
            >
                <GalleryThumbnails class="size-4" aria-hidden="true" />
            </button>

            <button
                v-if="shows('fullscreen') && fullscreenSupported"
                type="button"
                :class="button"
                :aria-label="
                    fullscreen
                        ? t('kinetix.lightbox_exit_fullscreen')
                        : t('kinetix.lightbox_fullscreen')
                "
                @click="emit('toggle-fullscreen')"
            >
                <Minimize v-if="fullscreen" class="size-4" aria-hidden="true" />
                <Maximize v-else class="size-4" aria-hidden="true" />
            </button>

            <a
                v-if="shows('open') && openHref"
                :href="openHref"
                target="_blank"
                rel="noopener noreferrer"
                :class="button"
                :aria-label="t('kinetix.lightbox_open_original')"
            >
                <ExternalLink class="size-4" aria-hidden="true" />
            </a>

            <a
                v-if="shows('download') && downloadHref"
                :href="downloadHref"
                download
                target="_blank"
                rel="noopener"
                :class="button"
                :aria-label="t('kinetix.download')"
            >
                <Download class="size-4" aria-hidden="true" />
            </a>

            <DialogClose :class="button" :aria-label="t('kinetix.close')">
                <X class="size-4" aria-hidden="true" />
            </DialogClose>
        </div>
    </div>
</template>
