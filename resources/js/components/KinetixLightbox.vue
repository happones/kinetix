<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import {
    lightboxFlipFrames,
    resolveLightboxItemType,
} from '@/composables/useKinetixLightbox';
import { useKinetixLightboxGestures } from '@/composables/useKinetixLightboxGestures';
import { useKinetixReducedMotion } from '@/composables/useKinetixReducedMotion';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import { useKinetixTransition } from '@/composables/useKinetixTransition';
import type {
    KinetixLightboxItem,
    KinetixLightboxToolbarButton,
} from '@/types/kinetix';
import LightboxSlide from './Lightbox/LightboxSlide.vue';
import LightboxThumbnails from './Lightbox/LightboxThumbnails.vue';
import LightboxToolbar from './Lightbox/LightboxToolbar.vue';
import { cn } from './primitives/cn';

/**
 * A full-screen media viewer on Reka's Dialog (focus trap, Escape, focus
 * returned to the trigger, scroll lock):
 *
 *   <KinetixLightbox v-model:open="open" v-model:index="index" :items="photos" />
 *
 * Images open by zooming out of the element they came from (`item.origin`)
 * and shrink back into it on close; galleries page with the arrows, the
 * keyboard (←/→, Home/End), a swipe or the thumbnail strip; images zoom with
 * the wheel, a pinch, a double-click/tap or the toolbar (+/−/0 on the
 * keyboard) and pan by dragging; a vertical swipe dismisses. PDFs show in a
 * frame and anything else as a download card, so one viewer serves a mixed
 * gallery.
 *
 * The viewer is always a dark surface: it scopes the `dark` theme tokens to
 * itself, so its controls are the host's own shadcn tokens in either mode.
 * Every animation stands still under reduced motion.
 *
 * Most apps never mount it directly: `<KinetixFilePreview />` hosts one for
 * the whole app and opens it from `openKinetixLightbox()`, the
 * `kinetix:preview` event and `data-kinetix-lightbox` triggers.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        items: KinetixLightboxItem[];
        /** The item shown (v-model:index). */
        index?: number;
        /** Accessible name when the item has no caption or alt text. */
        title?: string | null;
        /** Page from the last item to the first and back. */
        loop?: boolean;
        /** Toolbar buttons, in order; close is always shown. */
        toolbar?: KinetixLightboxToolbarButton[];
        /** Thumbnail strip: `auto` shows it on wider screens, toggleable on phones. */
        thumbnails?: boolean | 'auto';
        zoom?: boolean;
        maxZoom?: number;
        /** Close on a click/tap on the empty space around the item. */
        closeOnBackdrop?: boolean;
        swipeToClose?: boolean;
        /** `zoom` grows images out of their origin; `fade` cross-fades. */
        animation?: 'zoom' | 'fade' | 'none';
        /** Load the neighbours of the current image ahead of time. */
        preload?: boolean;
    }>(),
    {
        index: 0,
        title: null,
        loop: false,
        toolbar: () => ['zoom', 'thumbnails', 'fullscreen', 'download'],
        thumbnails: 'auto',
        zoom: true,
        maxZoom: 4,
        closeOnBackdrop: true,
        swipeToClose: true,
        animation: 'zoom',
        preload: true,
    },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'update:index', value: number): void;
    (e: 'change', index: number, item: KinetixLightboxItem): void;
}>();

defineSlots<{
    caption?(props: { item: KinetixLightboxItem; index: number }): unknown;
    actions?(props: { item: KinetixLightboxItem; index: number }): unknown;
}>();

const { t } = useI18n();
const { announce } = useKinetixAnnounce();
const reduced = useKinetixReducedMotion();

const viewer = ref<HTMLElement | null>(null);
const stage = ref<HTMLElement | null>(null);
const slide = ref<InstanceType<typeof LightboxSlide> | null>(null);

const current = ref(0);
const direction = ref<1 | -1>(1);
const closing = ref(false);
const flipping = ref(false);
const thumbnailsVisible = ref(true);
const fullscreen = ref(false);
const fullscreenSupported =
    typeof document !== 'undefined' && !!document.fullscreenEnabled;

let pendingFlip = false;
let flipTimer: ReturnType<typeof setTimeout> | null = null;
let flight: Animation | null = null;

