import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

/** Room a category label needs on the x axis ("Sep 12", "Week 34"…). */
export const KINETIX_CHART_TICK_SPACING = 72;

/**
 * The x values that get a label: every point while they fit, otherwise every
 * Nth one, so a 30- or 90-point series reads as a few dates instead of a smear
 * of overlapping (or line-wrapped) labels. An unmeasured width (0, before
 * layout or during SSR) keeps every label.
 */
export function kinetixChartTickValues(
    xs: number[],
    width: number,
    spacing: number = KINETIX_CHART_TICK_SPACING,
): number[] {
    if (width <= 0 || xs.length === 0) {
        return xs;
    }

    const fits = Math.max(2, Math.floor(width / spacing));
    const step = Math.ceil(xs.length / fits);

    return step <= 1 ? xs : xs.filter((_x, index) => index % step === 0);
}

/**
 * The rendered width of a chart, kept current with a ResizeObserver (the grid
 * reflows a widget without a window resize).
 */
export function useKinetixChartWidth(
    target: Ref<{ $el?: Element } | Element | null>,
): Ref<number> {
    const width = ref(0);
    let observer: ResizeObserver | null = null;

    const element = (): Element | null => {
        const value = target.value;

        return value instanceof Element ? value : (value?.$el ?? null);
    };

    const observe = (): void => {
        observer?.disconnect();
        const el = element();

        if (!el || typeof ResizeObserver === 'undefined') {
            return;
        }

        width.value = el.getBoundingClientRect().width;
        observer = new ResizeObserver(([entry]) => {
            width.value = entry.contentRect.width;
        });
        observer.observe(el);
    };

    onMounted(observe);
    watch(target, observe);
    onBeforeUnmount(() => observer?.disconnect());

    return width;
}
