import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

const toastError = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: { error: (message: string) => toastError(message) },
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

/** A promise this test settles by hand, to stage out-of-order responses. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

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

describe('KinetixTags sealed reference', () => {
    const mountWithReference = () =>
        mount(KinetixTags, {
            props: { reference: 'sealed-tags-ref' },
            global: { plugins: [i18n] },
        });

    it('loads by reference alone', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ tags: [] });
        mountWithReference();
        await flushPromises();

        const url = new URL(String(fetchMock.mock.calls[0][0]), 'http://x');
        expect(url.pathname).toBe('/_kinetix/tags');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            reference: 'sealed-tags-ref',
        });
    });

    it('syncs with the reference instead of type + id', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ tags: ['laravel'] });
        const w = mountWithReference();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ tags: ['laravel', 'vue'] });
        const input = w.find('input');
        await input.setValue('vue');
        await input.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        const syncCall = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tags/sync'),
        );
        expect(syncCall![1].body).toEqual({
            reference: 'sealed-tags-ref',
            tags: ['laravel', 'vue'],
        });
    });

    it('keeps the legacy type + id query without a reference', async () => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ tags: [] });
        mountIt();
        await flushPromises();

        const url = new URL(String(fetchMock.mock.calls[0][0]), 'http://x');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            taggable_type: 'App\\Models\\Post',
            taggable_id: '3',
        });
    });
});

describe('KinetixTags refused requests', () => {
    const forbidden = () =>
        Object.assign(new Error('This action is unauthorized.'), {
            status: 403,
        });

    it("shows the server's message when the load is refused", async () => {
        fetchMock.mockReset();
        toastError.mockClear();
        fetchMock.mockRejectedValueOnce(forbidden());
        const w = mountIt();
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('This action is unauthorized.');
        expect(w.findAll('[aria-label^="kinetix.tag_remove"]')).toHaveLength(0);
    });

    it('keeps the chips and the typed tag when a sync is refused', async () => {
        fetchMock.mockReset();
        toastError.mockClear();
        fetchMock.mockResolvedValueOnce({ tags: ['laravel'] });
        const w = mountIt();
        await flushPromises();

        fetchMock.mockRejectedValueOnce(forbidden());
        const input = w.find('input');
        await input.setValue('vue');
        await input.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('This action is unauthorized.');
        expect(w.text()).toContain('laravel');
        expect(w.text()).not.toContain('vue');
        expect((input.element as HTMLInputElement).value).toBe('vue');
    });

    it('keeps a chip whose removal is refused', async () => {
        fetchMock.mockReset();
        toastError.mockClear();
        fetchMock.mockResolvedValueOnce({ tags: ['laravel', 'vue'] });
        const w = mountIt();
        await flushPromises();

        fetchMock.mockRejectedValueOnce(forbidden());
        await w.find('[aria-label="kinetix.tag_remove: vue"]').trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('This action is unauthorized.');
        expect(w.text()).toContain('vue');
    });

    it('offers no suggestions when the lookup fails', async () => {
        vi.useFakeTimers();
        fetchMock.mockReset();
        toastError.mockClear();
        fetchMock.mockResolvedValueOnce({ tags: [] });
        const w = mountIt();
        await flushPromises();

        fetchMock.mockRejectedValueOnce(forbidden());
        await w.find('input').setValue('la');
        await w.find('input').trigger('input');
        await vi.runAllTimersAsync();
        vi.useRealTimers();
        await flushPromises();

        expect(w.find('ul').exists()).toBe(false);
        expect(toastError).not.toHaveBeenCalled();
    });

    it('shows the suggestions of the newest keystroke even when an older lookup answers last', async () => {
        vi.useFakeTimers();
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ tags: [] }); // initial load
        const w = mountIt();
        await vi.advanceTimersByTimeAsync(0);

        const slow = deferred<{ tags: string[] }>();
        const fast = deferred<{ tags: string[] }>();
        fetchMock.mockReturnValueOnce(slow.promise);
        fetchMock.mockReturnValueOnce(fast.promise);

        const input = w.find('input');
        await input.setValue('l');
        await vi.advanceTimersByTimeAsync(250);
        await input.setValue('lar');
        await vi.advanceTimersByTimeAsync(250);

        fast.resolve({ tags: ['laravel'] });
        await vi.advanceTimersByTimeAsync(0);
        slow.resolve({ tags: ['linux'] });
        await vi.advanceTimersByTimeAsync(0);

        expect(w.text()).toContain('laravel');
        expect(w.text()).not.toContain('linux');

        const first = fetchMock.mock.calls[1][1] as { signal: AbortSignal };
        expect(first.signal.aborted).toBe(true);
        vi.useRealTimers();
    });

    it('drops an in-flight lookup when the input is cleared', async () => {
        vi.useFakeTimers();
        fetchMock.mockReset();
        fetchMock.mockResolvedValueOnce({ tags: [] });
        const w = mountIt();
        await vi.advanceTimersByTimeAsync(0);

        const late = deferred<{ tags: string[] }>();
        fetchMock.mockReturnValueOnce(late.promise);

        const input = w.find('input');
        await input.setValue('lar');
        await vi.advanceTimersByTimeAsync(250);
        await input.setValue('');
        late.resolve({ tags: ['ghost'] });
        await vi.advanceTimersByTimeAsync(0);

        expect(w.text()).not.toContain('ghost');
        vi.useRealTimers();
    });
});
