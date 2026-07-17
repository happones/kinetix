import { describe, expect, it } from 'vitest';
import { useKinetixColumnVisibility } from '@/composables/useKinetixColumnVisibility';

const col = (name: string, hiddenByDefault = false) =>
    ({
        name,
        label: name,
        isToggledHiddenByDefault: hiddenByDefault,
    }) as any;

describe('useKinetixColumnVisibility', () => {
    it('seeds visibility from isToggledHiddenByDefault', () => {
        const { columnsToRender, isColumnVisible } = useKinetixColumnVisibility(
            () => [col('a'), col('b', true), col('c')],
        );

        expect(isColumnVisible('a')).toBe(true);
        expect(isColumnVisible('b')).toBe(false);
        expect(columnsToRender.value.map((c) => c.name)).toEqual(['a', 'c']);
    });

    it('toggles a column on and off', () => {
        const { toggleColumn, isColumnVisible } = useKinetixColumnVisibility(
            () => [col('a'), col('b')],
        );

        toggleColumn('a');
        expect(isColumnVisible('a')).toBe(false);

        toggleColumn('a');
        expect(isColumnVisible('a')).toBe(true);
    });

    it('never hides the final visible column', () => {
        const { toggleColumn, isColumnVisible, columnsToRender } =
            useKinetixColumnVisibility(() => [col('a'), col('b')]);

        toggleColumn('a');
        // b is the last one standing — toggling it off is a no-op.
        toggleColumn('b');

        expect(isColumnVisible('b')).toBe(true);
        expect(columnsToRender.value).toHaveLength(1);
    });
});