const total = computed(() => props.items.length);
const item = computed<KinetixLightboxItem | null>(
    () => props.items[current.value] ?? null,
);
const type = computed(() =>
    item.value ? resolveLightboxItemType(item.value) : 'other',
);
const zoomable = computed(() => props.zoom && type.value === 'image');
const zoomMotion = computed(() => props.animation === 'zoom' && !reduced.value);

function clampIndex(value: number): number {
    if (total.value === 0) {
        return 0;
    }

    return Math.min(Math.max(Math.trunc(value), 0), total.value - 1);
}

function canPage(step: 1 | -1): boolean {
    if (total.value < 2) {
        return false;
    }

    if (props.loop) {
        return true;
    }

    return step === 1 ? current.value < total.value - 1 : current.value > 0;
}

function show(next: number): void {
    if (next === current.value || !props.items[next]) {
        return;
    }

    current.value = next;
    emit('update:index', next);
    emit('change', next, props.items[next]);

    const caption = props.items[next].caption;

    announce(caption ? `${position.value}: ${caption}` : position.value);
}

function page(step: 1 | -1): void {
    if (!canPage(step)) {
        return;
    }

    direction.value = step;
    show((current.value + step + total.value) % total.value);
}

function goTo(next: number): void {
    const target = clampIndex(next);

    direction.value = target >= current.value ? 1 : -1;
    show(target);
}

const gestures = useKinetixLightboxGestures({
    stage,
    media: () => slide.value?.media ?? null,
    zoomable: () => zoomable.value,
    maxZoom: () => props.maxZoom,
    canPage,
    swipeToClose: () => props.swipeToClose,
    onPage: page,
    onDismiss: () => close(false),
    onTap,
});

const slideTransition = useKinetixTransition(() => {
    if (props.animation === 'none') {
        return 'none';
    }

    return direction.value === 1 ? 'slide-left' : 'slide-right';
});

const mediaTransform = computed(
    () =>
        `translate3d(${gestures.offsetX.value}px, ${gestures.offsetY.value}px, 0) scale(${gestures.scale.value})`,
);
const slideTransform = computed(() => {
    const shrink = 1 - gestures.dismissProgress.value * 0.15;

    return `translate3d(${gestures.dragX.value}px, ${gestures.dragY.value}px, 0) scale(${shrink})`;
});
const overlayOpacity = computed(() =>
    closing.value ? 0 : 1 - gestures.dismissProgress.value * 0.7,
);
/** Controls step aside while the image flies home or is dragged away. */
const chromeHidden = computed(
    () => closing.value || gestures.dismissProgress.value > 0,
);

const counter = computed(() =>
    total.value > 1 ? `${current.value + 1} / ${total.value}` : '',
);
const position = computed(() =>
    total.value > 1
        ? t('kinetix.lightbox_position', {
              current: current.value + 1,
              total: total.value,
          })
        : '',
);
const accessibleTitle = computed(
    () =>
        item.value?.caption ||
        item.value?.alt ||
        props.title ||
        t('kinetix.lightbox_title'),
);
const downloadHref = computed(() => {
    const target = item.value;

    if (!target || target.downloadUrl === false) {
        return null;
    }

    return target.downloadUrl ?? target.url;
});
const hasThumbnails = computed(
    () => props.thumbnails !== false && total.value > 1,
);

function onTap(event: PointerEvent): void {
    if (!props.closeOnBackdrop) {
        return;
    }

    const target = event.target as Element | null;

    if (target?.closest?.('[data-lightbox-media], button, a, object')) {
        return;
    }

    close();
}

function runFlip(media: HTMLElement, reverse: boolean): Animation | null {
    const origin = item.value?.origin;
    const frames = origin ? lightboxFlipFrames(media, origin) : null;

    if (!frames || typeof media.animate !== 'function') {
        return null;
    }

    return media.animate(reverse ? [...frames].reverse() : frames, {
        duration: reverse ? 220 : 320,
        easing: reverse
            ? 'cubic-bezier(0.4, 0, 1, 1)'
            : 'cubic-bezier(0.22, 1, 0.36, 1)',
        fill: reverse ? 'forwards' : 'none',
    });
}

function onReady(media: HTMLImageElement): void {
    if (!pendingFlip) {
        return;
    }

    pendingFlip = false;

    const animation = runFlip(media, false);

    if (!animation) {
        return;
    }

    flipping.value = true;
    flight = animation;
    animation.onfinish = animation.oncancel = () => {
        flipping.value = false;
        flight = null;
    };
}

