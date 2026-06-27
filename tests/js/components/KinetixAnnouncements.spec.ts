import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
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

describe('KinetixAnnouncements', () => {
    it('shows the unread badge from the feed', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [
                {
                    id: 1,
                    title: 'v2',
                    body: 'b',
                    level: 'feature',
                    publishedAt: '2026-06-26T10:00:00Z',
                    isNew: true,
                },
            ],
            unread: 3,
        });
        const w = mountIt();
        await flushPromises();
        expect(w.text()).toContain('3');
    });

    it('marks the feed seen when the popover opens', async () => {
        fetchMock.mockResolvedValueOnce({ announcements: [], unread: 2 });
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        // Open the popover via the trigger.
        await w.find('button').trigger('click');
        await flushPromises();

        const seenCall = fetchMock.mock.calls.find(
            (c) =>
                String(c[0]).endsWith('/announcements/seen') &&
                c[1]?.method === 'POST',
        );
        expect(seenCall).toBeTruthy();
    });
});
