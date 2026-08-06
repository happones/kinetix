/**
 * Responsive resolution for form grids (measured
 * against the FORM's own width via CSS container queries — a two-column grid
 * inside a narrow modal collapses even on a wide viewport).
 *
 * - `columns(2)` (an int) means "2 columns from `lg` up, 1 below" — exactly
 *   the default. `columns(['sm' => 2, 'xl' => 3])` sets explicit
 *   breakpoints; `default` (or `base`) is the below-`sm` value.
 * - `columnSpan(2)` applies at every size, clamped to the columns available at
 *   each breakpoint (a span can never overflow its grid). `'full'` spans the
 *   row; `columnSpan(['default' => 'full', 'lg' => 1])` mixes per breakpoint.
 *
 * Values are emitted as CSS custom properties (one per breakpoint, gaps
 * carried forward), consumed by `@container` rules in KinetixFormSchema —
 * inline vars + static CSS, so Tailwind's JIT never sees a dynamic class.
 */

export const GRID_BREAKPOINTS = [
    'base',
    'sm',
    'md',
    'lg',
    'xl',
    '2xl',
] as const;

export type GridBreakpoint = (typeof GRID_BREAKPOINTS)[number];

export type ResponsiveColumns = Record<GridBreakpoint, number>;

type ColumnsInput =
    | number
    | string
    | Record<string, unknown>
    | null
    | undefined;
type SpanInput = number | string | Record<string, unknown> | null | undefined;

/** All-ones — the root grid and any schema mounted standalone. */
export const SINGLE_COLUMN: ResponsiveColumns = {
    base: 1,
    sm: 1,
    md: 1,
    lg: 1,
    xl: 1,
    '2xl': 1,
};

const normalizeKey = (key: string): GridBreakpoint =>
    key === 'default' ? 'base' : (key as GridBreakpoint);

/**
 * Resolve a `columns` value into a per-breakpoint record, carrying values
 * forward through unspecified breakpoints.
 */
export function resolveColumns(columns: ColumnsInput): ResponsiveColumns {
    if (columns === null || columns === undefined) {
        return SINGLE_COLUMN;
    }

    // An int means "this many columns from lg up".
    if (typeof columns === 'number' || typeof columns === 'string') {
        const count = Math.max(1, Number(columns) || 1);

        return { ...SINGLE_COLUMN, lg: count, xl: count, '2xl': count };
    }

    const byBreakpoint: Partial<Record<GridBreakpoint, number>> = {};

    for (const [key, value] of Object.entries(columns)) {
        const breakpoint = normalizeKey(key);

        if (GRID_BREAKPOINTS.includes(breakpoint)) {
            byBreakpoint[breakpoint] = Math.max(1, Number(value) || 1);
        }
    }

    const resolved = {} as ResponsiveColumns;
    let current = byBreakpoint.base ?? 1;

    for (const breakpoint of GRID_BREAKPOINTS) {
        current = byBreakpoint[breakpoint] ?? current;
        resolved[breakpoint] = current;
    }

    return resolved;
}

/** The CSS variables a grid element carries (`--kx-cols-*`). */
export function gridColumnVars(
    columns: ResponsiveColumns,
): Record<string, string> {
    const vars: Record<string, string> = {};

    for (const breakpoint of GRID_BREAKPOINTS) {
        vars[`--kx-cols-${breakpoint}`] = String(columns[breakpoint]);
    }

    return vars;
}

/**
 * The CSS variables a grid CHILD carries (`--kx-span-*`), resolved against
 * the parent's columns so a span never overflows the grid at any width.
 */
export function spanVars(
    span: SpanInput,
    parentColumns: ResponsiveColumns,
): Record<string, string> {
    const byBreakpoint: Partial<Record<GridBreakpoint, number | 'full'>> = {};

    if (span !== null && span !== undefined && typeof span === 'object') {
        for (const [key, value] of Object.entries(span)) {
            const breakpoint = normalizeKey(key);

            if (!GRID_BREAKPOINTS.includes(breakpoint)) {
                continue;
            }

            byBreakpoint[breakpoint] =
                value === 'full' ? 'full' : Math.max(1, Number(value) || 1);
        }
    } else if (span === 'full') {
        byBreakpoint.base = 'full';
    } else {
        byBreakpoint.base = Math.max(1, Number(span) || 1);
    }

    const vars: Record<string, string> = {};
    let current: number | 'full' = byBreakpoint.base ?? 1;

    for (const breakpoint of GRID_BREAKPOINTS) {
        current = byBreakpoint[breakpoint] ?? current;

        if (current === 'full') {
            vars[`--kx-span-${breakpoint}`] = '1 / -1';

            continue;
        }

        const clamped = Math.min(current, parentColumns[breakpoint]);
        vars[`--kx-span-${breakpoint}`] = `span ${clamped} / span ${clamped}`;
    }

    return vars;
}
