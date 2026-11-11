import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { pageProps, navigate } = vi.hoisted(() => ({
    pageProps: {} as Record<string, unknown>,
    navigate: { handler: null as null | ((event: unknown) => void) },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: {
        on: (_event: string, handler: (event: unknown) => void) => {
            navigate.handler = handler;

            return () => {};
        },
    },
}));

import {
    clearKinetixSearchCache,
    getKinetixSearchCache,
    kinetixSearchCacheSize,
    setKinetixSearchCache,
} from '@/composables/kinetixSearchCache';
import {
    useKinetixSearch,
    useKinetixSearchAnnouncer,
} from '@/composables/useKinetixSearch';
import type { UseKinetixSearchOptions } from '@/composables/useKinetixSearch';

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

const fetchError = (
    status: number,
    extra: { code?: string; retryAfter?: number; message?: string } = {},
) =>
    Object.assign(new Error(extra.message ?? 'nope'), {
        status,
        code: extra.code,
        retryAfter: extra.retryAfter,
    });

const make = (overrides: Partial<UseKinetixSearchOptions<string[]>> = {}) => {
    const fetcher = vi.fn(async (query: string) => ({
        value: [query],
    }));

    const search = useKinetixSearch<string[]>({
        fetcher,
        debounce: 100,
        ...overrides,
    });

    return { search, fetcher };
};

