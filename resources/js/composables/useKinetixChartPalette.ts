import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Resolves the categorical chart series colors from the theme's `--chart-N`
 * tokens (shadcn convention, extended by Kinetix to 8 slots in `kinetix.css`),
 * falling back to Kinetix's validated palettes when a token is not defined.
 *
 * Tokens may hold an HSL triplet (`221.2 83.2% 53.3%`, the kinetix.css style)
 * or a complete color (`oklch(…)` / `#2563eb`, the shadcn starter-kit style);
 * both resolve to a concrete color string because SVG `fill`/`stroke`
 * attributes cannot dereference `var()` themselves.
 *
 * A single shared MutationObserver watches `html.class`, so palettes update
 * live when dark mode toggles — regardless of which toggle flipped it.
 */

export const CHART_SLOT_COUNT = 8;

/** Validated fallbacks (adjacent-pair CVD separation + ≥3:1 surface contrast). */
const LIGHT_FALLBACK = [
    '#2563eb',
    '#059669',
    '#d97706',
    '#7c3aed',
    '#e11d48',
    '#0891b2',
    '#4d7c0f',
    '#c026d3',
];

const DARK_FALLBACK = [
    '#3b82f6',
    '#059669',
    '#d97706',
    '#8b5cf6',
    '#f43f5e',
    '#0891b2',
    '#65a30d',
    '#d946ef',
];

/** An HSL triplet like `221.2 83.2% 53.3%` (needs wrapping in `hsl()`). */
const HSL_TRIPLET = /^[\d.]+(?:deg)?[ ,]/;

function resolvePalette(): string[] {
    if (typeof document === 'undefined') {
        return LIGHT_FALLBACK;
    }

    const root = document.documentElement;
    const fallback = root.classList.contains('dark')
        ? DARK_FALLBACK
        : LIGHT_FALLBACK;
    const styles = getComputedStyle(root);

    return fallback.map((fallbackColor, index) => {
        const token = styles.getPropertyValue(`--chart-${index + 1}`).trim();

        if (!token) {
            return fallbackColor;
        }

        return HSL_TRIPLET.test(token) ? `hsl(${token})` : token;
    });
}

const palette = ref<string[]>(LIGHT_FALLBACK);

let observer: MutationObserver | null = null;
let subscribers = 0;

function startObserving(): void {
    if (observer !== null || typeof document === 'undefined') {
        return;
    }

    observer = new MutationObserver(() => {
        palette.value = resolvePalette();
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

function stopObserving(): void {
    observer?.disconnect();
    observer = null;
}

/**
 * The 8 categorical series colors for the active theme, as concrete color
 * strings, live-updating when dark mode toggles.
 */
export function useKinetixChartPalette(): Readonly<Ref<string[]>> {
    onMounted(() => {
        subscribers += 1;
        palette.value = resolvePalette();
        startObserving();
    });

    onBeforeUnmount(() => {
        subscribers -= 1;

        if (subscribers <= 0) {
            stopObserving();
        }
    });

    return readonly(palette) as Readonly<Ref<string[]>>;
}
