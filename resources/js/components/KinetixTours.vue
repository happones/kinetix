<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { requestTourReveal } from '@/composables/useKinetixTourReveal';
import { useKinetixToursStore } from '@/stores/kinetixTours';
import type { KinetixTourData, KinetixTourStepData } from '@/types/kinetix';

/**
 * The global product-tour host. Mount ONE instance in your app layout:
 *
 *     <KinetixTours />
 *
 * On every Inertia navigation it looks for an unseen, auto-start tour whose
 * `page`/`url` pattern matches, and drives it with driver.js (spotlight
 * overlay, auto-scroll, collision-aware popover) themed to the shadcn tokens
 * via the published `kinetix.css`. Manual launches go through the tours pinia
 * store: `useKinetixToursStore().start('posts')`.
 *
 * driver.js is an opt-in host dependency (`kinetix:install --tours`); without
 * it the host warns once in the console and tours simply don't render.
 */
const page = usePage();
const { t } = useI18n();
const store = useKinetixToursStore();

// driver.js's driver() instance for the running tour (untyped here so the
// package compiles without the optional dependency installed).
/** The part of driver.js's `driver()` the tours use. */
type DriverFactory = (config: Record<string, unknown>) => {
    drive: (index?: number) => void;
    moveTo: (index: number) => void;
    getActiveIndex: () => number | undefined;
    destroy: () => void;
};

let activeDriver: { destroy: () => void } | null = null;
let warnedMissing = false;

/** Delay (ms) before auto-starting, so the page's DOM settles first. */
const START_DELAY = 400;
let pending: ReturnType<typeof setTimeout> | null = null;

/** Room the popover takes beside its target: its height plus the gap. */
const POPOVER_ROOM = 240;
/** Space kept clear at the screen's edges (a sticky header sits there). */
const EDGE = 72;
/** The least of a tall target worth highlighting without scrolling. */
const MIN_HIGHLIGHT = 160;
/** Frames to wait for a revealed target (a tab switch, a nested group mounting). */
const REVEAL_ATTEMPTS = 10;
const REVEAL_FRAME = 40;

/** Selectors already warned about, so a missing target warns once. */
const warnedTargets = new Set<string>();

/**
 * The element a selector means on this page: the first VISIBLE match, and
 * one outside content a modal hides (`aria-hidden`) before one inside it —
 * so with a modal open over a page, the same field name points at the
 * modal's field.
 */
function findTarget(selector: string): HTMLElement | null {
    let matches: HTMLElement[];

    try {
        matches = Array.from(document.querySelectorAll<HTMLElement>(selector));
    } catch {
        return null;
    }

    const visible = matches.filter(
        (element) => element.getClientRects().length > 0,
    );
    const candidates = visible.length > 0 ? visible : matches;

    return (
        candidates.find(
            (element) => element.closest('[aria-hidden="true"]') === null,
        ) ??
        candidates[0] ??
        null
    );
}

// Stands in for a target too tall to highlight whole (see tourTarget()).
let tallTarget: HTMLElement | null = null;

/**
 * The element a step highlights. A target taller than the screen can hold
 * next to its popover — a long table — is highlighted from its top down to
 * what fits. Highlighted whole, driver.js scrolled its top to the very top
 * of the screen, under the app's sticky header, and with no room left on any
 * side parked the popover at the bottom of the screen, without its arrow,
 * over the rows: the page jumped and the message pointed at nothing.
 *
 * When the target's top is on screen with room below it, the highlight ends
 * there and the page doesn't move at all.
 */
function tourTarget(selector: string): Element | null {
    const element = findTarget(selector);

    if (!element) {
        return null;
    }

    const rect = element.getBoundingClientRect();
    const fits = window.innerHeight - EDGE * 2 - POPOVER_ROOM;

    if (rect.height <= fits) {
        return element;
    }

    const belowTop = window.innerHeight - EDGE - POPOVER_ROOM - rect.top;
    const height =
        rect.top >= EDGE && belowTop >= MIN_HIGHLIGHT ? belowTop : fits;

    tallTarget ??= document.createElement('div');
    tallTarget.setAttribute('data-kinetix-tour-target', '');
    tallTarget.setAttribute('aria-hidden', 'true');
    Object.assign(tallTarget.style, {
        position: 'absolute',
        top: `${rect.top + window.scrollY}px`,
        left: `${rect.left + window.scrollX}px`,
        width: `${rect.width}px`,
        height: `${Math.max(height, MIN_HIGHLIGHT)}px`,
        pointerEvents: 'none',
    });

    if (!tallTarget.isConnected) {
        document.body.appendChild(tallTarget);
    }

    return tallTarget;
}

