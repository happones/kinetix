import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
}));

import KinetixCookieConsent from '@/components/KinetixCookieConsent.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                cookie_consent_message:
                    'We use cookies to improve your experience.',
                cookie_consent_policy_link: 'Learn more',
                cookie_consent_accept: 'Accept',
                cookie_consent_decline: 'Decline',
            },
        },
    },
});

function clearCookies(): void {
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0]?.trim();

        if (name) {
            document.cookie = `${name}=;path=/;max-age=0`;
        }
    });
}

const mountIt = () =>
    mount(KinetixCookieConsent, {
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

describe('KinetixCookieConsent', () => {
    beforeEach(() => {
        clearCookies();
        state.props = {};
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders nothing when disabled', async () => {
        state.props = { kinetix_cookie_consent: { enabled: false } };

        const w = mountIt();
        await w.vm.$nextTick();

        expect(w.find('[role="region"]').exists()).toBe(false);
    });

    it('shows the bar with the configured message when enabled', async () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const w = mountIt();
        await w.vm.$nextTick();

        expect(w.text()).toContain(
            'We use cookies to improve your experience.',
        );
        expect(w.text()).toContain('Accept');
        expect(w.text()).toContain('Decline');
    });

    it('renders the policy link only when policyUrl is set', async () => {
        state.props = {
            kinetix_cookie_consent: {
                enabled: true,
                policyUrl: '/cookie-policy',
            },
        };

        const w = mountIt();
        await w.vm.$nextTick();

        const link = w.find('a');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/cookie-policy');
    });

    it('hides after clicking Accept and writes the consent cookie', async () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const w = mountIt();
        await w.vm.$nextTick();

        await w
            .findAll('button')
            .find((b) => b.text() === 'Accept')
            ?.trigger('click');
        await w.vm.$nextTick();

        expect(w.find('[role="region"]').exists()).toBe(false);
        expect(document.cookie).toContain('kinetix_cookie_consent=accepted');
    });

    it('hides after clicking Decline and writes a distinct cookie value', async () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const w = mountIt();
        await w.vm.$nextTick();

        await w
            .findAll('button')
            .find((b) => b.text() === 'Decline')
            ?.trigger('click');
        await w.vm.$nextTick();

        expect(w.find('[role="region"]').exists()).toBe(false);
        expect(document.cookie).toContain('kinetix_cookie_consent=declined');
    });

    it('positions at the top when position is "top"', async () => {
        state.props = {
            kinetix_cookie_consent: { enabled: true, position: 'top' },
        };

        const w = mountIt();
        await w.vm.$nextTick();

        expect(w.find('[role="region"]').classes()).toContain('top-0');
    });
});
