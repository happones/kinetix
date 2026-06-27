import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixTags from '@/components/KinetixTags.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const mountIt = () =>
    mount(KinetixTags, {
        props: { taggableType: 'App\\Models\\Post', taggableId: 3 },
        global: { plugins: [i18n] },
    });

describe('KinetixTags', () => {
    it('loads and renders the current tags as chips', async () => {
        fetchMock.mockResolvedValueOnce({ tags: ['laravel', 'vue'] });
        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('laravel');
        expect(w.text()).toContain('vue');
    });

    it('adds a tag on Enter and syncs the new set', async () => {
        fetchMock.mockResolvedValueOnce({ tags: ['laravel'] }); // initial load
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ tags: ['laravel', 'vue'] }); // sync response
        const input = w.find('input');
        await input.setValue('vue');
        await input.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        const syncCall = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tags/sync'),
        );
        expect(syncCall).toBeTruthy();
        expect(syncCall![1].body).toMatchObject({
            taggable_type: 'App\\Models\\Post',
            taggable_id: 3,
            tags: ['laravel', 'vue'],
        });
        expect(w.text()).toContain('vue');
    });
});
