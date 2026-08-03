import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Resolves chart colors from the theme's tokens, falling back to Kinetix's
 * validated palettes when a token is not defined.
 *
 * Tokens may hold an HSL triplet (`221.2 83.2% 53.3%`, the kinetix.css style)
 * or a complete color (`oklch(…)` / `hsl(…)` / `#2563eb`, the shadcn
 * starter-kit style); both resolve to a concrete color string. That
 * normalization is why resolution happens in JS at all: SVG `fill`/`stroke`
 * attributes cannot dereference `var()`, and a CSS-side `hsl(var(--border))`
 * wrapping breaks on complete-color hosts (`hsl(hsl(…))` is invalid and gets
 * dropped silently).
 *
 * A single shared MutationObserver watches `html.class`, so palettes and
 * surface vars update live when dark mode toggles — regardless of which
 * toggle flipped it.
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

/** Normalize a token value of either shape into a complete color string. */
function normalizeToken(value: string): string {
    return HSL_TRIPLET.test(value) ? `hsl(${value})` : value;
}

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

        return normalizeToken(token);
    });
}

/**
 * The unovis chart-surface CSS properties (axis, crosshair, donut strokes),
 * each mapped to a shadcn token plus a light/dark fallback for hosts that
 * define neither kinetix.css nor their own tokens.
 */
const SURFACE_VARS: {
    cssVar: string;
    token: string;
    fallback: [light: string, dark: string];
}[] = [
    {
        cssVar: '--vis-axis-grid-color',
        token: '--border',
        fallback: ['hsl(240 5.9% 90%)', 'hsl(240 3.7% 15.9%)'],
    },
    {
        cssVar: '--vis-axis-tick-color',
        token: '--border',
        fallback: ['hsl(240 5.9% 90%)', 'hsl(240 3.7% 15.9%)'],
    },
    {
        cssVar: '--vis-axis-domain-color',
        token: '--border',
        fallback: ['hsl(240 5.9% 90%)', 'hsl(240 3.7% 15.9%)'],
    },
    {
        cssVar: '--vis-axis-tick-label-color',
        token: '--muted-foreground',
        fallback: ['hsl(240 3.8% 46.1%)', 'hsl(240 5% 64.9%)'],
    },
    {
        cssVar: '--vis-axis-label-color',
        token: '--muted-foreground',
        fallback: ['hsl(240 3.8% 46.1%)', 'hsl(240 5% 64.9%)'],
    },
    {
        cssVar: '--vis-crosshair-line-stroke-color',
        token: '--muted-foreground',
        fallback: ['hsl(240 3.8% 46.1%)', 'hsl(240 5% 64.9%)'],
    },
    {
        cssVar: '--vis-crosshair-circle-stroke-color',
        token: '--background',
        fallback: ['hsl(0 0% 100%)', 'hsl(240 10% 3.9%)'],
    },
    {
        cssVar: '--vis-donut-segment-stroke-color',
        token: '--card',
        fallback: ['hsl(0 0% 100%)', 'hsl(240 10% 3.9%)'],
    },
];

function resolveSurfaceVars(): Record<string, string> {
    const isDark =
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark');
    const styles =
        typeof document !== 'undefined'
            ? getComputedStyle(document.documentElement)
            : null;

    return Object.fromEntries(
        SURFACE_VARS.map(({ cssVar, token, fallback }) => {
            const value = styles?.getPropertyValue(token).trim();

            return [
                cssVar,
                value ? normalizeToken(value) : fallback[isDark ? 1 : 0],
            ];
        }),
    );
}

const palette = ref<string[]>(LIGHT_FALLBACK);
const surfaceVars = ref<Record<string, string>>(resolveSurfaceVars());

let observer: MutationObserver | null = null;
let subscribers = 0;

function refresh(): void {
    palette.value = resolvePalette();
    surfaceVars.value = resolveSurfaceVars();
}

function startObserving(): void {
    if (observer !== null || typeof document === 'undefined') {
        return;
    }

    observer = new MutationObserver(refresh);

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

function stopObserving(): void {
    observer?.disconnect();
    observer = null;
}

function subscribe(): void {
    onMounted(() => {
        subscribers += 1;
        refresh();
        startObserving();
    });

    onBeforeUnmount(() => {
        subscribers -= 1;

        if (subscribers <= 0) {
            stopObserving();
        }
    });
}

/**
 * The 8 categorical series colors for the active theme, as concrete color
 * strings, live-updating when dark mode toggles.
 */
export function useKinetixChartPalette(): Readonly<Ref<string[]>> {
    subscribe();

    return readonly(palette) as Readonly<Ref<string[]>>;
}

/**
 * The unovis `--vis-*` surface properties as an inline-style object, resolved
 * from theme tokens of either shape and live-updating with dark mode. Bound in
 * JS because wrapping in `hsl(var(…))` from CSS silently breaks on hosts whose
 * tokens are complete colors (the shadcn starter-kit convention).
 */
export function useKinetixChartSurfaceVars(): Readonly<
    Ref<Record<string, string>>
> {
    subscribe();

    return readonly(surfaceVars) as Readonly<Ref<Record<string, string>>>;
}
