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

import KinetixComments from '@/components/KinetixComments.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const comment = (over = {}) => ({
    id: 1,
    body: 'Hello there',
    authorId: 1,
    authorName: 'Ada Lovelace',
    authorAvatar: null,
    parentId: null,
    createdAt: '2026-06-26T10:00:00Z',
    edited: false,
    editable: true,
    replies: [],
    ...over,
});

const mountIt = () =>
    mount(KinetixComments, {
        props: { commentableType: 'App\\Models\\Post', commentableId: 7 },
        global: { plugins: [i18n] },
    });

describe('KinetixComments', () => {
    it('loads and renders comments with author + replies', async () => {
        fetchMock.mockResolvedValueOnce({
            comments: [
                comment({
                    replies: [
                        comment({
                            id: 2,
                            body: 'A reply',
                            authorName: 'Bob',
                            editable: false,
                        }),
                    ],
                }),
            ],
        });
        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('Hello there');
        expect(w.text()).toContain('Ada Lovelace');
        expect(w.text()).toContain('A reply');
        // Initials avatar when no image.
        expect(w.text()).toContain('AL');
    });

    it('posts a new comment with the commentable identity', async () => {
        fetchMock.mockResolvedValueOnce({ comments: [] }); // initial load
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({
            comments: [comment({ body: 'New one' })],
        });
        await w.find('textarea').setValue('New one');
        await w.find('form').trigger('submit');
        await flushPromises();

        const postCall = fetchMock.mock.calls.find(
            (c) => c[1]?.method === 'POST',
        );
        expect(postCall).toBeTruthy();
        expect(postCall![1].body).toMatchObject({
            commentable_type: 'App\\Models\\Post',
            commentable_id: 7,
            body: 'New one',
            parent_id: null,
        });
    });
});
