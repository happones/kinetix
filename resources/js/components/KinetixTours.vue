<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixToursStore } from '@/stores/kinetixTours';
import type { KinetixTourData } from '@/types/kinetix';

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
let activeDriver: { destroy: () => void } | null = null;
let warnedMissing = false;

/** Delay (ms) before auto-starting, so the page's DOM settles first. */
const START_DELAY = 400;
let pending: ReturnType<typeof setTimeout> | null = null;

async function run(tour: KinetixTourData): Promise<void> {
    let driverFactory: (config: Record<string, unknown>) => {
        drive: () => void;
        destroy: () => void;
    };

    try {
        // Lazy import keeps driver.js code-split AND optional — apps that
        // never enable tours don't ship or need it.
        const module = await import('driver.js');
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

    const instance = driverFactory({
        showProgress: tour.steps.length > 1,
        progressText: t('kinetix.tour_progress', {
            current: '{{current}}',
            total: '{{total}}',
        }),
        nextBtnText: t('kinetix.tour_next'),
        prevBtnText: t('kinetix.tour_previous'),
        doneBtnText: t('kinetix.tour_done'),
        popoverClass: 'kinetix-tour-popover',
        stagePadding: 6,
        steps: tour.steps.map((step) => ({
            element: step.selector,
            popover: {
                title: step.title ?? '',
                description: step.description ?? '',
                ...(step.side ? { side: step.side } : {}),
                ...(step.align ? { align: step.align } : {}),
            },
        })),
        // Fires once per tour teardown (finished OR dismissed) — both count
        // as "seen" so the tour doesn't nag on every visit.
        onDestroyed: () => {
            activeDriver = null;
            store.markSeen(tour.id);
            store.stop();
        },
    });

    activeDriver = instance;
    instance.drive();
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
