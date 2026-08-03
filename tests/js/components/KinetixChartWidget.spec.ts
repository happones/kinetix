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

    it('shows the legend automatically for two or more series', () => {
        const widget = makeWidget({
            chartType: 'line',
            labels: ['Jan', 'Feb'],
            datasets: [
                { label: 'Sales', data: [10, 20] },
                { label: 'Refunds', data: [1, 2] },
            ],
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        const entries = w.findAll('button[aria-pressed]');
        expect(entries).toHaveLength(2);
        expect(w.text()).toContain('Sales');
        expect(w.text()).toContain('Refunds');
    });

    it('hides the legend for a single series unless forced on', () => {
        const single = {
            chartType: 'line',
            labels: ['Jan', 'Feb'],
            datasets: [{ label: 'Sales', data: [10, 20] }],
        };

        const auto = mount(KinetixChartWidget, {
            props: { widget: makeWidget(single) },
            global: { plugins: [i18n] },
        });
        expect(auto.findAll('button[aria-pressed]')).toHaveLength(0);

        const forced = mount(KinetixChartWidget, {
            props: { widget: makeWidget({ ...single, legend: true }) },
            global: { plugins: [i18n] },
        });
        expect(forced.findAll('button[aria-pressed]')).toHaveLength(1);
    });

    it('legend(false) from the server wins over auto', () => {
        const widget = makeWidget({
            chartType: 'line',
            labels: ['Jan'],
            datasets: [
                { label: 'A', data: [1] },
                { label: 'B', data: [2] },
            ],
            legend: false,
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        expect(w.findAll('button[aria-pressed]')).toHaveLength(0);
    });

    it('toggles a series via its legend entry, but never the last one', async () => {
        const widget = makeWidget({
            chartType: 'horizontalBar',
            labels: ['Chrome', 'Safari'],
            datasets: [{ label: 'Usage', data: [62, 25] }],
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        const entries = w.findAll('button[aria-pressed]');
        expect(entries).toHaveLength(2);

        await entries[0].trigger('click');
        expect(entries[0].attributes('aria-pressed')).toBe('false');
        // The hidden category's bar is gone.
        expect(w.text()).not.toContain('62');

        // Hiding the remaining entry is refused — a chart never renders empty.
        await entries[1].trigger('click');
        expect(entries[1].attributes('aria-pressed')).toBe('true');
    });

    it('honors a per-slice backgroundColor array on horizontal bars', () => {
        const widget = makeWidget({
            chartType: 'horizontalBar',
            labels: ['Chrome', 'Safari'],
            datasets: [
                {
                    label: 'Usage',
                    data: [62, 25],
                    backgroundColor: ['rgb(1, 2, 3)', 'rgb(4, 5, 6)'],
                },
            ],
        });

        const w = mount(KinetixChartWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        const html = w.html();
        expect(html).toContain('rgb(1, 2, 3)');
        expect(html).toContain('rgb(4, 5, 6)');
    });
});
