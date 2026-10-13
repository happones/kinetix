import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UnovisChartCanvas from '@/components/widgets/UnovisChartCanvas.vue';

const mountCircular = (arcWidthValue: number) =>
    mount(UnovisChartCanvas, {
        props: {
            chartType: arcWidthValue > 0 ? 'doughnut' : 'pie',
            isCircular: true,
            chartData: [],
            pieData: [{ label: 'A', value: 1 }] as any,
            labels: ['A'],
            stacked: false,
            duration: 0,
            xAccessor: () => undefined,
            yAccessors: [],
            visualAccessors: [],
            lineColors: [],
            areaColors: [],
            groupedBarColors: [],
            pieValueAccessor: () => 1,
            pieLabelAccessor: () => 'A',
            pieColorAccessor: () => 'red',
            arcWidthValue,
            centerValue: '20',
            centerCaption: 'items',
            tooltipTemplate: () => '',
            pieTooltipTemplate: () => '',
        },
        global: {
            stubs: {
                VisSingleContainer: { template: '<div><slot /></div>' },
                VisDonut: true,
                VisTooltip: true,
            },
        },
    });

describe('UnovisChartCanvas', () => {
    it("puts the center label in a donut's hole", () => {
        expect(mountCircular(40).text()).toContain('20');
        expect(mountCircular(40).text()).toContain('items');
    });

    it('leaves a full pie without one, so it never covers the slices', () => {
        expect(mountCircular(0).text()).not.toContain('items');
    });
});
