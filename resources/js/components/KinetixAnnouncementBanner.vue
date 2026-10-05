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
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import {
    useKinetixAnnouncementBanner,
    useKinetixAnnouncementFormat,
} from '@/composables/useKinetixAnnouncements';
import type { KinetixDismissMode } from '@/composables/useKinetixDismissal';
import { focusableNear } from '@/composables/useKinetixFocusTrap';
import { useKinetixReducedMotion } from '@/composables/useKinetixReducedMotion';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import { useKinetixTransition } from '@/composables/useKinetixTransition';
import type { KinetixTransitionPreset } from '@/composables/useKinetixTransition';
import type { KinetixAnnouncement } from '@/types/kinetix';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';
import AlertTitle from './primitives/AlertTitle.vue';
import { cn } from './primitives/cn';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * Announcements as an inline banner instead of a header popover: one entry at a
 * time, rotating through the rest when there is more than one. Dropping it at
 * the top of a page (or a layout) puts the message where the work happens,
 * which the megaphone icon can't do.
 *
 * Closing is per entry and lasts as long as `dismissMode` says — for good on
 * every device by default; `dontShowAgain` lets the ✕ hide it for now while a
 * link closes it for good.
 */
const props = withDefaults(
    defineProps<{
        /**
         * How many entries to rotate through (server ceiling: 10). Unset, it is
         * `announcements.banner_limit` — the shape the page payload carries.
         */
        limit?: number;
        /** Only show these levels; empty = every level. */
        levels?: string[];
        /** Rotation interval in ms; `0` turns auto-rotation off. */
        autoplay?: number;
        /** Show the close button. */
        dismissible?: boolean;
        /**
         * What the ✕ does: `permanent` (the account, every device), `device`
         * (this browser), `session` (this tab) or `hide` (until unmount).
         */
        dismissMode?: KinetixDismissMode;
        /** `session`/`device`: ms until a closed entry comes back. */
        dismissDuration?: number | null;
        /** Add a "Don't show again" link that closes the entry for good. */
        dontShowAgain?: boolean;
        /**
         * `inline` sits in the page flow; `fixed-top` pins the banner to the
         * top of the viewport, above the page and below Kinetix's overlays.
         */
        position?: 'inline' | 'fixed-top';
        /** Max width of the pinned bar (any Tailwind width class). */
        fixedWidthClass?: string;
        /**
         * How the banner enters and leaves. Unset = `slide-down` when pinned,
         * `fade` inline.
         */
        transition?: KinetixTransitionPreset;
        /** How one entry gives way to the next while rotating. */
        slideTransition?: KinetixTransitionPreset;
        class?: string;
    }>(),
    {
        // No default limit: a hardcoded one would override the config and
        // force a fetch on every mount whenever the two disagree.
        limit: undefined,
        autoplay: 8000,
        dismissible: true,
        dismissMode: 'permanent',
        dismissDuration: null,
        dontShowAgain: false,
        position: 'inline',
        fixedWidthClass: 'max-w-3xl',
        transition: undefined,
        slideTransition: 'fade',
    },
);

const { t } = useI18n();
const { announce } = useKinetixAnnounce();
const { levelColor, levelLabel, formatDate } = useKinetixAnnouncementFormat();
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
 * Reduced motion (OS setting, the user's preference or the app's config)
 * means no auto-rotation and no transition — the arrows and dots stay.
 */
const reducedMotion = useKinetixReducedMotion();

const isFixed = computed(() => props.position === 'fixed-top');
const enterLeave = useKinetixTransition(
    () => props.transition ?? (isFixed.value ? 'slide-down' : 'fade'),
);
const slideChange = useKinetixTransition(() => props.slideTransition);

