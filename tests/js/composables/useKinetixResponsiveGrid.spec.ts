import { describe, expect, it } from 'vitest';
import {
    gridColumnVars,
    resolveColumns,
    SINGLE_COLUMN,
    spanVars,
} from '@/composables/useKinetixResponsiveGrid';

describe('resolveColumns', () => {
    it('treats an int as "N columns from lg up" (Filament parity)', () => {
        expect(resolveColumns(2)).toEqual({
            base: 1,
            sm: 1,
            md: 1,
            lg: 2,
            xl: 2,
            '2xl': 2,
        });
    });

    it('carries breakpoint values forward through gaps', () => {
        expect(resolveColumns({ default: 1, sm: 2, xl: 3 })).toEqual({
            base: 1,
            sm: 2,
            md: 2,
            lg: 2,
            xl: 3,
            '2xl': 3,
        });
    });

    it('falls back to a single column for null', () => {
        expect(resolveColumns(null)).toEqual(SINGLE_COLUMN);
    });
});

describe('spanVars', () => {
    it('clamps an int span to the columns available per breakpoint', () => {
        const cols = resolveColumns({ default: 1, sm: 2, xl: 3 });
        const vars = spanVars(2, cols);

        expect(vars['--kx-span-base']).toBe('span 1 / span 1');
        expect(vars['--kx-span-sm']).toBe('span 2 / span 2');
        expect(vars['--kx-span-xl']).toBe('span 2 / span 2');
    });

    it('renders full as a row-spanning track', () => {
        const vars = spanVars('full', SINGLE_COLUMN);

        expect(vars['--kx-span-base']).toBe('1 / -1');
        expect(vars['--kx-span-2xl']).toBe('1 / -1');
    });

    it('supports per-breakpoint maps mixing ints and full', () => {
        const cols = resolveColumns({ default: 2 });
        const vars = spanVars({ default: 'full', lg: 1 }, cols);

        expect(vars['--kx-span-base']).toBe('1 / -1');
        expect(vars['--kx-span-md']).toBe('1 / -1');
        expect(vars['--kx-span-lg']).toBe('span 1 / span 1');
    });
});

describe('gridColumnVars', () => {
    it('emits one var per breakpoint', () => {
        const vars = gridColumnVars(resolveColumns({ sm: 2 }));

        expect(vars['--kx-cols-base']).toBe('1');
        expect(vars['--kx-cols-sm']).toBe('2');
        expect(vars['--kx-cols-2xl']).toBe('2');
    });
});
