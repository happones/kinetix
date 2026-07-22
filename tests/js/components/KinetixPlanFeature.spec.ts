import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { pageState } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

import KinetixPlanFeature from '@/components/KinetixPlanFeature.vue';

const sharePlan = (features: Record<string, unknown> | null) => {
    pageState.props = {
        kinetix_billing: {
            enabled: true,
            plan:
                features === null
                    ? null
                    : { slug: 'pro', name: 'Pro', features },
        },
    };
};

describe('KinetixPlanFeature', () => {
    it('renders the default slot when the capability is granted', () => {
        sharePlan({ capabilities: { api: true } });

        const wrapper = mount(KinetixPlanFeature, {
            props: { feature: 'capabilities.api' },
            slots: {
                default: '<span>api menu</span>',
                denied: '<span>upgrade</span>',
            },
        });

        expect(wrapper.text()).toContain('api menu');
        expect(wrapper.text()).not.toContain('upgrade');
    });

    it('renders the denied slot when the capability is missing', () => {
        sharePlan({ capabilities: { api: false } });

        const wrapper = mount(KinetixPlanFeature, {
            props: { feature: 'capabilities.api' },
            slots: {
                default: '<span>api menu</span>',
                denied: '<span>upgrade</span>',
            },
        });

        expect(wrapper.text()).toContain('upgrade');
        expect(wrapper.text()).not.toContain('api menu');
    });

    it('gates on a usage limit and exposes the remaining count', () => {
        sharePlan({ usage: { products: 10 } });

        const under = mount(KinetixPlanFeature, {
            props: { limit: 'usage.products', count: 7 },
            slots: {
                default: `<template #default="{ remaining }"><span>add ({{ remaining }} left)</span></template>`,
                denied: '<span>limit reached</span>',
            },
        });
        expect(under.text()).toContain('add (3 left)');

        const at = mount(KinetixPlanFeature, {
            props: { limit: 'usage.products', count: 10 },
            slots: {
                default: '<span>add</span>',
                denied: '<span>limit reached</span>',
            },
        });
        expect(at.text()).toContain('limit reached');
    });

    it('treats an unlimited plan as always allowed with null remaining', () => {
        sharePlan({ usage: { products: null } });

        const wrapper = mount(KinetixPlanFeature, {
            props: { limit: 'usage.products', count: 9999 },
            slots: {
                default: `<template #default="{ remaining }"><span>add {{ remaining === null ? '(unlimited)' : remaining }}</span></template>`,
            },
        });

        expect(wrapper.text()).toContain('add (unlimited)');
    });

    it('requires both checks when feature and limit are combined', () => {
        sharePlan({ capabilities: { api: true }, usage: { tokens: 5 } });

        const blocked = mount(KinetixPlanFeature, {
            props: {
                feature: 'capabilities.api',
                limit: 'usage.tokens',
                count: 5,
            },
            slots: { default: '<span>ok</span>', denied: '<span>no</span>' },
        });
        expect(blocked.text()).toContain('no');

        const allowed = mount(KinetixPlanFeature, {
            props: {
                feature: 'capabilities.api',
                limit: 'usage.tokens',
                count: 4,
            },
            slots: { default: '<span>ok</span>', denied: '<span>no</span>' },
        });
        expect(allowed.text()).toContain('ok');
    });

    it('denies when no plan resolves', () => {
        sharePlan(null);

        const wrapper = mount(KinetixPlanFeature, {
            props: { feature: 'capabilities.api' },
            slots: { default: '<span>ok</span>', denied: '<span>no</span>' },
        });

        expect(wrapper.text()).toContain('no');
    });
});
