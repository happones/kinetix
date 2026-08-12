import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const pageProps: Record<string, unknown> = {};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixAnnouncements from '@/components/KinetixAnnouncements.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const mountIt = () =>
    mount(KinetixAnnouncements, { global: { plugins: [i18n] } });

const announcement = {
    id: 1,
    title: 'v2',
    body: 'b',
    level: 'feature',
    publishedAt: '2026-06-26T10:00:00Z',
    isNew: true,
};

describe('KinetixAnnouncements', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        pageProps.kinetix_announcements = {
            unread: 3,
            bannerLimit: 3,
            banner: [],
        };
    });

    it('shows the unread badge from the page payload, without fetching', async () => {
        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('3');
        // The header costs no request until someone opens it.
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads the list and marks the feed seen when the popover opens', async () => {
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({
            announcements: [announcement],
            unread: 3,
        });
        fetchMock.mockResolvedValueOnce({ status: 'success' });

        await w.find('button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/announcements');
        expect(
            fetchMock.mock.calls.find(
                (c) =>
                    String(c[0]).endsWith('/announcements/seen') &&
                    c[1]?.method === 'POST',
            ),
        ).toBeTruthy();
        // The badge clears optimistically, before the next page payload.
        expect(w.text()).not.toContain('3');
    });

    it('fetches the list once, not on every open', async () => {
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValue({
            announcements: [announcement],
            unread: 0,
        });

        await w.find('button').trigger('click');
        await flushPromises();
        await w.find('button').trigger('click');
        await w.find('button').trigger('click');
        await flushPromises();

        const listCalls = fetchMock.mock.calls.filter(
            (c) => c[0] === '/_kinetix/announcements',
        );
        expect(listCalls).toHaveLength(1);
    });

    it('falls back to fetching when the app does not share the payload', async () => {
        pageProps.kinetix_announcements = undefined;
        fetchMock.mockResolvedValue({
            announcements: [announcement],
            unread: 0,
        });

        const w = mountIt();
        await flushPromises();

        expect(w.text()).not.toContain('3');

        await w.find('button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/announcements');
    });
});