/**
 * Close, shrinking the image back into its origin when it can: zoom motion
 * on, the image loaded at fit, the origin still on screen.
 */
async function close(animate = true): Promise<void> {
    if (closing.value || !props.open) {
        return;
    }

    flight?.finish();

    if (document.fullscreenElement) {
        void document.exitFullscreen?.().catch(() => undefined);
    }

    const media = slide.value?.media ?? null;
    const homeward =
        animate &&
        zoomMotion.value &&
        media &&
        slide.value?.loaded &&
        !gestures.zoomed.value
            ? runFlip(media, true)
            : null;

    if (!homeward) {
        emit('update:open', false);

        return;
    }

    closing.value = true;
    await homeward.finished.catch(() => undefined);
    emit('update:open', false);
}

function onOpenChange(value: boolean): void {
    if (value) {
        emit('update:open', true);

        return;
    }

    void close();
}

function onOpenAutoFocus(event: Event): void {
    // Focus the viewer itself so the arrow keys work from the start.
    event.preventDefault();
    viewer.value?.focus({ preventScroll: true });
}

function onKeydown(event: KeyboardEvent): void {
    if (
        event.defaultPrevented ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey
    ) {
        return;
    }

    const actions: Record<string, () => void> = {
        ArrowRight: () => page(1),
        ArrowLeft: () => page(-1),
        Home: () => goTo(0),
        End: () => goTo(total.value - 1),
        '+': gestures.zoomIn,
        '=': gestures.zoomIn,
        '-': gestures.zoomOut,
        '0': gestures.reset,
    };
    const action = actions[event.key];

    if (!action) {
        return;
    }

    event.preventDefault();
    action();
}

function toggleFullscreen(): void {
    if (document.fullscreenElement) {
        void document.exitFullscreen?.().catch(() => undefined);

        return;
    }

    void viewer.value?.requestFullscreen?.().catch(() => undefined);
}

function syncFullscreen(): void {
    fullscreen.value = !!document.fullscreenElement;
}

function preloadNeighbours(): void {
    if (!props.preload || total.value < 2 || typeof Image === 'undefined') {
        return;
    }

    for (const step of [1, -1]) {
        const neighbour =
            props.items[(current.value + step + total.value) % total.value];

        if (neighbour && resolveLightboxItemType(neighbour) === 'image') {
            new Image().src = neighbour.url;
        }
    }
}

function startOpening(): void {
    current.value = clampIndex(props.index);
    direction.value = 1;
    closing.value = false;
    gestures.reset();
    thumbnailsVisible.value =
        props.thumbnails === true ||
        (props.thumbnails === 'auto' &&
            (window.matchMedia?.('(min-width: 640px)').matches ?? true));

    // Zoom out of the origin only if the image arrives soon; a slow one fades in.
    pendingFlip = zoomMotion.value && !!item.value?.origin;

    if (flipTimer) {
        clearTimeout(flipTimer);
    }

    flipTimer = setTimeout(() => {
        pendingFlip = false;
        flipTimer = null;
    }, 600);
    preloadNeighbours();
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            startOpening();

            return;
        }

        closing.value = false;
    },
    { immediate: true },
);

watch(
    () => props.index,
    (next) => {
        if (!props.open) {
            return;
        }

        goTo(next);
    },
);

watch(
    () => props.items,
    () => {
        current.value = clampIndex(current.value);
    },
);

watch(current, () => {
    gestures.reset();
    preloadNeighbours();
});

onMounted(() => {
    document.addEventListener('fullscreenchange', syncFullscreen);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', syncFullscreen);
    flight?.cancel();

    if (flipTimer) {
        clearTimeout(flipTimer);
    }
});

defineExpose({
    next: () => page(1),
    previous: () => page(-1),
    goTo,
    zoomIn: gestures.zoomIn,
    zoomOut: gestures.zoomOut,
    resetZoom: gestures.reset,
    close,
});

