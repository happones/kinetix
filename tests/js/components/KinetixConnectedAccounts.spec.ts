import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import KinetixConnectedAccounts from '@/components/KinetixConnectedAccounts.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l, key) => key,
    messages: { en: { kinetix: {} } },
});

const mountIt = () =>
    mount(KinetixConnectedAccounts, { global: { plugins: [i18n] } });

describe('KinetixConnectedAccounts', () => {
    it('lists providers and shows a connect link for unlinked ones', async () => {
        fetchMock.mockResolvedValueOnce({
            accounts: [],
            providers: [
                {
                    key: 'github',
                    label: 'GitHub',
                    icon: 'github',
                    color: null,
                    linked: false,
                },
                {
                    key: 'google',
                    label: 'Google',
                    icon: 'google',
                    color: null,
                    linked: false,
                },
            ],
            hasPassword: true,
        });

        const w = mountIt();
        await flushPromises();

        const links = w.findAll('a[href]');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('href')).toBe(
            '/_kinetix/connected-accounts/redirect/github',
        );
    });

    it('shows the linked identity and a disconnect control', async () => {
        fetchMock.mockResolvedValueOnce({
            accounts: [
                {
                    id: 1,
                    provider: 'github',
                    name: 'Ada',
                    nickname: 'ada',
                    email: 'ada@example.com',
                    avatar: null,
                    createdAt: null,
                },
            ],
            providers: [
                {
                    key: 'github',
                    label: 'GitHub',
                    icon: 'github',
                    color: null,
                    linked: true,
                },
            ],
            hasPassword: true,
        });

        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('ada@example.com');
        // Linked provider renders no connect <a>, only a disconnect button.
        expect(w.findAll('a[href]')).toHaveLength(0);
    });

    it('prompts a social-only user to set a password', async () => {
        fetchMock.mockResolvedValueOnce({
            accounts: [],
            providers: [],
            hasPassword: false,
        });

        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('password_set_title');
    });
});
