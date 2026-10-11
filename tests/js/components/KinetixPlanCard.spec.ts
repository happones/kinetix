import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixPlanCard from '@/components/KinetixPlanCard.vue';
import type { KinetixPlanData } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                billing_featured: 'Popular',
                billing_upgrade: 'Upgrade',
                billing_switch_plan: 'Switch plan',
                billing_per_month: '/ month',
                billing_current_plan: 'Current plan',
            },
        },
    },
});

const plan = (overrides: Partial<KinetixPlanData> = {}): KinetixPlanData => ({
    id: 1,
    name: 'Pro',
    slug: 'pro',
    description: null,
    monthlyPrice: 15,
    yearlyPrice: null,
    features: {},
    highlightedFeatures: [],
    isFeatured: false,
    isFree: false,
    sortOrder: 1,
    trialDays: null,
    ...overrides,
});

const card = (props: Record<string, unknown>) =>
    mount(KinetixPlanCard, {
        props: { plan: plan(), ...props },
        global: { plugins: [i18n], stubs: { KinetixConfirmModal: true } },
    });

describe('KinetixPlanCard', () => {
    it('keeps the cents of a price', () => {
        expect(card({ plan: plan({ monthlyPrice: 14.99 }) }).text()).toContain(
            '$14.99',
        );
    });

    it('names the featured badge', () => {
        expect(card({ plan: plan({ isFeatured: true }) }).text()).toContain(
            'Popular',
        );
    });

    it('calls a cheaper plan a switch, not an upgrade', () => {
        expect(card({ currentPrice: 49 }).find('button').text()).toBe(
            'Switch plan',
        );
        expect(card({ currentPrice: 0 }).find('button').text()).toBe('Upgrade');
    });

    it('asks before moving to a free plan', async () => {
        const w = card({ plan: plan({ isFree: true, monthlyPrice: 0 }) });

        await w.find('button').trigger('click');

        expect(w.emitted('subscribe')).toBeUndefined();
    });
});