/**
 * Get a step's target onto the page: ask the tab groups and wizards to open
 * what holds it, and wait a few frames for it to render (a nested group only
 * mounts once its parent tab opens, so the request repeats). Returns the
 * selector to highlight — the target, a fallback a wizard offered, or null.
 */
async function prepare(step: KinetixTourStepData): Promise<string | null> {
    if (!step.reveal) {
        return findTarget(step.selector) ? step.selector : null;
    }

    for (let attempt = 0; attempt < REVEAL_ATTEMPTS; attempt++) {
        if (findTarget(step.selector)) {
            return step.selector;
        }

        const fallback = requestTourReveal(step.reveal);

        if (fallback) {
            return fallback;
        }

        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, REVEAL_FRAME));
    }

    return findTarget(step.selector) ? step.selector : null;
}

function warnMissing(selector: string): void {
    if (!import.meta.env?.DEV || warnedTargets.has(selector)) {
        return;
    }

    warnedTargets.add(selector);
    console.warn(
        `Kinetix tours: no element matches "${selector}"; the step shows centered. Mark it ->optional() to skip it instead.`,
    );
}

async function run(tour: KinetixTourData): Promise<void> {
    let driverFactory: DriverFactory;

    try {
        // Lazy import keeps driver.js code-split AND optional — apps that
        // never enable tours don't ship or need it. Typed here, not by the
        // app: whatever version (or `declare module` shim) it has, the
        // component only relies on these methods.
        const module = (await import('driver.js')) as unknown as {
            driver: DriverFactory;
        };
        await import('driver.js/dist/driver.css');
        driverFactory = module.driver;
    } catch {
        if (!warnedMissing) {
            warnedMissing = true;
            console.warn(
                'Kinetix tours: driver.js is not installed. Run `php artisan kinetix:install --tours` (or `npm i driver.js`).',
            );
        }

        return;
    }

    activeDriver?.destroy();

    // An optional step whose target isn't here (and that nothing can
    // reveal) is left out up front, so the progress count stays honest.
    const steps = tour.steps.filter(
        (step) =>
            !step.optional || step.reveal || findTarget(step.selector) !== null,
    );

    if (steps.length === 0) {
        return;
    }

    // Where each step points once prepared (a wizard may offer a fallback).
    const resolved = steps.map((step) => step.selector);

    const instance = driverFactory({
        showProgress: steps.length > 1,
        // A one-step tour has nowhere to go back to.
        showButtons:
            steps.length > 1
                ? ['next', 'previous', 'close']
                : ['next', 'close'],
        progressText: t('kinetix.tour_progress', {
            current: '{{current}}',
            total: '{{total}}',
        }),
        nextBtnText: t('kinetix.tour_next'),
        prevBtnText: t('kinetix.tour_previous'),
        doneBtnText: t('kinetix.tour_done'),
        popoverClass: 'kinetix-tour-popover',
        stagePadding: 6,
        steps: steps.map((step, index) => ({
            element: () => {
                const target = tourTarget(resolved[index]);

                if (!target) {
                    warnMissing(resolved[index]);
                }

                return target as Element;
            },
            popover: {
                title: step.title ?? '',
                description: step.description ?? '',
                ...(step.side ? { side: step.side } : {}),
                ...(step.align ? { align: step.align } : {}),
            },
        })),
        // Moving between steps goes through go(), which opens the tab or
        // wizard step holding the next target first. "Done" on the last
        // step lands here too, and closes the tour.
        onNextClick: () => void go((instance.getActiveIndex() ?? -1) + 1, 1),
        onPrevClick: () => void go((instance.getActiveIndex() ?? 1) - 1, -1),
        // Fires once per tour teardown (finished OR dismissed) — both count
        // as "seen" so the tour doesn't nag on every visit.
        onDestroyed: () => {
            tallTarget?.remove();
            tallTarget = null;
            activeDriver = null;
            store.markSeen(tour.id);
            store.stop();
        },
    });

    /**
     * Show step `index`, walking in `direction` past optional steps whose
     * target never appears. Past the end, the tour is done.
     */
    async function go(index: number, direction: 1 | -1): Promise<void> {
        for (let i = index; i >= 0 && i < steps.length; i += direction) {
            const selector = await prepare(steps[i]);

            if (selector === null && steps[i].optional) {
                continue;
            }

            resolved[i] = selector ?? steps[i].selector;

            if (activeDriver !== instance) {
                return;
            }

            if (instance.getActiveIndex() === undefined) {
                instance.drive(i);
            } else {
                instance.moveTo(i);
            }

            return;
        }

        if (direction === 1) {
            instance.destroy();
        }
    }

    activeDriver = instance;
    await go(0, 1);
}

