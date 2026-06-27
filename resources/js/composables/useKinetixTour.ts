import { computed, ref } from 'vue';

/** A single product-tour step pointing at an element on the page. */
export interface KinetixTourStep {
    /** CSS selector for the element to highlight. */
    target: string;
    title: string;
    description?: string;
}

const STORAGE_PREFIX = 'kinetix.tour.';

/**
 * Lightweight, dependency-free product tour state. Tracks the active step and
 * remembers (in localStorage) whether a given tour id has already been seen, so
 * it only auto-starts once. The matching `<KinetixTour>` component renders it.
 */
export function useKinetixTour(id: string, steps: KinetixTourStep[]) {
    const index = ref(0);
    const active = ref(false);

    const current = computed<KinetixTourStep | null>(
        () => steps[index.value] ?? null,
    );
    const isFirst = computed(() => index.value === 0);
    const isLast = computed(() => index.value === steps.length - 1);

    function hasSeen(): boolean {
        try {
            return localStorage.getItem(STORAGE_PREFIX + id) === '1';
        } catch {
            return false;
        }
    }

    function markSeen(): void {
        try {
            localStorage.setItem(STORAGE_PREFIX + id, '1');
        } catch {
            // Ignore storage failures (private mode, quota) — the tour still works.
        }
    }

    function start(): void {
        if (steps.length === 0) {
            return;
        }

        index.value = 0;
        active.value = true;
    }

    /** Start only if this tour has never been completed/skipped before. */
    function startOnce(): void {
        if (!hasSeen()) {
            start();
        }
    }

    function next(): void {
        if (isLast.value) {
            finish();

            return;
        }

        index.value++;
    }

    function prev(): void {
        if (!isFirst.value) {
            index.value--;
        }
    }

    function finish(): void {
        active.value = false;
        markSeen();
    }

    function skip(): void {
        finish();
    }

    /** Clear the "seen" flag so the tour can run again (e.g. a "replay" button). */
    function reset(): void {
        try {
            localStorage.removeItem(STORAGE_PREFIX + id);
        } catch {
            // ignore
        }
    }

    return {
        index,
        active,
        current,
        isFirst,
        isLast,
        steps,
        start,
        startOnce,
        next,
        prev,
        finish,
        skip,
        reset,
        hasSeen,
    };
}
