import { beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
}));

import { useKinetixCookieConsent } from '@/composables/useKinetixCookieConsent';

function clearCookies(): void {
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0]?.trim();

        if (name) {
            document.cookie = `${name}=;path=/;max-age=0`;
        }
    });
}

describe('useKinetixCookieConsent', () => {
    beforeEach(() => {
        clearCookies();
        state.props = {};
    });

    it('is not visible when disabled', () => {
        state.props = { kinetix_cookie_consent: { enabled: false } };

        const { visible, checkConsent } = useKinetixCookieConsent();
        checkConsent();

        expect(visible.value).toBe(false);
    });

    it('is visible when enabled and no consent cookie is set', () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const { visible, checkConsent } = useKinetixCookieConsent();
        checkConsent();

        expect(visible.value).toBe(true);
    });

    it('accept() writes the configured cookie name and hides the bar', () => {
        state.props = {
            kinetix_cookie_consent: {
                enabled: true,
                cookieName: 'my_consent',
                expiryDays: 30,
            },
        };

        const { visible, checkConsent, accept } = useKinetixCookieConsent();
        checkConsent();
        expect(visible.value).toBe(true);

        accept();

        expect(visible.value).toBe(false);
        expect(document.cookie).toContain('my_consent=accepted');
    });

    it('decline() writes a distinct cookie value and hides the bar', () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const { visible, checkConsent, decline } = useKinetixCookieConsent();
        checkConsent();

        decline();

        expect(visible.value).toBe(false);
        expect(document.cookie).toContain('kinetix_cookie_consent=declined');
    });

    it('stays hidden on a later checkConsent() once a cookie already exists', () => {
        state.props = { kinetix_cookie_consent: { enabled: true } };

        const first = useKinetixCookieConsent();
        first.checkConsent();
        first.accept();

        const second = useKinetixCookieConsent();
        second.checkConsent();

        expect(second.visible.value).toBe(false);
    });

    it('defaults to enabled: false when the shared prop is missing entirely', () => {
        state.props = {};

        const { visible, checkConsent } = useKinetixCookieConsent();
        checkConsent();

        expect(visible.value).toBe(false);
    });
});
