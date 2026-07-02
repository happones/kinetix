import { mount } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixChartWidget from '@/components/KinetixChartWidget.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: { chart_empty: 'No data available' } } },
});

const makeWidget = (data: any) => ({
    id: 'w1',
    type: 'chart' as const,
    title: 'Chart title',
    columnSpan: 12,
    sort: 0,
    data,
});

describe('KinetixChartWidget', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });
    it('renders empty state when there is no data', () => {
        const widget = makeWidget({
            chartType: 'line',
            labels: [],
            datasets: [],
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('Chart title');
        expect(w.text()).toContain('No data available');
    });

    it('renders the chart container when data is present', () => {
        const widget = makeWidget({
            chartType: 'line',
            labels: ['January', 'February'],
            datasets: [
                {
                    label: 'Sales',
                    data: [10, 20],
                },
            ],
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('Chart title');
        expect(w.text()).not.toContain('kinetix.chart_empty');
    });
});
