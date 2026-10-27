<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    collectLightboxGroup,
    LIGHTBOX_ATTRIBUTE,
    normalizePreviewDetail,
} from '@/composables/useKinetixLightbox';
import type {
    KinetixLightboxItem,
    KinetixLightboxOptions,
    KinetixLightboxToolbarButton,
    KinetixPreviewDetail,
} from '@/types/kinetix';
import KinetixLightbox from './KinetixLightbox.vue';

/**
 * The app's lightbox host. Mount once in your layout:
 *   <KinetixFilePreview />
 *
 * It opens a KinetixLightbox for
 * - the `kinetix:preview` window event (`openKinetixLightbox()`, image
 *   columns and entries with `preview()`, `PreviewAction`, the media library,
 *   Markdown images) — a single file or a whole gallery;
 * - a click on any `[data-kinetix-lightbox]` element, grouped into a gallery
 *   by the attribute's value (turn off with `:bind="false"`).
 *
 * Its props are the app-wide defaults for every viewer it opens; an event's
 * `options` override them for that one opening.
 */
const props = withDefaults(
    defineProps<{
        bind?: boolean;
        loop?: boolean;
        toolbar?: KinetixLightboxToolbarButton[];
        thumbnails?: boolean | 'auto';
        zoom?: boolean;
        maxZoom?: number;
        closeOnBackdrop?: boolean;
        swipeToClose?: boolean;
        animation?: 'zoom' | 'fade' | 'none';
        preload?: boolean;
    }>(),
    {
        // Undefined, not false: an option the host leaves out keeps the
        // viewer's own default.
        bind: true,
        loop: undefined,
        toolbar: undefined,
        thumbnails: undefined,
        zoom: undefined,
        maxZoom: undefined,
        closeOnBackdrop: undefined,
        swipeToClose: undefined,
        animation: undefined,
        preload: undefined,
    },
);

const open = ref(false);
const index = ref(0);
const items = ref<KinetixLightboxItem[]>([]);
const overrides = ref<KinetixLightboxOptions>({});

const options = computed<KinetixLightboxOptions>(() => ({
    loop: props.loop,
    toolbar: props.toolbar,
    thumbnails: props.thumbnails,
    zoom: props.zoom,
    maxZoom: props.maxZoom,
    closeOnBackdrop: props.closeOnBackdrop,
    swipeToClose: props.swipeToClose,
    animation: props.animation,
    preload: props.preload,
    ...overrides.value,
}));

function show(
    next: KinetixLightboxItem[],
    at: number,
    extra?: KinetixLightboxOptions,
): void {
    items.value = next;
    index.value = at;
    overrides.value = extra ?? {};
    open.value = true;
}

function onPreview(event: Event): void {
    const detail = (event as CustomEvent<KinetixPreviewDetail>).detail;
    const gallery = normalizePreviewDetail(detail);

    if (!gallery) {
        return;
    }

    // Tell a cancelable sender it's handled (it then skips its own fallback,
    // e.g. opening the file in a new tab).
    event.preventDefault();
    show(gallery.items, gallery.index, detail.options);
}

function onDocumentClick(event: MouseEvent): void {
    const modified =
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey;

    if (!props.bind || event.defaultPrevented || modified) {
        return;
    }

    const trigger = (event.target as Element | null)?.closest?.(
        `[${LIGHTBOX_ATTRIBUTE}]`,
    );

    if (!trigger) {
        return;
    }

    const gallery = collectLightboxGroup(trigger);

    if (gallery.items.length === 0) {
        return;
    }

    event.preventDefault();
    show(gallery.items, gallery.index);
}

onMounted(() => {
    window.addEventListener('kinetix:preview', onPreview);
    document.addEventListener('click', onDocumentClick);
});

onBeforeUnmount(() => {
    window.removeEventListener('kinetix:preview', onPreview);
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <KinetixLightbox
        v-model:open="open"
        v-model:index="index"
        :items="items"
        v-bind="options"
    />
</template>
