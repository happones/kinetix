import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import KinetixSavedViews from '@/components/KinetixSavedViews.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const view = (over = {}) => ({
    id: 1,
    name: 'Active',
    state: { search: 'foo', filters: { status: 'active' } },
    isDefault: false,
    ...over,
});

const mountIt = (currentState = {}) =>
    mount(KinetixSavedViews, {
        props: { viewKey: 'App\\Models\\Post', currentState },
        global: { plugins: [i18n] },
    });

describe('KinetixSavedViews', () => {
    it('applies the default view on mount', async () => {
        fetchMock.mockResolvedValueOnce({
            views: [
                view({
                    id: 2,
                    name: 'Default',
                    isDefault: true,
                    state: { search: 'x' },
                }),
            ],
        });
        const w = mountIt();
        await flushPromises();

        const applied = w.emitted('apply');
        expect(applied).toBeTruthy();
        expect(applied![0][0]).toEqual({ search: 'x' });
    });

    it('renders the views trigger button', async () => {
        fetchMock.mockResolvedValueOnce({ views: [view()] });
        const w = mountIt();
        await flushPromises();
        expect(w.find('button').exists()).toBe(true);
    });
});

describe('useKinetixSavedViews', () => {
    it('create posts the key + name + state and refreshes the list', async () => {
        const { useKinetixSavedViews } =
            await import('@/composables/useKinetixSavedViews');
        const { create, views } = useKinetixSavedViews('App\\Models\\Post');

        const state = { search: 'q', columns: ['a', 'b'] };
        fetchMock.mockResolvedValueOnce({ views: [view({ name: 'My view' })] });
        await create('My view', state);

        const postCall = fetchMock.mock.calls.find(
            (c) => c[1]?.method === 'POST',
        );
        expect(postCall![0]).toBe('/_kinetix/saved-views');
        expect(postCall![1].body).toMatchObject({
            key: 'App\\Models\\Post',
            name: 'My view',
            state,
            is_default: false,
        });
        expect(views.value).toHaveLength(1);
    });
});
