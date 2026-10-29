import { usePage } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixSharedProps,
    KinetixTourData,
    KinetixToursState,
} from '@/types/kinetix';

const STORAGE_PREFIX = 'kinetix.tour.';

/** Opt back in to auto-start under automation (a test of the tour itself). */
const AUTOMATED_OPT_IN_KEY = 'kinetix.tours.automated';
const AUTOMATED_OPT_IN_PARAM = 'kinetix-tours';

/**
 * Whether a test runner drives this browser (Playwright, Selenium, Cypress
 * set `navigator.webdriver`) and hasn't asked for tours: an auto-started
 * tour's overlay would swallow the test's clicks.
 */
export function isAutomatedWithoutTours(): boolean {
    if (typeof navigator === 'undefined' || navigator.webdriver !== true) {
        return false;
    }

    try {
        if (
            new URLSearchParams(window.location.search).get(
                AUTOMATED_OPT_IN_PARAM,
            ) === '1' ||
            localStorage.getItem(AUTOMATED_OPT_IN_KEY) === '1'
        ) {
            return false;
        }
    } catch {
        // Storage blocked: stay skipped.
    }

    return true;
}

/** `*`-wildcard match (e.g. `Kinetix/Posts/*`, `/posts*`). */
function matchesPattern(pattern: string, value: string): boolean {
    const regex = new RegExp(
        '^' +
            pattern
                .split('*')
                .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
                .join('.*') +
            '$',
    );

    return regex.test(value);
}

/**
 * Product-tour state on top of the `kinetix_tours` Inertia share: which tours
 * exist for this user (permission-filtered server-side), which have been seen,
 * and which one is running. "Seen" persists through the configured driver —
 * `local` (browser localStorage, the default) or `database` (per-user via the
 * seen/reset endpoints, survives devices). `<KinetixTours />` renders whatever
 * this store activates; call `start(id)` from anywhere (a help menu, a replay
 * button) for manual launches.
 */
export const useKinetixToursStore = defineStore('kinetix-tours', () => {
    const page = usePage<KinetixSharedProps>();

    const state = computed<KinetixToursState>(
        () =>
            page.props.kinetix_tours ?? {
                enabled: false,
                driver: 'local',
                tours: [],
                seen: [],
            },
    );

    const enabled = computed(() => state.value.enabled === true);
    const tours = computed(() => state.value.tours);

    /** Local mirror so mark/reset reflect immediately without a reload. */
    const seenOverrides = ref<Map<string, boolean>>(new Map());

    /** The tour id `<KinetixTours />` should be running (null = none). */
    const activeTourId = ref<string | null>(null);

    function find(id: string): KinetixTourData | null {
        return tours.value.find((tour) => tour.id === id) ?? null;
    }

    /** What "seen" is stored under: per version (`id@v2`), so a bump re-shows it. */
    function seenKey(id: string): string {
        return find(id)?.seenKey ?? id;
    }

    function hasSeen(id: string): boolean {
        const key = seenKey(id);
        const override = seenOverrides.value.get(key);

        if (override !== undefined) {
            return override;
        }

        if (state.value.driver === 'database') {
            return state.value.seen.includes(key);
        }

        try {
            return localStorage.getItem(STORAGE_PREFIX + key) === '1';
        } catch {
            return false;
        }
    }

    function markSeen(id: string): void {
        const key = seenKey(id);
        seenOverrides.value.set(key, true);

        if (state.value.driver === 'database') {
            void kinetixFetch(
                `/${kinetixRoutePrefix(page)}/tours/${encodeURIComponent(id)}/seen`,
                { method: 'POST' },
            ).catch(() => {
                // Persisting is best-effort; the tour still closed locally.
            });

            return;
        }

        try {
            localStorage.setItem(STORAGE_PREFIX + key, '1');
        } catch {
            // Ignore storage failures (private mode, quota).
        }
    }

    /** Re-arm a tour (a "replay tour" button). */
    function reset(id: string): void {
        const key = seenKey(id);
        seenOverrides.value.set(key, false);

        if (state.value.driver === 'database') {
            void kinetixFetch(
                `/${kinetixRoutePrefix(page)}/tours/${encodeURIComponent(id)}/seen`,
                { method: 'DELETE' },
            ).catch(() => {});

            return;
        }

        try {
            localStorage.removeItem(STORAGE_PREFIX + key);
        } catch {
            // ignore
        }
    }

    /** Start a tour by id (ignores seen state — manual launches always run). */
    function start(id: string): void {
        if (enabled.value && find(id) !== null) {
            activeTourId.value = id;
        }
    }

    function stop(): void {
        activeTourId.value = null;
    }

    /** Whether a tour's `page`/`url` pattern matches this page. */
    function matchesPage(
        tour: KinetixTourData,
        component: string,
        path: string,
    ): boolean {
        if (tour.page !== null) {
            return matchesPattern(tour.page, component);
        }

        return tour.url !== null && matchesPattern(tour.url, path);
    }

    /**
     * The unseen auto-start tour matching the current page, if any. `page`
     * patterns match the Inertia component name, `url` patterns the path.
     * None under test automation (`skip_when_automated`).
     */
    function matchFor(component: string, path: string): KinetixTourData | null {
        if (!enabled.value) {
            return null;
        }

        if (
            state.value.skipWhenAutomated !== false &&
            isAutomatedWithoutTours()
        ) {
            return null;
        }

        return (
            tours.value.find(
                (tour) =>
                    tour.auto &&
                    !hasSeen(tour.id) &&
                    matchesPage(tour, component, path),
            ) ?? null
        );
    }

    /**
     * The tour for this page, seen or not, auto-start or manual-only — what
     * a "show me around" button replays.
     */
    function tourFor(component: string, path: string): KinetixTourData | null {
        if (!enabled.value) {
            return null;
        }

        return (
            tours.value.find((tour) => matchesPage(tour, component, path)) ??
            null
        );
    }

    return {
        enabled,
        tours,
        activeTourId,
        find,
        hasSeen,
        markSeen,
        reset,
        start,
        stop,
        matchFor,
        tourFor,
    };
});
