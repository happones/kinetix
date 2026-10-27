<script setup lang="ts">
import {
    Download,
    ExternalLink,
    GalleryThumbnails,
    Maximize,
    Minimize,
    Pause,
    Play,
    RotateCw,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { DialogClose } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixLightboxToolbarButton } from '@/types/kinetix';
import { cn } from '../primitives/cn';

/**
 * KinetixLightbox's top bar: the position counter on the start side, the
 * configured buttons — in the order given — and the always-present close
 * button on the end side. A button that doesn't apply to the current item
 * (zoom on a PDF, slideshow with one item) is left out. The `actions` slot
 * adds host buttons before the built-in ones.
 */
defineProps<{
    buttons: KinetixLightboxToolbarButton[];
    /** "3 / 8"; empty for a single item. */
    counter: string;
    zoomable: boolean;
    canZoomIn: boolean;
    canZoomOut: boolean;
    rotatable: boolean;
    canPlay: boolean;
    playing: boolean;
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
    (e: 'rotate'): void;
    (e: 'toggle-slideshow'): void;
    (e: 'toggle-fullscreen'): void;
    (e: 'toggle-thumbnails'): void;
}>();

const { t } = useI18n();

const button = cn(buttonVariants({ variant: 'ghost', size: 'icon' }));
// Phones zoom with a pinch or a double-tap; dropping these keeps the bar on
// one row at 320px (the keyboard's +/− still work everywhere).
const zoomButton = cn(button, 'max-sm:hidden');
</script>

<template>
    <div
        class="gap-2 px-2 sm:px-4 pb-2 flex shrink-0 items-center justify-between pt-[max(0.5rem,env(safe-area-inset-top))]"
    >
        <p
            class="px-2 text-sm shrink-0 text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            {{ counter }}
        </p>

        <div class="gap-1 min-w-0 flex flex-wrap items-center justify-end">
            <slot name="actions" />

            <template v-for="name in buttons" :key="name">
                <template v-if="name === 'zoom' && zoomable">
                    <button
                        type="button"
                        :class="zoomButton"
                        :disabled="!canZoomOut"
                        :aria-label="t('kinetix.lightbox_zoom_out')"
                        @click="emit('zoom-out')"
                    >
                        <ZoomOut class="size-4" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        :class="zoomButton"
                        :disabled="!canZoomIn"
                        :aria-label="t('kinetix.lightbox_zoom_in')"
                        @click="emit('zoom-in')"
                    >
                        <ZoomIn class="size-4" aria-hidden="true" />
                    </button>
                </template>

                <button
                    v-else-if="name === 'rotate' && rotatable"
                    type="button"
                    :class="button"
                    :aria-label="t('kinetix.lightbox_rotate')"
                    @click="emit('rotate')"
                >
                    <RotateCw class="size-4" aria-hidden="true" />
                </button>

                <button
                    v-else-if="name === 'slideshow' && canPlay"
                    type="button"
                    :class="button"
                    :aria-label="
                        playing
                            ? t('kinetix.lightbox_slideshow_pause')
                            : t('kinetix.lightbox_slideshow_play')
                    "
                    @click="emit('toggle-slideshow')"
                >
                    <Pause v-if="playing" class="size-4" aria-hidden="true" />
                    <Play v-else class="size-4" aria-hidden="true" />
                </button>

                <button
                    v-else-if="name === 'thumbnails' && hasThumbnails"
                    type="button"
                    :class="button"
                    :aria-label="t('kinetix.lightbox_thumbnails')"
                    :aria-pressed="thumbnailsVisible"
                    @click="emit('toggle-thumbnails')"
                >
                    <GalleryThumbnails class="size-4" aria-hidden="true" />
                </button>

                <button
                    v-else-if="name === 'fullscreen' && fullscreenSupported"
                    type="button"
                    :class="button"
                    :aria-label="
                        fullscreen
                            ? t('kinetix.lightbox_exit_fullscreen')
                            : t('kinetix.lightbox_fullscreen')
                    "
                    @click="emit('toggle-fullscreen')"
                >
                    <Minimize
                        v-if="fullscreen"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Maximize v-else class="size-4" aria-hidden="true" />
                </button>

                <a
                    v-else-if="name === 'open' && openHref"
                    :href="openHref"
                    target="_blank"
                    rel="noopener noreferrer"
                    :class="button"
                    :aria-label="t('kinetix.lightbox_open_original')"
                >
                    <ExternalLink class="size-4" aria-hidden="true" />
                </a>

                <a
                    v-else-if="name === 'download' && downloadHref"
                    :href="downloadHref"
                    download
                    target="_blank"
                    rel="noopener"
                    :class="button"
                    :aria-label="t('kinetix.download')"
                >
                    <Download class="size-4" aria-hidden="true" />
                </a>
            </template>

            <DialogClose :class="button" :aria-label="t('kinetix.close')">
                <X class="size-4" aria-hidden="true" />
            </DialogClose>
        </div>
    </div>
</template>
