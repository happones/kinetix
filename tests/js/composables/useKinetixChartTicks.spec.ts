import { describe, expect, it } from 'vitest';
import { kinetixChartTickValues } from '@/composables/useKinetixChartTicks';

const days = (count: number): number[] =>
    Array.from({ length: count }, (_v, index) => index);

describe('kinetixChartTickValues', () => {
    it('labels every point while they fit', () => {
        expect(kinetixChartTickValues(days(7), 700)).toEqual(days(7));
    });

    it('labels every Nth point of a long series so they do not overlap', () => {
        // 700px holds 9 labels: 30 points → every 4th.
        expect(kinetixChartTickValues(days(30), 700)).toEqual([
            0, 4, 8, 12, 16, 20, 24, 28,
        ]);
    });

    it('keeps at least two labels on a phone', () => {
        expect(kinetixChartTickValues(days(90), 100)).toEqual([0, 45]);
    });

    it('keeps every label before the chart is measured', () => {
        expect(kinetixChartTickValues(days(30), 0)).toEqual(days(30));
    });
});
