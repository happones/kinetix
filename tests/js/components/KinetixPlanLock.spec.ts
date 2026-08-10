import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
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

import KinetixPlanLock from '@/components/KinetixPlanLock.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                close: 'Close',
                plan_locked_title: 'Upgrade required',
                plan_locked_body:
                    'This feature is not included in your current plan.',
                plan_locked_feature:
                    'The "{feature}" feature is not included in your current plan.',
                plan_locked_hint: 'Locked — upgrade required',
                plan_upgrade: 'Upgrade plan',
                plan_upgrade_modal_title: 'Upgrade to unlock',
                plan_upgrade_modal_body:
                    'This feature is available on our premium plans. Upgrade your subscription to unlock it.',
                plan_upgrade_dismiss: 'Maybe later',
            },
        },
    },
});

const billing = (
    features: Record<string, unknown>,
    extra: Record<string, unknown> = {},
) => ({
    enabled: true,
    plan: { slug: 'free', name: 'Free', features },
    upgradeUrl: '/billing',
    ...extra,
});

const mountLock = (
    props: Record<string, any> = {},
    slots: Record<string, any> = {},
) =>
    mount(KinetixPlanLock, {
        props,
        slots: { default: '<p>Feature content</p>', ...slots },
        global: { plugins: [i18n] },
    });

describe('KinetixPlanLock', () => {
    it('renders the slot untouched when the plan grants the capability', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: true } });

        const wrapper = mountLock({ feature: 'capabilities.api' });

        expect(wrapper.text()).toContain('Feature content');
        expect(wrapper.text()).not.toContain('Upgrade required');
    });

    it('locks a denied capability behind the card by default', async () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock({ feature: 'capabilities.api' });

        expect(wrapper.text()).not.toContain('Feature content');
        expect(wrapper.text()).toContain('Upgrade required');

        await wrapper.find('button').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Upgrade to unlock');
        expect(document.body.querySelector('a')?.getAttribute('href')).toBe(
            '/billing',
        );

        wrapper.unmount();
        document.body.innerHTML = '';
    });

    it('locks a usage limit that has been reached', () => {
        pageProps.kinetix_billing = billing({ usage: { projects: 2 } });

        expect(
            mountLock({ limit: 'usage.projects', count: 2 }).text(),
        ).toContain('Upgrade required');
        expect(
            mountLock({ limit: 'usage.projects', count: 1 }).text(),
        ).toContain('Feature content');
    });

    it('names the feature in the default copy', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock({
            feature: 'capabilities.api',
            featureName: 'API access',
        });

        expect(wrapper.text()).toContain(
            'The "API access" feature is not included in your current plan.',
        );
    });

    it('locks unconditionally when no feature or limit is given', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: true } });

        const wrapper = mountLock(
            { variant: 'banner', featureName: 'Real-time alerts' },
            { default: '' },
        );

        expect(wrapper.text()).toContain('Upgrade required');
        expect(wrapper.text()).toContain('Real-time alerts');
    });

    it('keeps the content visible but inert behind the overlay', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock({
            feature: 'capabilities.api',
            variant: 'overlay',
        });

        const behind = wrapper.find('[aria-hidden="true"]');

        expect(behind.text()).toContain('Feature content');
        expect(behind.attributes('inert')).toBeDefined();
        expect(behind.classes()).toContain('blur-[2px]');
        expect(wrapper.text()).toContain('Upgrade required');
    });

    it('drops the overlay blur when blur is disabled', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock({
            feature: 'capabilities.api',
            variant: 'overlay',
            blur: false,
        });

        expect(wrapper.find('[aria-hidden="true"]').classes()).not.toContain(
            'blur-[2px]',
        );
    });

    it('badges the content with a padlock and swallows its click', async () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock(
            { feature: 'capabilities.api', variant: 'badge' },
            { default: '<a href="/api-tokens">API tokens</a>' },
        );

        expect(wrapper.text()).toContain('API tokens');
        expect(wrapper.text()).toContain('Locked — upgrade required');
        expect(document.body.textContent).not.toContain('Upgrade to unlock');

        await wrapper.find('a').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Upgrade to unlock');

        wrapper.unmount();
        document.body.innerHTML = '';
    });

    it('links straight out instead of opening the modal when modal is off', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock({
            feature: 'capabilities.api',
            modal: false,
        });

        expect(wrapper.find('a').attributes('href')).toBe('/billing');
        expect(wrapper.findComponent({ name: 'KinetixButton' }).exists()).toBe(
            false,
        );
    });

    it('renders no CTA when there is no upgrade url', () => {
        pageProps.kinetix_billing = billing(
            { capabilities: { api: false } },
            {
                upgradeUrl: null,
            },
        );

        const wrapper = mountLock({ feature: 'capabilities.api' });

        expect(wrapper.text()).toContain('Upgrade required');
        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('takes its presentation defaults from the shared lock config', () => {
        pageProps.kinetix_billing = billing(
            { capabilities: { api: false } },
            {
                lock: { variant: 'banner', modal: false, badgeLabel: 'Pro' },
            },
        );

        const wrapper = mountLock({ feature: 'capabilities.api' });

        expect(wrapper.text()).toContain('Pro');
        expect(wrapper.find('a').attributes('href')).toBe('/billing');
    });

    it('lets the locked slot replace the lock entirely', () => {
        pageProps.kinetix_billing = billing({ capabilities: { api: false } });

        const wrapper = mountLock(
            { feature: 'capabilities.api' },
            { locked: '<p>Custom upsell</p>' },
        );

        expect(wrapper.text()).toContain('Custom upsell');
        expect(wrapper.text()).not.toContain('Upgrade required');
    });

    it('locks everything when billing is off (fail-closed)', () => {
        pageProps.kinetix_billing = { enabled: false, plan: null };

        expect(mountLock({ feature: 'capabilities.api' }).text()).toContain(
            'Upgrade required',
        );
    });
});
