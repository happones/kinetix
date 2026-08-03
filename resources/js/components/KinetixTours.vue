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
