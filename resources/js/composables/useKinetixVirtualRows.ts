import { useVirtualizer } from '@tanstack/vue-virtual';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

export interface UseKinetixVirtualRowsOptions {
    /** Reactive getter for the total number of rows. */
    count: () => number;
    /** The scroll container element (a template ref). */
    getScrollElement: () => HTMLElement | null;
    /** Estimated row height in px (px used until measured). */
    estimateSize: number;
    /** Rows to render beyond the visible window. */
    overscan?: number;
    /**
     * Only virtualize once the list exceeds this many rows. Below it, the
     * component renders every row directly — zero virtualization overhead for
     * the common small-list case, and DOM-querying tests (which use tiny
     * fixtures) stay on the plain render path.
     */
    threshold?: number;
}

export interface KinetixVirtualRow {
    index: number;
    key: string | number;
    start: number;
    size: number;
}

export interface UseKinetixVirtualRows {
    /** Whether the list is currently virtualized (count > threshold). */
    enabled: ComputedRef<boolean>;
    /** The virtual rows to render (empty while disabled). */
    virtualRows: ComputedRef<KinetixVirtualRow[]>;
    /** Total scroll height of all rows, for the spacer element. */
    totalSize: ComputedRef<number>;
    /** Measure a rendered row element (dynamic height support). */
    measureElement: (el: Element | null) => void;
}

/**
 * Threshold-gated vertical virtualization over `@tanstack/vue-virtual`. Only
 * large lists window their rows; small ones render in full, so there is no
 * overhead — or behavioural change — for the common case.
 *
 * The component owns the item markup and the scroll container; this returns the
 * window (indices + offsets) to render when virtualization kicks in.
 */
export function useKinetixVirtualRows(
    options: UseKinetixVirtualRowsOptions,
): UseKinetixVirtualRows {
    const threshold = options.threshold ?? 40;
    const enabled = computed<boolean>(() => options.count() > threshold);

    const virtualizer = useVirtualizer(
        computed(() => ({
            count: enabled.value ? options.count() : 0,
            getScrollElement: options.getScrollElement,
            estimateSize: () => options.estimateSize,
            overscan: options.overscan ?? 8,
        })),
    );

    const virtualRows = computed<KinetixVirtualRow[]>(() =>
        enabled.value
            ? virtualizer.value.getVirtualItems().map((row) => ({
                  index: row.index,
                  key: row.key as string | number,
                  start: row.start,
                  size: row.size,
              }))
            : [],
    );

    const totalSize = computed<number>(() =>
        enabled.value ? virtualizer.value.getTotalSize() : 0,
    );

    const measureElement = (el: Element | null): void => {
        if (el) {
            virtualizer.value.measureElement(el);
        }
    };

    return { enabled, virtualRows, totalSize, measureElement };
}
