import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Tours reach into tabs and wizard steps: before highlighting a field (or a
 * tab), the tour host broadcasts what it is looking for, and every tab group
 * and wizard on the page opens the child that holds it. Nested groups mount
 * only once their parent opens, so the host repeats the request until the
 * target appears.
 */
export const TOUR_REVEAL_EVENT = 'kinetix:tour-reveal';

export interface TourRevealRequest {
    /** A form field's name (`TourStep::field()`). */
    field?: string;
    /** A tab's key (`TourStep::tab()`). */
    tab?: string;
    /**
     * Set by a listener that can't open what holds the target — a wizard
     * step past one whose required fields are empty — to the selector the
     * tour should highlight instead.
     */
    fallback?: string | null;
}

/** Nodes that repeat their schema per item: their fields aren't revealable. */
const ITEM_LISTS = new Set(['repeater', 'table-repeater']);

/**
 * Whether a schema subtree holds the field or tab the request names.
 */
export function holdsTourTarget(
    nodes: unknown[] | null | undefined,
    request: TourRevealRequest,
): boolean {
    return (nodes ?? []).some((raw) => {
        const node = raw as {
            type?: string;
            name?: string;
            urlKey?: string | null;
            schema?: unknown[];
        };

        if (request.field && node.name === request.field) {
            return true;
        }

        if (request.tab && node.type === 'tab' && node.urlKey === request.tab) {
            return true;
        }

        return (
            Array.isArray(node.schema) &&
            !ITEM_LISTS.has(node.type ?? '') &&
            holdsTourTarget(node.schema, request)
        );
    });
}

/** Ask the page to open what holds a tour target; returns the fallback, if any. */
export function requestTourReveal(request: TourRevealRequest): string | null {
    const detail: TourRevealRequest = { ...request, fallback: null };

    window.dispatchEvent(new CustomEvent(TOUR_REVEAL_EVENT, { detail }));

    return detail.fallback ?? null;
}

/** Answer tour reveal requests while the component is mounted. */
export function onTourReveal(
    handler: (request: TourRevealRequest) => void,
): void {
    const listener = (event: Event) =>
        handler((event as CustomEvent<TourRevealRequest>).detail);

    onMounted(() => window.addEventListener(TOUR_REVEAL_EVENT, listener));
    onBeforeUnmount(() =>
        window.removeEventListener(TOUR_REVEAL_EVENT, listener),
    );
}
