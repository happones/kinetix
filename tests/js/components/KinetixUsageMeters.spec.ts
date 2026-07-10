import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixUsageMeters from '@/components/KinetixUsageMeters.vue';
import type { KinetixUsageMetricData } from '@/types';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                billing_usage_title: 'Usage this period',
                billing_usage_over_limit:
                    "You've reached the included limit for this usage.",
            },
        },
    },
});

const metric = (
    overrides: Partial<KinetixUsageMetricData> = {},
): KinetixUsageMetricData => ({
    key: 'api_calls',
    label: 'API calls',
    used: 1234,
    limit: 5000,
    percent: 25,
    display: '1,234 / 5,000 calls',
    unit: 'calls',
    color: 'primary',
    overLimit: false,
    ...overrides,
});

describe('KinetixUsageMeters', () => {
    it('renders nothing when there are no metrics', () => {
        const w = mount(KinetixUsageMeters, {
            props: { metrics: [] },
            global: { plugins: [i18n] },
        });

        expect(w.html()).toBe('<!--v-if-->');
    });

    it('renders one bar per metric with the resolved fill color and width', () => {
        const w = mount(KinetixUsageMeters, {
            props: {
                metrics: [
                    metric(),
                    metric({
                        key: 'seats',
                        label: 'Seats',
                        color: 'warning',
                        percent: 85,
                    }),
                ],
            },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('Usage this period');
        expect(w.text()).toContain('API calls');
        expect(w.text()).toContain('1,234 / 5,000 calls');
        expect(w.text()).toContain('Seats');

        expect(w.html()).toContain('width: 25%');
        expect(w.html()).toContain('width: 85%');
        expect(w.html()).toContain('bg-primary');
        expect(w.html()).toContain('bg-warning');
    });

    it('shows the over-limit hint only for metrics that are over', () => {
        const w = mount(KinetixUsageMeters, {
            props: {
                metrics: [
                    metric({ overLimit: false }),
                    metric({
                        key: 'storage',
                        label: 'Storage',
                        color: 'danger',
                        percent: 100,
                        overLimit: true,
                    }),
                ],
            },
            global: { plugins: [i18n] },
        });

        const hints = w
            .findAll('p')
            .filter((p) =>
                p.text().includes("You've reached the included limit"),
            );
        expect(hints).toHaveLength(1);
    });

    it('accepts a custom title', () => {
        const w = mount(KinetixUsageMeters, {
            props: { metrics: [metric()], title: 'Plan usage' },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('Plan usage');
        expect(w.text()).not.toContain('Usage this period');
    });
});
