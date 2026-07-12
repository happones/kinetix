import { describe, expect, it } from 'vitest';
import {
    computeMasonryLayout,
    gapToPx,
    packIntoColumns,
    resolveResponsiveValue,
} from '@/composables/useMasonryColumns';

describe('useMasonryColumns', () => {
    describe('resolveResponsiveValue', () => {
        it('returns a bare number or string unchanged regardless of viewport', () => {
            expect(resolveResponsiveValue(3, 320)).toBe(3);
            expect(resolveResponsiveValue('1.5rem', 1920)).toBe('1.5rem');
        });

        it('picks the largest matching breakpoint at or below the viewport width', () => {
            const value = { default: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 };

            expect(resolveResponsiveValue(value, 320)).toBe(1);
            expect(resolveResponsiveValue(value, 640)).toBe(2);
            expect(resolveResponsiveValue(value, 900)).toBe(3);
            expect(resolveResponsiveValue(value, 1024)).toBe(4);
            expect(resolveResponsiveValue(value, 1280)).toBe(5);
            expect(resolveResponsiveValue(value, 1536)).toBe(6);
        });

        it('falls back to the next lower breakpoint when one is missing', () => {
            const value = { default: 1, md: 3 };

            expect(resolveResponsiveValue(value, 1024)).toBe(3);
        });

        it('falls back to default (or 1) when no breakpoint matches', () => {
            expect(resolveResponsiveValue({ lg: 4 }, 320)).toBe(1);
            expect(resolveResponsiveValue({ default: 2, lg: 4 }, 320)).toBe(2);
        });
    });

    describe('packIntoColumns', () => {
        it('round-robins ties instead of piling everything into column 0', () => {
            const items = [{ id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }];

            const columns = packIntoColumns(items, 3, {});

            expect(columns.map((c) => c.length)).toEqual([2, 1, 1]);
            expect(columns[0].map((i) => i.id)).toEqual(['a', 'd']);
            expect(columns[1].map((i) => i.id)).toEqual(['b']);
            expect(columns[2].map((i) => i.id)).toEqual(['c']);
        });

        it('places each item into the currently shortest column by measured height', () => {
            const items = [
                { id: 'tall' },
                { id: 'short-1' },
                { id: 'short-2' },
            ];
            const heights = { tall: 300, 'short-1': 50, 'short-2': 50 };

            const columns = packIntoColumns(items, 2, heights);

            expect(columns[0].map((i) => i.id)).toEqual(['tall']);
            expect(columns[1].map((i) => i.id)).toEqual(['short-1', 'short-2']);
        });

        it('treats missing heights as 0', () => {
            const items = [{ id: 1 }, { id: 2 }];

            const columns = packIntoColumns(items, 2, { 1: 100 });

            expect(columns[0].map((i) => i.id)).toEqual([1]);
            expect(columns[1].map((i) => i.id)).toEqual([2]);
        });

        it('clamps columnCount to at least 1', () => {
            const items = [{ id: 'a' }, { id: 'b' }];

            const columns = packIntoColumns(items, 0, {});

            expect(columns).toHaveLength(1);
            expect(columns[0].map((i) => i.id)).toEqual(['a', 'b']);
        });

        it('returns an empty column array for no items', () => {
            const columns = packIntoColumns([], 3, {});

            expect(columns).toEqual([[], [], []]);
        });
    });

    describe('gapToPx', () => {
        it('returns a bare number unchanged', () => {
            expect(gapToPx(24)).toBe(24);
        });

        it('parses px strings', () => {
            expect(gapToPx('24px')).toBe(24);
        });

        it('converts rem/em to px assuming a 16px root font size', () => {
            expect(gapToPx('1.5rem')).toBe(24);
            expect(gapToPx('1em')).toBe(16);
        });

        it('falls back to 0 for an unparseable value', () => {
            expect(gapToPx('auto')).toBe(0);
        });
    });

    describe('computeMasonryLayout', () => {
        it('positions items sequentially within each column, accumulating height + gap', () => {
            const columns = [[{ id: 'a' }, { id: 'b' }], [{ id: 'c' }]];
            const heights = { a: 100, b: 50, c: 80 };

            const { positions, containerHeight } = computeMasonryLayout(
                columns,
                heights,
                10,
            );

            expect(positions.a).toEqual({ column: 0, top: 0 });
            expect(positions.b).toEqual({ column: 0, top: 110 });
            expect(positions.c).toEqual({ column: 1, top: 0 });
            // Column 0: 100 + 10 + 50 = 160 (no trailing gap). Column 1: 80.
            expect(containerHeight).toBe(160);
        });

        it('treats missing heights as 0 when computing offsets', () => {
            const columns = [[{ id: 'a' }, { id: 'b' }]];

            const { positions } = computeMasonryLayout(columns, {}, 8);

            expect(positions.a).toEqual({ column: 0, top: 0 });
            expect(positions.b).toEqual({ column: 0, top: 8 });
        });

        it('returns a zero container height for empty columns', () => {
            const { positions, containerHeight } = computeMasonryLayout(
                [[], []],
                {},
                10,
            );

            expect(positions).toEqual({});
            expect(containerHeight).toBe(0);
        });
    });
});
