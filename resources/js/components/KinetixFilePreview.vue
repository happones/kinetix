<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useKinetixLazyOnDemand } from '@/composables/useKinetixLazyHost';
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
import { KINETIX_LIGHTBOX_BODY } from './kinetixLayoutBodyLoaders';

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
 *
 * The viewer itself loads on the first opening: the click or event is
 * claimed (`preventDefault`) at once, and the lightbox opens as soon as its
 * code has arrived.
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
        slideshowInterval?: number;
        autoplay?: boolean;
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
        slideshowInterval: undefined,
        autoplay: undefined,
    },
);

const {
    body: lightbox,
    open,
    request,
} = useKinetixLazyOnDemand(KINETIX_LIGHTBOX_BODY, 'The lightbox');

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
    slideshowInterval: props.slideshowInterval,
    autoplay: props.autoplay,
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
    request();
}

/** Start the download when a pointer or focus reaches a trigger; once. */
function onTriggerIntent(event: Event): void {
    if (
        !(event.target as Element | null)?.closest?.(`[${LIGHTBOX_ATTRIBUTE}]`)
    ) {
        return;
    }

    removeIntentListeners();
    KINETIX_LIGHTBOX_BODY.preload().catch(() => {
        // Retried on the click.
    });
}

function removeIntentListeners(): void {
    document.removeEventListener('pointerover', onTriggerIntent);
    document.removeEventListener('focusin', onTriggerIntent);
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

    if (props.bind && !KINETIX_LIGHTBOX_BODY.loaded) {
        document.addEventListener('pointerover', onTriggerIntent);
        document.addEventListener('focusin', onTriggerIntent);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('kinetix:preview', onPreview);
    document.removeEventListener('click', onDocumentClick);
    removeIntentListeners();
});
</script>

<template>
    <component
        :is="lightbox"
        v-if="lightbox"
        v-model:open="open"
        v-model:index="index"
        :items="items"
        v-bind="options"
    />
</template>
