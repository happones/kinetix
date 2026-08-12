<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Info,
    Megaphone,
    Pause,
    Play,
    Sparkles,
    Wrench,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    useKinetixAnnouncementBanner,
    useKinetixAnnouncementFormat,
} from '@/composables/useKinetixAnnouncements';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixAnnouncement } from '@/types/kinetix';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';
import AlertTitle from './primitives/AlertTitle.vue';
import { cn } from './primitives/cn';

/**
 * Announcements as an inline banner instead of a header popover: one entry at a
 * time, rotating through the rest when there is more than one. Dropping it at
 * the top of a page (or a layout) puts the message where the work happens,
 * which the megaphone icon can't do.
 *
 * Dismissing is per entry and persisted server-side — a closed banner stays
 * closed on the user's other devices.
 */
const props = withDefaults(
    defineProps<{
        /** How many entries to rotate through (server ceiling: 10). */
        limit?: number;
        /** Only show these levels; empty = every level. */
        levels?: string[];
        /** Rotation interval in ms; `0` turns auto-rotation off. */
        autoplay?: number;
        /** Show the close button (dismissal is per user, per announcement). */
        dismissible?: boolean;
        /**
         * `inline` sits in the page flow; `fixed-top` pins the banner to the
         * top of the viewport, above the page and below Kinetix's overlays.
         */
        position?: 'inline' | 'fixed-top';
        /** Max width of the pinned bar (any Tailwind width class). */
        fixedWidthClass?: string;
        class?: string;
    }>(),
    {
        limit: 3,
        autoplay: 8000,
        dismissible: true,
        position: 'inline',
        fixedWidthClass: 'max-w-3xl',
    },
);

const { t } = useI18n();
const { levelClass, levelLabel, formatDate } = useKinetixAnnouncementFormat();
const { announcements, load, dismiss } = useKinetixAnnouncementBanner({
    limit: props.limit,
    levels: props.levels,
});

const levelIcons: Record<string, Component> = {
    feature: Sparkles,
    fix: Wrench,
    info: Info,
};

const index = ref(0);
/** Explicit pause (the button) — kept apart from the transient hover/focus
 * pause, so moving the mouse away doesn't restart what the user stopped. */
const paused = ref(false);
const hovered = ref(false);
const focused = ref(false);

/**
 * Users who asked the OS for less motion get no auto-rotation and no
 * transition — they keep the arrows and the dots.
 */
const reducedMotion =
    typeof window !== 'undefined' &&
    !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const count = computed(() => announcements.value.length);
const rotates = computed(() => count.value > 1);
const current = computed<KinetixAnnouncement | undefined>(
    () => announcements.value[index.value],
);
const autoplays = computed(
    () => rotates.value && props.autoplay > 0 && !reducedMotion,
);
const running = computed(
    () => autoplays.value && !paused.value && !hovered.value && !focused.value,
);

let timer: ReturnType<typeof setInterval> | undefined;

function stop(): void {
    if (timer !== undefined) {
        clearInterval(timer);
        timer = undefined;
    }
}

function restart(): void {
    stop();

    if (running.value) {
        timer = setInterval(() => go(1), props.autoplay);
    }
}

function go(delta: number): void {
    if (count.value === 0) {
        return;
    }

    index.value = (index.value + delta + count.value) % count.value;
}

/** Arrows and dots restart the clock, so a rotation never lands mid-read. */
function select(next: number): void {
    index.value = next;
    restart();
}

function move(delta: number): void {
    go(delta);
    restart();
}

async function close(announcement: KinetixAnnouncement): Promise<void> {
    await dismiss(announcement);
}

const isFixed = computed(() => props.position === 'fixed-top');
const root = ref<HTMLElement | null>(null);

/**
 * A pinned bar covers whatever is under it. Publishing its measured height as
 * `--kinetix-announcement-banner-height` lets the layout reserve the space
 * (`padding-top: var(--kinetix-announcement-banner-height, 0px)`) and get it
 * back the moment the banner is dismissed — the height is not a constant, since
 * entries wrap differently.
 */
const HEIGHT_VAR = '--kinetix-announcement-banner-height';
let observer: ResizeObserver | undefined;

function publishHeight(): void {
    if (typeof document === 'undefined') {
        return;
    }

    // An inline banner never claims the variable — a page may hold both, and
    // only the pinned one is covering anything.
    if (!isFixed.value) {
        document.documentElement.style.removeProperty(HEIGHT_VAR);

        return;
    }

    document.documentElement.style.setProperty(
        HEIGHT_VAR,
        `${root.value?.offsetHeight ?? 0}px`,
    );
}

function observeHeight(): void {
    observer?.disconnect();
    observer = undefined;

    if (
        isFixed.value &&
        root.value !== null &&
        typeof ResizeObserver !== 'undefined'
    ) {
        observer = new ResizeObserver(publishHeight);
        observer.observe(root.value);
    }

    publishHeight();
}

onMounted(load);
onBeforeUnmount(() => {
    stop();
    observer?.disconnect();

    if (typeof document !== 'undefined') {
        document.documentElement.style.removeProperty(HEIGHT_VAR);
    }
});

