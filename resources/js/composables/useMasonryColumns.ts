/**
 * Pure helpers behind `<KinetixMasonryColumns>` — kept outside the SFC so the
 * packing algorithm is unit-testable without mounting a component (jsdom has
 * no real layout engine, so a `ResizeObserver`-driven height never fires in
 * tests; these functions take heights as plain data instead).
 */

export const MASONRY_BREAKPOINTS = [
    { key: '2xl', width: 1536 },
    { key: 'xl', width: 1280 },
    { key: 'lg', width: 1024 },
    { key: 'md', width: 768 },
    { key: 'sm', width: 640 },
] as const;

/** Resolve a bare value or a `{default, sm, md, lg, xl, '2xl'}` map for the given viewport width. */
export function resolveResponsiveValue(
    value: number | string | Record<string, number | string>,
    viewportWidth: number,
): number | string {
    if (typeof value === 'number' || typeof value === 'string') {
        return value;
    }

    for (const bp of MASONRY_BREAKPOINTS) {
        if (viewportWidth >= bp.width && value[bp.key] !== undefined) {
            return value[bp.key];
        }
    }

    return value.default ?? 1;
}

/**
 * Resolve a CSS length (`'1.5rem'`, `'24px'`, or a bare number treated as
 * px) to a px number, for use in JS layout math (masonry vertical offsets).
 * `rem`/`em` assume the default 16px root font-size — the one case this
 * gets wrong is an app that overrides `html { font-size }` away from 16px.
 */
export function gapToPx(gap: string | number): number {
    if (typeof gap === 'number') {
        return gap;
    }

    const trimmed = gap.trim();

    if (trimmed.endsWith('rem') || trimmed.endsWith('em')) {
        return parseFloat(trimmed) * 16;
    }

    return parseFloat(trimmed) || 0;
}

/**
 * Greedily distributes `items` into `columnCount` columns, appending each to
 * whichever column currently has the smallest total height. Missing heights
 * default to 0. The search rotates its starting point after every placement
 * (rather than always starting from column 0), so ties round-robin evenly —
 * important for the all-unmeasured case before the first `ResizeObserver`
 * report, which would otherwise pile every item into column 0.
 */
export function packIntoColumns<T extends { id: string | number }>(
    items: T[],
    columnCount: number,
    heights: Record<string, number>,
): T[][] {
    const n = Math.max(1, columnCount);
    const columns: T[][] = Array.from({ length: n }, () => []);
    const columnHeights = new Array(n).fill(0);
    let cursor = 0;

    for (const item of items) {
        let target = cursor;

        for (let step = 1; step < n; step++) {
            const i = (cursor + step) % n;

            if (columnHeights[i] < columnHeights[target]) {
                target = i;
            }
        }

        columns[target].push(item);
        columnHeights[target] += heights[String(item.id)] ?? 0;
        cursor = (target + 1) % n;
    }

    return columns;
}

/**
 * Turns packed `columns` into a per-item `{ column, top }` position (in px,
 * relative to a shared container) plus the container's overall height.
 *
 * Rendering every item in a single flat, stably-keyed list and repositioning
 * it via these coordinates — rather than re-parenting it into a different
 * column's `v-for` — is what keeps a widget's DOM node (and its
 * `ResizeObserver`) alive across re-packs. Moving a node between two
 * independent `v-for` arrays would unmount/remount it, which for any widget
 * with async-rendered content (charts, images) restarts its render and can
 * make its measured height chronically wrong — the column it's assigned to
 * then looks artificially short forever, so the greedy packer keeps piling
 * more items onto it.
 */
export function computeMasonryLayout<T extends { id: string | number }>(
    columns: T[][],
    heights: Record<string, number>,
    gapPx: number,
): {
    positions: Record<string, { column: number; top: number }>;
    containerHeight: number;
} {
    const positions: Record<string, { column: number; top: number }> = {};
    let containerHeight = 0;

    columns.forEach((column, columnIndex) => {
        let y = 0;

        column.forEach((item) => {
            positions[String(item.id)] = { column: columnIndex, top: y };
            y += (heights[String(item.id)] ?? 0) + gapPx;
        });

        containerHeight = Math.max(containerHeight, y - gapPx);
    });

    return { positions, containerHeight: Math.max(0, containerHeight) };
}
