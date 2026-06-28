import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn() } }));

import KinetixPeriodFilter from '@/components/KinetixPeriodFilter.vue';
import { resolvePeriodRange } from '@/composables/useKinetixPeriod';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                period_7d: 'Last 7 days',
                period_30d: 'Last 30 days',
                period_90d: 'Last 3 months',
            },
        },
    },
});

describe('KinetixPeriodFilter', () => {
    it('renders a segmented button per period and emits on click', async () => {
        const w = mount(KinetixPeriodFilter, {
            props: { modelValue: '30d', periods: ['7d', '30d', '90d'] },
            global: { plugins: [i18n] },
        });

        const buttons = w.findAll('button');
        expect(buttons).toHaveLength(3);
        expect(w.text()).toContain('Last 7 days');

        await buttons[0].trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['7d']);
        expect(w.emitted('change')?.[0]).toEqual(['7d']);
    });

    it('does not emit when clicking the active period', async () => {
        const w = mount(KinetixPeriodFilter, {
            props: { modelValue: '7d', periods: ['7d', '30d'] },
            global: { plugins: [i18n] },
        });

        await w.findAll('button')[0].trigger('click');
        expect(w.emitted('update:modelValue')).toBeUndefined();
    });
});

describe('resolvePeriodRange', () => {
    it('computes a 7-day window ending today', () => {
        const { start, end } = resolvePeriodRange('7d');
        const days =
            (new Date(end!).getTime() - new Date(start!).getTime()) /
            86_400_000;
        expect(Math.round(days)).toBe(6);
    });

    it('returns null bounds for "all"', () => {
        expect(resolvePeriodRange('all')).toEqual({ start: null, end: null });
    });
});