watch([root, isFixed], observeHeight);

watch(running, restart);
// A dismissal shortens the list: keep the cursor inside it and let the timer
// pick up the new length.
watch(count, (value) => {
    if (index.value >= value) {
        index.value = Math.max(0, value - 1);
    }

    restart();
});
</script>

<template>
    <!-- The wrapper is what gets pinned; `inline` leaves it an unstyled block
         so the banner keeps behaving like any other element in the page. -->
    <Transition
        :enter-active-class="
            reducedMotion || !isFixed ? '' : 'transition-all duration-300'
        "
        enter-from-class="opacity-0 -translate-y-full"
        :leave-active-class="
            reducedMotion || !isFixed ? '' : 'transition-all duration-200'
        "
        leave-to-class="opacity-0 -translate-y-full"
    >
        <div
            v-if="current"
            ref="root"
            :class="
                isFixed
                    ? 'inset-x-0 top-0 p-4 fixed z-40 flex justify-center'
                    : undefined
            "
        >
            <Alert
                role="region"
                aria-roledescription="carousel"
                :aria-label="t('kinetix.announcements_title')"
                :class="
                    cn(
                        'gap-3 [&>svg]:translate-y-0 [&>svg+div]:translate-y-0 flex items-start [&>svg]:static',
                        isFixed && `shadow-lg bg-popover ${fixedWidthClass}`,
                        props.class,
                    )
                "
                @mouseenter="hovered = true"
                @mouseleave="hovered = false"
                @focusin="focused = true"
                @focusout="focused = false"
                @keydown.left="rotates && move(-1)"
                @keydown.right="rotates && move(1)"
            >
                <component
                    :is="levelIcons[current.level] ?? Megaphone"
                    class="size-4 mt-0.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1">
                    <div
                        role="group"
                        aria-roledescription="slide"
                        :aria-label="
                            rotates
                                ? t('kinetix.announcements_slide_position', {
                                      current: index + 1,
                                      total: count,
                                  })
                                : undefined
                        "
                        :aria-live="running ? 'off' : 'polite'"
                    >
                        <Transition
                            mode="out-in"
                            :enter-active-class="
                                reducedMotion
                                    ? ''
                                    : 'transition-opacity duration-200'
                            "
                            :leave-active-class="
                                reducedMotion
                                    ? ''
                                    : 'transition-opacity duration-150'
                            "
                            enter-from-class="opacity-0"
                            leave-to-class="opacity-0"
                        >
                            <div :key="String(current.id)">
                                <div class="gap-2 flex flex-wrap items-center">
                                    <AlertTitle class="mb-0">
                                        {{ current.title }}
                                    </AlertTitle>
                                    <span
                                        class="px-2 py-0.5 font-medium shrink-0 rounded-full text-[10px]"
                                        :class="levelClass(current.level)"
                                    >
                                        {{ levelLabel(current.level) }}
                                    </span>
                                    <span v-if="current.isNew" class="sr-only">
                                        {{ t('kinetix.announcements_new') }}
                                    </span>
                                </div>

                                <AlertDescription
                                    class="mt-1 whitespace-pre-line text-muted-foreground"
                                >
                                    {{ current.body }}
                                </AlertDescription>

                                <p
                                    v-if="current.publishedAt"
                                    class="mt-1 text-xs text-muted-foreground/70"
                                >
                                    {{ formatDate(current.publishedAt) }}
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <div v-if="rotates" class="gap-1 mt-3 flex items-center">
                        <button
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                            :aria-label="t('kinetix.announcements_previous')"
                            @click="move(-1)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>

                        <div class="gap-1 px-1 flex items-center">
                            <button
                                v-for="(a, i) in announcements"
                                :key="String(a.id)"
                                type="button"
                                class="size-6 grid place-items-center rounded-full focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                :aria-current="i === index ? 'true' : undefined"
                                :aria-label="
                                    t('kinetix.announcements_go_to', {
                                        title: a.title,
                                    })
                                "
                                @click="select(i)"
                            >
                                <span
                                    class="size-1.5 rounded-full transition-colors"
                                    :class="
                                        i === index
                                            ? 'bg-primary'
                                            : 'bg-muted-foreground/40'
                                    "
                                />
                            </button>
                        </div>

                        <button
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                            :aria-label="t('kinetix.announcements_next')"
                            @click="move(1)"
                        >
                            <ChevronRight class="size-4" />
                        </button>

                        <button
                            v-if="autoplays"
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                            :aria-label="
                                paused
                                    ? t('kinetix.announcements_play')
                                    : t('kinetix.announcements_pause')
                            "
                            :aria-pressed="paused"
                            @click="paused = !paused"
                        >
                            <Play v-if="paused" class="size-4" />
                            <Pause v-else class="size-4" />
                        </button>
                    </div>
                </div>

                <button
                    v-if="dismissible"
                    type="button"
                    :class="
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                    "
                    class="-mt-1 -mr-1 shrink-0"
                    :aria-label="t('kinetix.announcements_dismiss')"
                    @click="close(current)"
                >
                    <X class="size-4" />
                </button>
            </Alert>
        </div>
    </Transition>
</template>
