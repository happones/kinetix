import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    kinetixFailureMessage,
    isKinetixThrottled,
} from '@/composables/kinetixThrottle';
import {
    isKinetixAbort,
    isKinetixFetchError,
    kinetixFetch,
    xsrfToken,
} from '@/composables/useKinetixHttp';
import type { KinetixFetchError } from '@/composables/useKinetixHttp';

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

function stubFetch(
    response: Partial<Response> & { json?: () => Promise<unknown> },
) {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        text: () => Promise.resolve(JSON.stringify({ ok: true })),
        clone: () => ({ json: () => Promise.resolve({}) }),
        ...response,
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

describe('useKinetixHttp', () => {
    it('reads the XSRF-TOKEN cookie', () => {
        document.cookie = 'XSRF-TOKEN=abc%3D123';
        expect(xsrfToken()).toBe('abc=123');
    });

    it('sends JSON with the standard stateful headers + credentials', async () => {
        document.cookie = 'XSRF-TOKEN=tok';
        const fetchMock = stubFetch({});

        await kinetixFetch('/x', { method: 'post', body: { a: 1 } });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/x');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.body).toBe(JSON.stringify({ a: 1 }));
    });

    it('sends FormData as multipart (no JSON Content-Type)', async () => {
        const fetchMock = stubFetch({});
        const form = new FormData();
        form.append('file', 'x');

        await kinetixFetch('/upload', { method: 'POST', body: form });

        const [, init] = fetchMock.mock.calls[0];
        expect(init.headers['Content-Type']).toBeUndefined();
        expect(init.body).toBeInstanceOf(FormData);
    });

    it('throws the server message on a non-2xx response', async () => {
        stubFetch({
            ok: false,
            status: 422,
            clone: () => ({
                json: () => Promise.resolve({ message: 'Too big' }),
            }),
        });

        await expect(kinetixFetch('/x', { method: 'POST' })).rejects.toThrow(
            'Too big',
        );
    });

    it('returns null for a 204 response', async () => {
        stubFetch({ status: 204 });
        expect(await kinetixFetch('/x', { method: 'DELETE' })).toBeNull();
    });

    it('forwards an abort signal to fetch', async () => {
        const fetchMock = stubFetch({});
        const controller = new AbortController();

        await kinetixFetch('/x', { signal: controller.signal });

        expect(fetchMock.mock.calls[0][1].signal).toBe(controller.signal);
    });

    it('tells an abort apart from a real failure', () => {
        expect(isKinetixAbort(new DOMException('stop', 'AbortError'))).toBe(
            true,
        );
        expect(isKinetixAbort(new Error('HTTP error! status: 500'))).toBe(
            false,
        );
    });

    it('carries the code and retry-after of a throttled response', async () => {
        stubFetch({
            ok: false,
            status: 429,
            headers: new Headers({ 'Retry-After': '30' }),
            clone: () => ({
                json: () =>
                    Promise.resolve({
                        message: 'Too many attempts. Try again in 42 seconds.',
                        code: 'throttled',
                        retry_after: 42,
                        errors: { password: ['Too many attempts.'] },
                    }),
            }),
        });

        const error = (await kinetixFetch('/x', { method: 'POST' }).catch(
            (e: unknown) => e,
        )) as KinetixFetchError;

        expect(isKinetixFetchError(error)).toBe(true);
        expect(error.status).toBe(429);
        expect(error.code).toBe('throttled');
        // The payload wins over the header.
        expect(error.retryAfter).toBe(42);
        expect(error.errors).toEqual({ password: ['Too many attempts.'] });
        expect(isKinetixThrottled(error)).toBe(true);
        expect(kinetixFailureMessage(error, 'Failed')).toBe(
            'Too many attempts. Try again in 42 seconds.',
        );
    });

    it('falls back to the Retry-After header without a payload value', async () => {
        stubFetch({
            ok: false,
            status: 429,
            headers: new Headers({ 'Retry-After': '17' }),
            clone: () => ({ json: () => Promise.reject(new Error('html')) }),
        });

        const error = (await kinetixFetch('/x').catch(
            (e: unknown) => e,
        )) as KinetixFetchError;

        expect(error.retryAfter).toBe(17);
        expect(error.code).toBeUndefined();
    });

    it('keeps a surface fallback for anything but a throttle', async () => {
        stubFetch({
            ok: false,
            status: 500,
            clone: () => ({
                json: () => Promise.resolve({ message: 'Server Error' }),
            }),
        });

        const error = await kinetixFetch('/x').catch((e: unknown) => e);

        expect(isKinetixThrottled(error)).toBe(false);
        expect(kinetixFailureMessage(error, 'Failed')).toBe('Failed');
        expect(isKinetixFetchError(new Error('network'))).toBe(false);
        expect(
            isKinetixFetchError(new DOMException('stop', 'AbortError')),
        ).toBe(false);
    });
});