function autoStart(): void {
    if (pending) {
        clearTimeout(pending);
    }

    pending = setTimeout(() => {
        const match = store.matchFor(
            String(page.component ?? ''),
            window.location.pathname,
        );

        if (match && !activeDriver) {
            void run(match);
        }
    }, START_DELAY);
}

onMounted(autoStart);
watch(
    () => page.component,
    () => void nextTick(autoStart),
);

// Manual launches via the store (help menu, replay buttons).
watch(
    () => store.activeTourId,
    (id) => {
        if (id === null) {
            return;
        }

        const tour = store.find(id);

        if (tour) {
            void nextTick(() => void run(tour));
        }
    },
);

onBeforeUnmount(() => {
    if (pending) {
        clearTimeout(pending);
    }

    activeDriver?.destroy();
});
</script>

<template>
    <!-- Renderless: driver.js owns the overlay/popover DOM. -->
    <slot />
</template>

<style>
/*
 * Product-tour popover (driver.js), shadcn-themed. Ships WITH the component
 * (not kinetix.css) so every host that mounts <KinetixTours /> gets it, and
 * colors resolve through the Tailwind-level `--color-*` variables — complete
 * colors in BOTH token conventions (HSL triplets via kinetix.css's @theme,
 * full colors in shadcn starter kits), so the popover follows the ACTIVE
 * theme: flip `html.dark` and every token below shifts with it. Raw
 * `var(--popover)` must not be used here — on triplet hosts it produces an
 * invalid color that is silently dropped, leaving driver.js's white defaults
 * even in dark mode.
 *
 * Scoped by `popoverClass: 'kinetix-tour-popover'`, so a host using driver.js
 * for its own purposes is unaffected.
 */
.driver-popover.kinetix-tour-popover {
    background-color: var(--color-popover, #fff);
    color: var(--color-popover-foreground, #09090b);
    border: 1px solid var(--color-border, #e4e4e7);
    border-radius: calc(var(--radius, 0.625rem) + 4px);
    box-shadow:
        0 4px 6px -1px rgb(0 0 0 / 0.07),
        0 10px 15px -3px rgb(0 0 0 / 0.08);
    padding: 1rem;
    max-width: 20rem;
}
.driver-popover.kinetix-tour-popover .driver-popover-title {
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25rem;
    color: inherit;
}
.driver-popover.kinetix-tour-popover .driver-popover-description {
    margin-top: 0.25rem;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: var(--color-muted-foreground, #71717a);
}
.driver-popover.kinetix-tour-popover .driver-popover-progress-text {
    font-size: 0.6875rem;
    color: var(--color-muted-foreground, #71717a);
}
.driver-popover.kinetix-tour-popover .driver-popover-footer {
    margin-top: 0.75rem;
    gap: 0.375rem;
}
.driver-popover.kinetix-tour-popover .driver-popover-footer button {
    all: unset;
    box-sizing: border-box;
    cursor: pointer;
    font-size: 0.8125rem;
    font-weight: 500;
    line-height: 1;
    padding: 0.4375rem 0.75rem;
    border-radius: calc(var(--radius, 0.625rem) - 2px);
    border: 1px solid var(--color-border, #e4e4e7);
    background-color: var(--color-background, #fff);
    color: var(--color-foreground, #09090b);
    text-shadow: none;
    transition: background-color 120ms ease;
}
.driver-popover.kinetix-tour-popover .driver-popover-footer button:hover {
    background-color: var(--color-accent, #f4f4f5);
}
.driver-popover.kinetix-tour-popover .driver-popover-next-btn {
    background-color: var(--color-primary, #18181b);
    border-color: var(--color-primary, #18181b);
    color: var(--color-primary-foreground, #fafafa);
}
.driver-popover.kinetix-tour-popover .driver-popover-next-btn:hover {
    background-color: color-mix(
        in srgb,
        var(--color-primary, #18181b) 90%,
        transparent
    );
}
.driver-popover.kinetix-tour-popover .driver-popover-close-btn {
    color: var(--color-muted-foreground, #71717a);
}
.driver-popover.kinetix-tour-popover .driver-popover-close-btn:hover {
    color: var(--color-foreground, #09090b);
}
.driver-popover.kinetix-tour-popover .driver-popover-arrow-side-top {
    border-top-color: var(--color-popover, #fff);
}
.driver-popover.kinetix-tour-popover .driver-popover-arrow-side-bottom {
    border-bottom-color: var(--color-popover, #fff);
}
.driver-popover.kinetix-tour-popover .driver-popover-arrow-side-left {
    border-left-color: var(--color-popover, #fff);
}
.driver-popover.kinetix-tour-popover .driver-popover-arrow-side-right {
    border-right-color: var(--color-popover, #fff);
}
</style>