const count = computed(() => announcements.value.length);
const rotates = computed(() => count.value > 1);
const current = computed<KinetixAnnouncement | undefined>(
    () => announcements.value[index.value],
);
const autoplays = computed(
    () => rotates.value && props.autoplay > 0 && !reducedMotion.value,
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

/** A close that outlives the page reads "Dismiss"; a temporary one "Hide". */
function lasts(mode: KinetixDismissMode): boolean {
    return (
        mode === 'permanent' || (mode === 'device' && !props.dismissDuration)
    );
}

const closeLabel = computed(() =>
    t(
        lasts(props.dismissMode)
            ? 'kinetix.announcements_dismiss'
            : 'kinetix.alert_hide',
    ),
);

const root = ref<HTMLElement | null>(null);

/**
 * Close the entry on screen. When the close button held focus, focus stays
 * on the banner while entries remain (the next one is announced), or moves to
 * the next control on the page once the banner itself is gone.
 */
async function close(
    announcement: KinetixAnnouncement,
    mode: KinetixDismissMode,
): Promise<void> {
    const element = root.value;
    const hadFocus =
        element !== null &&
        typeof document !== 'undefined' &&
        element.contains(document.activeElement);
    const last = count.value <= 1;
    const next = hadFocus && last && element ? focusableNear(element) : null;

    const pending = dismiss(announcement, mode, props.dismissDuration);

    await nextTick();

    if (hadFocus) {
        if (last) {
            next?.focus();
        } else {
            root.value
                ?.querySelector<HTMLElement>('[data-slot="alert"]')
                ?.focus();
        }
    }

    announce(
        t(lasts(mode) ? 'kinetix.alert_dismissed' : 'kinetix.alert_hidden'),
    );

    await pending;
}

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

onMounted(() => {
    // Hydrated during setup, so the banner may already be in the page.
    observeHeight();

    return load();
});
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
    <!-- The wrapper is what gets pinned; `inline` leaves it a plain grid so the
         `collapse` preset can animate its height, and the banner keeps
         behaving like any other element in the page. -->
    <Transition v-bind="enterLeave">
        <div
            v-if="current"
            ref="root"
            :class="
                isFixed
                    ? 'inset-x-0 top-0 p-4 fixed z-40 flex justify-center'
                    : 'grid'
            "
        >
            <div :class="isFixed ? 'contents' : 'min-h-0'">
                <Alert
                    role="region"
                    aria-roledescription="carousel"
                    :aria-label="t('kinetix.announcements_title')"
                    tabindex="-1"
                    :class="
                        cn(
                            'gap-3 flex items-start outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            isFixed &&
                                `shadow-lg bg-popover ${fixedWidthClass}`,
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
                    <span class="mt-0.5 shrink-0" aria-hidden="true">
                        <component
                            :is="levelIcons[current.level] ?? Megaphone"
                            class="size-4 text-muted-foreground"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div
                            role="group"
                            aria-roledescription="slide"
                            :aria-label="
                                rotates
                                    ? t(
                                          'kinetix.announcements_slide_position',
                                          {
                                              current: index + 1,
                                              total: count,
                                          },
                                      )
                                    : undefined
                            "
                            :aria-live="running ? 'off' : 'polite'"
                        >
                            <Transition mode="out-in" v-bind="slideChange">
                                <div :key="String(current.id)">
                                    <div
                                        class="gap-2 flex flex-wrap items-center"
                                    >
                                        <AlertTitle class="mb-0">
                                            {{ current.title }}
                                        </AlertTitle>
                                        <KinetixBadge
                                            :color="levelColor(current.level)"
                                            size="sm"
                                            class="shrink-0"
                                        >
                                            {{ levelLabel(current.level) }}
                                        </KinetixBadge>
                                        <span
                                            v-if="current.isNew"
                                            class="sr-only"
                                        >
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

                        <button
                            v-if="dismissible && dontShowAgain"
                            type="button"
                            :class="
                                cn(
                                    buttonVariants({
                                        variant: 'link',
                                        size: 'sm',
                                    }),
                                    'mt-1 px-0 text-muted-foreground',
                                )
                            "
                            @click="close(current, 'permanent')"
                        >
                            {{ t('kinetix.alert_dont_show_again') }}
                        </button>

                        <div
                            v-if="rotates"
                            class="gap-1 mt-3 flex items-center"
                        >
                            <button
                                type="button"
                                :class="
                                    buttonVariants({
                                        variant: 'ghost',
                                        size: 'icon-sm',
                                    })
                                "
                                :aria-label="
                                    t('kinetix.announcements_previous')
                                "
                                @click="move(-1)"
                            >
                                <ChevronLeft
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>

                            <div class="gap-1 px-1 flex items-center">
                                <button
                                    v-for="(a, i) in announcements"
                                    :key="String(a.id)"
                                    type="button"
                                    class="size-6 grid place-items-center rounded-full focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    :aria-current="
                                        i === index ? 'true' : undefined
                                    "
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
                                <ChevronRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
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
                                <Play
                                    v-if="paused"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <Pause
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>

                    <button
                        v-if="dismissible"
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        class="-mt-1 -mr-1 shrink-0"
                        :aria-label="closeLabel"
                        @click="close(current, dismissMode)"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </Alert>
            </div>
        </div>
    </Transition>
</template>
