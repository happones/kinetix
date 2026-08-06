import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const pageProps: Record<string, any> = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

import KinetixPlanGate from '@/components/KinetixPlanGate.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                plan_locked_title: 'Upgrade required',
                plan_locked_body:
                    'This feature is not included in your current plan.',
                plan_upgrade: 'Upgrade plan',
            },
        },
    },
});

const billing = (
    features: Record<string, unknown>,
    upgradeUrl: string | null = null,
) => ({
    enabled: true,
    plan: { slug: 'free', name: 'Free', features },
    upgradeUrl,
});

const mountGate = (
    props: Record<string, any>,
    slots: Record<string, any> = {},
) =>
    mount(KinetixPlanGate, {
        props,
        slots: { default: '<p>Feature content</p>', ...slots },
        global: { plugins: [i18n] },
    });

describe('KinetixPlanGate', () => {
    it('renders the slot when the plan grants the capability', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: true } });

        const wrapper = mountGate({ feature: 'capabilities.api' });

        expect(wrapper.text()).toContain('Feature content');
        expect(wrapper.text()).not.toContain('Upgrade required');
    });

    it('renders the upsell card with the CTA when denied and an upgrade URL exists', () => {
        pageProps.kinetix_billing = billing(
            { capabilities: { api: false } },
            '/billing',
        );

        const wrapper = mountGate({ feature: 'capabilities.api' });

        expect(wrapper.text()).not.toContain('Feature content');
        expect(wrapper.text()).toContain('Upgrade required');

        const cta = wrapper.find('a[href="/billing"]');
        expect(cta.exists()).toBe(true);
        expect(cta.text()).toContain('Upgrade plan');
    });

    it('omits the CTA without an upgrade URL and honors the #locked slot', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const plain = mountGate({ feature: 'capabilities.api' });
        expect(plain.text()).toContain('Upgrade required');
        expect(plain.find('a').exists()).toBe(false);

        const custom = mountGate(
            { feature: 'capabilities.api' },
            { locked: '<p>Custom upsell</p>' },
        );
        expect(custom.text()).toContain('Custom upsell');
        expect(custom.text()).not.toContain('Upgrade required');
    });

    it('gates on usage limits with the remaining count exposed', () => {
        pageProps.kinetix_billing = billing({ usage: { projects: 2 } });

        const under = mountGate(
            { limit: 'usage.projects', count: 1 },
            {
                default: `<template #default="{ remaining }"><p>Left: {{ remaining }}</p></template>`,
            },
        );
        expect(under.text()).toContain('Left: 1');

        const at = mountGate({ limit: 'usage.projects', count: 2 });
        expect(at.text()).toContain('Upgrade required');
    });
});
