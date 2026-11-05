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

import { toast } from 'vue-sonner';
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

describe('KinetixComments sealed reference', () => {
    const mountWithReference = () =>
        mount(KinetixComments, {
            props: { reference: 'sealed-comments-ref' },
            global: { plugins: [i18n] },
        });

    it('loads by reference alone', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ comments: [] });
        mountWithReference();
        await flushPromises();

        const url = new URL(String(fetchMock.mock.calls[0][0]), 'http://x');
        expect(url.pathname).toBe('/_kinetix/comments');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            reference: 'sealed-comments-ref',
        });
    });

    it('posts the reference instead of type + id', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ comments: [] });
        const w = mountWithReference();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ comments: [] });
        await w.find('textarea').setValue('Sealed');
        await w.find('form').trigger('submit');
        await flushPromises();

        const postCall = fetchMock.mock.calls.find(
            (c) => c[1]?.method === 'POST',
        );
        expect(postCall![1].body).toEqual({
            reference: 'sealed-comments-ref',
            body: 'Sealed',
            parent_id: null,
        });
    });

    it('keeps the legacy type + id query without a reference', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ comments: [] });
        mountIt();
        await flushPromises();

        const url = new URL(String(fetchMock.mock.calls[0][0]), 'http://x');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            commentable_type: 'App\\Models\\Post',
            commentable_id: '7',
        });
    });
});

describe('KinetixComments refused load', () => {
    it("shows the server's message instead of an empty thread", async () => {
        fetchMock.mockReset();
        vi.mocked(toast.error).mockClear();
        fetchMock.mockRejectedValueOnce(
            Object.assign(new Error('This action is unauthorized.'), {
                status: 403,
            }),
        );
        const w = mountIt();
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith(
            'This action is unauthorized.',
        );
        expect(w.text()).not.toContain('kinetix.comment_empty');
    });

    it('keeps the shown thread when a reload after a delete is refused', async () => {
        fetchMock.mockReset();
        vi.mocked(toast.error).mockClear();
        fetchMock.mockResolvedValueOnce({ comments: [comment()] });
        const w = mountIt();
        await flushPromises();
        expect(w.text()).toContain('Hello there');

        fetchMock.mockReset();
        fetchMock
            .mockResolvedValueOnce(null) // DELETE
            .mockRejectedValueOnce(new Error('Please reload the page.')); // reload
        const deleteButton = w
            .findAll('button')
            .find((b) => b.text().includes('kinetix.delete'));
        await deleteButton!.trigger('click');
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith('Please reload the page.');
        expect(w.text()).toContain('Hello there');
    });
});