describe('useKinetixSearch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        clearKinetixSearchCache();
        delete pageProps.kinetix_config;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces typing into one request for the last term', async () => {
        const { search, fetcher } = make();

        void search.search('a');
        void search.search('ap');
        const last = search.search('app');
        expect(search.status.value).toBe('debouncing');

        await vi.advanceTimersByTimeAsync(100);

        expect(await last).toEqual(['app']);
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(search.results.value).toEqual(['app']);
        expect(search.status.value).toBe('success');
    });

    it('takes the default debounce from the shared config', async () => {
        pageProps.kinetix_config = { search: { debounce: 500 } };
        const { search, fetcher } = make({ debounce: undefined });

        void search.search('x');
        await vi.advanceTimersByTimeAsync(499);
        expect(fetcher).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it('lets the newest answer win when responses arrive out of order', async () => {
        const first = deferred<{ value: string[] }>();
        const second = deferred<{ value: string[] }>();
        const answers = [first, second];
        const { search } = make({ fetcher: () => answers.shift()!.promise });

        const a = search.search('a', { immediate: true });
        const b = search.search('b', { immediate: true });

        second.resolve({ value: ['new'] });
        first.resolve({ value: ['old'] });

        expect(await b).toEqual(['new']);
        expect(await a).toBeNull();
        expect(search.results.value).toEqual(['new']);
        expect(search.loading.value).toBe(false);
    });

    it('never sends a non-empty term below minChars, but loads the empty one', async () => {
        const { search, fetcher } = make({ minChars: () => 3 });

        expect(await search.search('ab', { immediate: true })).toBeNull();
        expect(search.status.value).toBe('tooShort');
        expect(fetcher).not.toHaveBeenCalled();

        await search.search('', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(search.status.value).toBe('success');
    });

    it('caps the term at maxLength', async () => {
        const { search, fetcher } = make({ maxLength: 3 });

        await search.search('abcdef', { immediate: true });

        expect(fetcher.mock.calls[0][0]).toBe('abc');
    });

    it('joins an identical in-flight query instead of sending it twice', async () => {
        const gate = deferred<{ value: string[] }>();
        const fetcher = vi.fn(() => gate.promise);
        const { search } = make({ fetcher });

        const a = search.search('q', { immediate: true });
        const b = search.search('q', { immediate: true });
        gate.resolve({ value: ['r'] });

        expect(await a).toEqual(['r']);
        expect(await b).toEqual(['r']);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it('answers a repeated query from the cache, and force bypasses it', async () => {
        const { search, fetcher } = make({
            cacheKey: () => 'team-a:forms:tok',
        });

        await search.search('x', { immediate: true });
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(search.results.value).toEqual(['x']);

        await search.search('x', { immediate: true, force: true });
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('never crosses namespaces (another team or token)', async () => {
        let ns = 'team-a:forms:tok';
        const { search, fetcher } = make({ cacheKey: () => ns });

        await search.search('x', { immediate: true });
        ns = 'team-b:forms:tok';
        await search.search('x', { immediate: true });

        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('does not cache without a cacheKey or past the ttl', async () => {
        const plain = make();
        await plain.search.search('x', { immediate: true });
        await plain.search.search('x', { immediate: true });
        expect(plain.fetcher).toHaveBeenCalledTimes(2);

        const keyed = make({ cacheKey: () => 'ns', cacheTtl: 1000 });
        await keyed.search.search('y', { immediate: true });
        vi.advanceTimersByTime(1001);
        await keyed.search.search('y', { immediate: true });
        expect(keyed.fetcher).toHaveBeenCalledTimes(2);
    });

    it('invalidate() drops the cached answers', async () => {
        const { search, fetcher } = make({ cacheKey: () => 'ns' });

        await search.search('x', { immediate: true });
        search.invalidate();
        await search.search('x', { immediate: true });

        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('keeps the last results on a 429, then retries the latest term once', async () => {
        let calls = 0;
        const { search, fetcher } = make({
            fetcher: async (query: string) => {
                calls++;

                if (calls === 2) {
                    throw fetchError(429, {
                        code: 'busy',
                        retryAfter: 1,
                        message: 'Busy',
                    });
                }

                return { value: [query] };
            },
        });

        await search.search('a', { immediate: true });
        expect(await search.search('ab', { immediate: true })).toBeNull();

        expect(search.status.value).toBe('throttled');
        expect(search.results.value).toEqual(['a']);
        expect(search.retryAfter.value).toBe(1);
        expect(search.message.value).toBe('Busy');
        expect(fetcher).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);

        expect(calls).toBe(3);
        expect(search.status.value).toBe('success');
        expect(search.results.value).toEqual(['ab']);
    });

    it('retries a throttle only once', async () => {
        let calls = 0;
        const { search } = make({
            fetcher: async () => {
                calls++;
                throw fetchError(429, { code: 'throttled', retryAfter: 1 });
            },
        });

        await search.search('a', { immediate: true });
        await vi.advanceTimersByTimeAsync(5000);

        expect(calls).toBe(2);
        expect(search.status.value).toBe('throttled');
    });

    it('does not wait for a long retry_after', async () => {
        let calls = 0;
        const { search } = make({
            fetcher: async () => {
                calls++;
                throw fetchError(429, { retryAfter: 30 });
            },
        });

        await search.search('a', { immediate: true });
        await vi.advanceTimersByTimeAsync(60_000);

        expect(calls).toBe(1);
    });

    it('typing again cancels the pending retry', async () => {
        let calls = 0;
        const { search } = make({
            fetcher: async (query: string) => {
                calls++;

                if (calls === 1) {
                    throw fetchError(429, { retryAfter: 2 });
                }

                return { value: [query] };
            },
        });

        await search.search('a', { immediate: true });
        await search.search('b', { immediate: true });
        await vi.advanceTimersByTimeAsync(5000);

        expect(calls).toBe(2);
        expect(search.results.value).toEqual(['b']);
    });

    it('reports a timed-out answer and never caches it', async () => {
        const fetcher = vi.fn(async () => ({
            value: [] as string[],
            meta: { timed_out: true },
        }));
        const { search } = make({ fetcher, cacheKey: () => 'ns' });

        await search.search('x', { immediate: true });
        expect(search.status.value).toBe('timedOut');
        expect(kinetixSearchCacheSize()).toBe(0);

        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('turns the server below_min answer into tooShort with its meta', async () => {
        const { search } = make({
            fetcher: async () => ({
                value: [] as string[],
                meta: { below_min: true, min_chars: 4 },
            }),
        });

        await search.search('ab', { immediate: true });

        expect(search.status.value).toBe('tooShort');
        expect(search.meta.value?.min_chars).toBe(4);
    });

    it('maps an expired descriptor and other failures without rejecting', async () => {
        let error: unknown = fetchError(403, {
            code: 'descriptor_expired',
            message: 'Reload',
        });
        const { search } = make({
            fetcher: async () => {
                throw error;
            },
        });

        expect(await search.search('a', { immediate: true })).toBeNull();
        expect(search.status.value).toBe('expired');
        expect(search.message.value).toBe('Reload');

        error = new TypeError('Failed to fetch');
        await search.search('b', { immediate: true });
        expect(search.status.value).toBe('error');
        expect(search.message.value).toBeNull();
        expect(search.results.value).toBeNull();
    });

    it('cancel() drops a pending debounce and aborts the request', async () => {
        const signals: AbortSignal[] = [];
        const { search, fetcher } = make({
            fetcher: (_query, { signal }) => {
                signals.push(signal);

                return new Promise(() => {});
            },
        });

        const skipped = search.search('a');
        search.cancel();
        expect(await skipped).toBeNull();
        await vi.advanceTimersByTimeAsync(500);
        expect(signals).toHaveLength(0);
        expect(fetcher).not.toHaveBeenCalled();

        void search.search('b', { immediate: true });
        search.cancel();

        expect(signals[0].aborted).toBe(true);
        expect(search.loading.value).toBe(false);
        expect(search.status.value).toBe('idle');
    });

    it('reset() forgets the results', async () => {
        const { search } = make();

        await search.search('a', { immediate: true });
        search.reset();

        expect(search.results.value).toBeNull();
        expect(search.status.value).toBe('idle');
    });

    it('calls onSettled once per finished search, not while typing', async () => {
        const onSettled = vi.fn();
        const { search } = make({ onSettled });

        void search.search('a');
        void search.search('ap');
        void search.search('app');
        await vi.advanceTimersByTimeAsync(100);

        expect(onSettled).toHaveBeenCalledTimes(1);
        expect(onSettled.mock.calls[0][0]).toMatchObject({
            status: 'success',
            results: ['app'],
        });
    });
});

describe('useKinetixSearch identity', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        clearKinetixSearchCache();
        for (const key of Object.keys(pageProps)) {
            delete pageProps[key];
        }
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("never gives a second user in the same tab the first one's answer", async () => {
        pageProps.auth = { user: { id: 1 } };
        const { search, fetcher } = make({ cacheKey: () => 'help.search' });

        await search.search('x', { immediate: true });
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(1);

        pageProps.auth = { user: { id: 2 } };
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(2);
        expect(kinetixSearchCacheSize()).toBe(1); // the first user's is gone
    });

    it('treats impersonation start and stop as another identity', async () => {
        pageProps.auth = { user: { id: 1 } };
        const { search, fetcher } = make({ cacheKey: () => 'k' });

        await search.search('x', { immediate: true });

        pageProps.kinetix_impersonation = { active: true, user: { id: 9 } };
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(2);

        pageProps.kinetix_impersonation = { active: false };
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(3);
    });

    it('separates teams and languages', async () => {
        pageProps.kinetix_config = { route_prefix: 'a/_kinetix', team: 'a' };
        const { search, fetcher } = make({ cacheKey: () => 'k' });

        await search.search('x', { immediate: true });
        pageProps.kinetix_config = { route_prefix: 'b/_kinetix', team: 'b' };
        await search.search('x', { immediate: true });
        pageProps.kinetix_locale = { current: 'es' };
        await search.search('x', { immediate: true });

        expect(fetcher).toHaveBeenCalledTimes(3);
    });

    it('empties the whole cache on a navigation that changes who the page is for', async () => {
        pageProps.auth = { user: { id: 1 } };
        const { search } = make({ cacheKey: () => 'k' });

        await search.search('x', { immediate: true });
        expect(kinetixSearchCacheSize()).toBe(1);

        navigate.handler?.({
            detail: { page: { props: { auth: { user: { id: 1 } } } } },
        });
        expect(kinetixSearchCacheSize()).toBe(1); // same identity: kept

        navigate.handler?.({
            detail: {
                page: {
                    props: {
                        auth: { user: { id: 1 } },
                        kinetix_impersonation: {
                            active: true,
                            user: { id: 5 },
                        },
                    },
                },
            },
        });
        expect(kinetixSearchCacheSize()).toBe(0);
    });
});

describe('useKinetixSearch identity during a request', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        clearKinetixSearchCache();
        for (const key of Object.keys(pageProps)) {
            delete pageProps[key];
        }
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('drops an answer that lands after the page moved to another user', async () => {
        pageProps.auth = { user: { id: 1 } };
        const gate = deferred<{ value: string[] }>();
        const fetcher = vi.fn(() => gate.promise);
        const { search } = make({ fetcher, cacheKey: () => 'k' });

        const pending = search.search('x', { immediate: true });

        pageProps.auth = { user: { id: 2 } };
        navigate.handler?.({
            detail: { page: { props: { auth: { user: { id: 2 } } } } },
        });
        gate.resolve({ value: ['a-secret'] });

        expect(await pending).toBeNull();
        expect(search.results.value).toBeNull();
        expect(search.status.value).toBe('idle');
        expect(kinetixSearchCacheSize()).toBe(0);

        fetcher.mockImplementation(async () => ({ value: ['b'] }) as never);
        await search.search('x', { immediate: true });
        expect(fetcher).toHaveBeenCalledTimes(2);
        expect(search.results.value).toEqual(['b']);
    });

    it("never lets a search under another user join the first one's request", async () => {
        pageProps.auth = { user: { id: 1 } };
        const gate = deferred<{ value: string[] }>();
        const fetcher = vi.fn(() => gate.promise);
        const { search } = make({ fetcher, cacheKey: () => 'k' });

        void search.search('x', { immediate: true });
        pageProps.auth = { user: { id: 2 } };
        void search.search('x', { immediate: true });

        expect(fetcher).toHaveBeenCalledTimes(2);
        gate.resolve({ value: ['r'] });
        await vi.advanceTimersByTimeAsync(0);
    });
});

describe('useKinetixSearch details', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        clearKinetixSearchCache();
        for (const key of Object.keys(pageProps)) {
            delete pageProps[key];
        }
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('carries the effective minimum on a client-side too-short term', async () => {
        const { search } = make({ minChars: 4 });

        await search.search('ab', { immediate: true });

        expect(search.meta.value).toEqual({ below_min: true, min_chars: 4 });
    });

    it('passes silent to onSettled', async () => {
        const onSettled = vi.fn();
        const { search } = make({ onSettled });

        await search.search('a', { immediate: true, silent: true });
        await search.search('b', { immediate: true });

        expect(onSettled.mock.calls[0][0].silent).toBe(true);
        expect(onSettled.mock.calls[1][0].silent).toBe(false);
    });

    it('does not treat a throwing onSettled as a failed fetch, nor call it twice', async () => {
        const onSettled = vi.fn(() => {
            throw new Error('host bug');
        });
        const { search } = make({ onSettled });
        const uncaught: unknown[] = [];
        const handler = (error: unknown) => uncaught.push(error);
        process.on('uncaughtException', handler);

        const answer = await search.search('a', { immediate: true });
        await vi.advanceTimersByTimeAsync(0);
        process.off('uncaughtException', handler);

        expect(answer).toEqual(['a']);
        expect(search.status.value).toBe('success');
        expect(onSettled).toHaveBeenCalledTimes(1);
    });
});

describe('kinetixSearchCache', () => {
    beforeEach(() => {
        clearKinetixSearchCache();
    });

    it('evicts the least recently used entry past the capacity', () => {
        setKinetixSearchCache('ns', 'a', 1, 1000, 2);
        setKinetixSearchCache('ns', 'b', 2, 1000, 2);
        expect(getKinetixSearchCache('ns', 'a')).toBe(1); // a is now fresh
        setKinetixSearchCache('ns', 'c', 3, 1000, 2);

        expect(getKinetixSearchCache('ns', 'b')).toBeUndefined();
        expect(getKinetixSearchCache('ns', 'a')).toBe(1);
        expect(getKinetixSearchCache('ns', 'c')).toBe(3);
    });

    it('clears one namespace and the ones nested below it', () => {
        setKinetixSearchCache('team:rel', 'a', 1, 1000, 10);
        setKinetixSearchCache('team:rel:attach', 'a', 2, 1000, 10);
        setKinetixSearchCache('team:rel2', 'a', 3, 1000, 10);

        clearKinetixSearchCache('team:rel');

        expect(getKinetixSearchCache('team:rel', 'a')).toBeUndefined();
        expect(getKinetixSearchCache('team:rel:attach', 'a')).toBeUndefined();
        expect(getKinetixSearchCache('team:rel2', 'a')).toBe(3);
    });

    it('is disabled when there is no window (SSR)', () => {
        vi.stubGlobal('window', undefined);
        setKinetixSearchCache('ns', 'a', 1, 1000, 10);
        expect(getKinetixSearchCache('ns', 'a')).toBeUndefined();
        vi.unstubAllGlobals();

        expect(kinetixSearchCacheSize()).toBe(0);
    });

    it('stores nothing with a zero ttl or capacity', () => {
        setKinetixSearchCache('ns', 'a', 1, 0, 10);
        setKinetixSearchCache('ns', 'b', 1, 1000, 0);

        expect(kinetixSearchCacheSize()).toBe(0);
    });
});

describe('useKinetixSearchAnnouncer', () => {
    const i18n = createI18n({
        legacy: false,
        locale: 'en',
        missingWarn: false,
        messages: {
            en: {
                kinetix: {
                    search_results_count: '{count} result | {count} results',
                    search_throttled_retrying: 'Retrying',
                    search_timed_out: 'Timed out',
                    search_min_chars: 'At least {count}',
                },
            },
        },
    });

    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('requestAnimationFrame', (cb: () => void) => {
            cb();

            return 0;
        });
        document.getElementById('kinetix-live-region')?.remove();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('announces the count, a retry and a timeout politely, once per settle', async () => {
        let announcer!: ReturnType<typeof useKinetixSearchAnnouncer<string[]>>;

        mount(
            {
                setup() {
                    announcer = useKinetixSearchAnnouncer<string[]>({
                        count: (list) => list.length,
                    });

                    return () => null;
                },
            },
            { global: { plugins: [i18n] } },
        );

        const region = () =>
            document.getElementById('kinetix-live-region')!.textContent;
        const base = {
            meta: null,
            message: null,
            retrying: false,
            silent: false,
        };

        announcer({ ...base, status: 'success', results: ['a', 'b'] });
        await flushPromises();
        expect(region()).toBe('2 results');

        announcer({ ...base, status: 'success', results: ['a'] });
        expect(region()).toBe('1 result');

        announcer({
            ...base,
            status: 'throttled',
            results: null,
            retrying: true,
        });
        expect(region()).toBe('Retrying');

        announcer({ ...base, status: 'timedOut', results: [] });
        expect(region()).toBe('Timed out');
        expect(
            document
                .getElementById('kinetix-live-region')!
                .getAttribute('aria-live'),
        ).toBe('polite');
    });

    it('announces a throttle only while it is retrying, never the final failure', () => {
        let announcer!: ReturnType<typeof useKinetixSearchAnnouncer<string[]>>;

        mount(
            {
                setup() {
                    announcer = useKinetixSearchAnnouncer<string[]>({
                        count: (list) => list.length,
                    });

                    return () => null;
                },
            },
            { global: { plugins: [i18n] } },
        );

        announcer({
            status: 'throttled',
            results: null,
            meta: null,
            message: 'Busy',
            retrying: false,
            silent: false,
        });

        expect(document.getElementById('kinetix-live-region')).toBeNull();
    });

    it('stays quiet for a silent (initial) search', () => {
        let announcer!: ReturnType<typeof useKinetixSearchAnnouncer<string[]>>;

        mount(
            {
                setup() {
                    announcer = useKinetixSearchAnnouncer<string[]>({
                        count: (list) => list.length,
                    });

                    return () => null;
                },
            },
            { global: { plugins: [i18n] } },
        );

        announcer({
            status: 'success',
            results: ['a'],
            meta: null,
            message: null,
            retrying: false,
            silent: true,
        });

        expect(document.getElementById('kinetix-live-region')).toBeNull();
    });

    it('announces the effective minimum for a too-short term', () => {
        let announcer!: ReturnType<typeof useKinetixSearchAnnouncer<string[]>>;

        mount(
            {
                setup() {
                    announcer = useKinetixSearchAnnouncer<string[]>({
                        count: (list) => list.length,
                    });

                    return () => null;
                },
            },
            { global: { plugins: [i18n] } },
        );

        announcer({
            status: 'tooShort',
            results: null,
            meta: { below_min: true, min_chars: 4 },
            message: null,
            retrying: false,
            silent: false,
        });

        expect(
            document.getElementById('kinetix-live-region')?.textContent,
        ).toBe('At least 4');
    });

    it('can leave the too-short hint to the control that renders it', () => {
        let announcer!: ReturnType<typeof useKinetixSearchAnnouncer<string[]>>;

        mount(
            {
                setup() {
                    announcer = useKinetixSearchAnnouncer<string[]>({
                        count: (list) => list.length,
                        tooShort: false,
                    });

                    return () => null;
                },
            },
            { global: { plugins: [i18n] } },
        );

        announcer({
            status: 'tooShort',
            results: null,
            meta: null,
            message: null,
            retrying: false,
            silent: false,
        });

        expect(document.getElementById('kinetix-live-region')).toBeNull();
    });
});