const navButton = cn(
    buttonVariants({ variant: 'secondary', size: 'icon-lg' }),
    'shadow-xs absolute top-1/2 z-10 -translate-y-1/2 rounded-full',
);
const chrome = 'transition-opacity duration-150';
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay
                class="inset-0 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 supports-[backdrop-filter]:backdrop-blur-sm fixed z-[var(--kinetix-z-overlay,100)]"
                :class="
                    gestures.gesturing.value
                        ? ''
                        : 'transition-opacity duration-200'
                "
                :style="{ opacity: overlayOpacity }"
            />
            <DialogContent
                class="dark inset-0 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed z-[var(--kinetix-z-modal,100)] text-foreground [color-scheme:dark] outline-none"
                @open-auto-focus="onOpenAutoFocus"
            >
                <div
                    ref="viewer"
                    tabindex="-1"
                    class="flex h-full flex-col outline-none"
                    data-kinetix-lightbox-viewer
                    @keydown="onKeydown"
                >
                    <DialogTitle class="sr-only">
                        {{ accessibleTitle }}
                    </DialogTitle>
                    <DialogDescription class="sr-only">
                        {{ position || accessibleTitle }}
                    </DialogDescription>

                    <LightboxToolbar
                        :class="[chrome, chromeHidden ? 'opacity-0' : '']"
                        :buttons="toolbar"
                        :counter="counter"
                        :zoomable="zoomable"
                        :can-zoom-in="gestures.canZoomIn.value"
                        :can-zoom-out="gestures.canZoomOut.value"
                        :fullscreen="fullscreen"
                        :fullscreen-supported="fullscreenSupported"
                        :download-href="downloadHref"
                        :open-href="item?.url ?? null"
                        :has-thumbnails="hasThumbnails"
                        :thumbnails-visible="thumbnailsVisible"
                        @zoom-in="gestures.zoomIn"
                        @zoom-out="gestures.zoomOut"
                        @toggle-fullscreen="toggleFullscreen"
                        @toggle-thumbnails="
                            thumbnailsVisible = !thumbnailsVisible
                        "
                    >
                        <template v-if="item && $slots.actions" #actions>
                            <slot
                                name="actions"
                                :item="item"
                                :index="current"
                            />
                        </template>
                    </LightboxToolbar>

                    <div
                        ref="stage"
                        class="min-h-0 relative flex-1 touch-none overflow-hidden select-none"
                        @pointerdown="gestures.onPointerDown"
                        @pointermove="gestures.onPointerMove"
                        @pointerup="gestures.onPointerUp"
                        @pointercancel="gestures.onPointerCancel"
                        @wheel="gestures.onWheel"
                    >
                        <Transition v-bind="slideTransition">
                            <LightboxSlide
                                v-if="item"
                                :key="current"
                                ref="slide"
                                :item="item"
                                :media-transform="mediaTransform"
                                :slide-transform="slideTransform"
                                :gesturing="gestures.gesturing.value"
                                :zoomable="zoomable"
                                :zoomed="gestures.zoomed.value"
                                :flipping="flipping"
                                @ready="onReady"
                            />
                        </Transition>

                        <template v-if="total > 1">
                            <button
                                type="button"
                                :class="[
                                    navButton,
                                    chrome,
                                    chromeHidden ? 'opacity-0' : '',
                                    'left-2 sm:left-4',
                                ]"
                                :disabled="!canPage(-1)"
                                :aria-label="t('kinetix.lightbox_previous')"
                                @click="page(-1)"
                            >
                                <ChevronLeft
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    navButton,
                                    chrome,
                                    chromeHidden ? 'opacity-0' : '',
                                    'right-2 sm:right-4',
                                ]"
                                :disabled="!canPage(1)"
                                :aria-label="t('kinetix.lightbox_next')"
                                @click="page(1)"
                            >
                                <ChevronRight
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </button>
                        </template>
                    </div>

                    <div
                        v-if="
                            item &&
                            ($slots.caption || item.caption || item.description)
                        "
                        class="px-4 pt-3 max-w-3xl mx-auto w-full shrink-0 text-center"
                        :class="[chrome, chromeHidden ? 'opacity-0' : '']"
                    >
                        <slot name="caption" :item="item" :index="current">
                            <p
                                v-if="item.caption"
                                class="text-sm font-medium text-foreground"
                            >
                                {{ item.caption }}
                            </p>
                            <p
                                v-if="item.description"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ item.description }}
                            </p>
                        </slot>
                    </div>

                    <LightboxThumbnails
                        v-if="hasThumbnails && thumbnailsVisible"
                        :class="[chrome, chromeHidden ? 'opacity-0' : '']"
                        :items="items"
                        :index="current"
                        @select="goTo"
                    />
                    <div
                        v-else
                        class="h-[max(0.75rem,env(safe-area-inset-bottom))] shrink-0"
                        aria-hidden="true"
                    />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
