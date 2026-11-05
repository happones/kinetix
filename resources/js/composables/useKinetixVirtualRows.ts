import type * as VirtualEngine from '@tanstack/vue-virtual';
import type { Virtualizer } from '@tanstack/vue-virtual';
import { computed, getCurrentScope, shallowRef, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

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
    /**
     * Whether the list is currently virtualized: over the threshold AND the
     * virtualization engine has loaded (until then every row renders).
     */
    enabled: ComputedRef<boolean>;
    /** The virtual rows to render (empty while disabled). */
    virtualRows: ComputedRef<KinetixVirtualRow[]>;
    /** Total scroll height of all rows, for the spacer element. */
    totalSize: ComputedRef<number>;
    /** Measure a rendered row element (dynamic height support). */
    measureElement: (el: Element | null) => void;
}

let engine: Promise<typeof VirtualEngine> | null = null;

/** The engine, downloaded once for the whole app; a failure lets a later list retry. */
const loadEngine = (): Promise<typeof VirtualEngine> => {
    engine ??= import('@tanstack/vue-virtual').catch((error: unknown) => {
        engine = null;

        throw error;
    });

    return engine;
};

/**
 * Threshold-gated vertical virtualization over `@tanstack/vue-virtual`. Only
 * large lists window their rows; small ones render in full, so there is no
 * overhead — or behavioural change — for the common case.
 *
 * The engine itself downloads the first time a list crosses the threshold, so
 * pages whose lists stay small never fetch it. Until it arrives the list
 * renders every row, exactly as below the threshold. Never on the server.
 *
 * The component owns the item markup and the scroll container; this returns the
 * window (indices + offsets) to render when virtualization kicks in.
 */
export function useKinetixVirtualRows(
    options: UseKinetixVirtualRowsOptions,
): UseKinetixVirtualRows {
    const threshold = options.threshold ?? 40;
    const overThreshold = computed<boolean>(() => options.count() > threshold);
    // The engine's virtualizer once loaded (its own ref triggers on scroll);
    // `ready` flips with it so the computeds below start tracking it.
    let virtualizer: Ref<Virtualizer<HTMLElement, Element>> | null = null;
    const ready = shallowRef(false);
    const enabled = computed<boolean>(() => overThreshold.value && ready.value);

    const scope = getCurrentScope();

    if (scope && typeof window !== 'undefined') {
        let starting = false;

        const stop = watch(
            overThreshold,
            async (isOver) => {
                if (!isOver || starting || ready.value) {
                    return;
                }

                starting = true;

                try {
                    const { useVirtualizer } = await loadEngine();

                    // The component may have unmounted during the download.
                    if (!scope.active) {
                        return;
                    }

                    // Created inside the component's scope so its watchers
                    // and observers are torn down with it.
                    virtualizer =
                        scope.run(() =>
                            useVirtualizer(
                                computed(() => ({
                                    count: overThreshold.value
                                        ? options.count()
                                        : 0,
                                    getScrollElement: options.getScrollElement,
                                    estimateSize: () => options.estimateSize,
                                    overscan: options.overscan ?? 8,
                                })),
                            ),
                        ) ?? null;
                    ready.value = virtualizer !== null;
                    stop();
                } catch (error) {
                    console.warn(
                        '[kinetix] The list virtualizer failed to load; rendering every row.',
                        error,
                    );
                } finally {
                    starting = false;
                }
            },
            { immediate: true },
        );
    }

    const virtualRows = computed<KinetixVirtualRow[]>(() =>
        enabled.value && virtualizer
            ? virtualizer.value.getVirtualItems().map((row) => ({
                  index: row.index,
                  key: row.key as string | number,
                  start: row.start,
                  size: row.size,
              }))
            : [],
    );

    const totalSize = computed<number>(() =>
        enabled.value && virtualizer ? virtualizer.value.getTotalSize() : 0,
    );

    const measureElement = (el: Element | null): void => {
        if (el && virtualizer) {
            virtualizer.value.measureElement(el);
        }
    };

    return { enabled, virtualRows, totalSize, measureElement };
}
