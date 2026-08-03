import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useKinetixPrecognition } from '@/composables/useKinetixPrecognition';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

function stubFetch(response: Partial<Response> & { json?: () => any }) {
    const fetchMock = vi.fn().mockResolvedValue({
        status: 204,
        json: () => Promise.resolve({}),
        ...response,
    });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

const flush = async () => {
    await vi.runAllTimersAsync();
};

describe('useKinetixPrecognition', () => {
    it('sends the precognition headers, XSRF token and scoped field', async () => {
        vi.useFakeTimers();
        document.cookie = 'XSRF-TOKEN=tok';
        const fetchMock = stubFetch({ status: 204 });

        const precog = useKinetixPrecognition({
            url: '/posts',
            method: 'post',
            getData: () => ({ title: 'x', body: 'y' }),
        });

        precog.validate('title');
        await flush();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/posts');
        expect(init.method).toBe('POST');
        expect(init.headers.Precognition).toBe('true');
        expect(init.headers['Precognition-Validate-Only']).toBe('title');
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok');
        expect(JSON.parse(init.body)).toEqual({ title: 'x', body: 'y' });
    });

    it('method-spoofs put/patch over POST', async () => {
        vi.useFakeTimers();
        const fetchMock = stubFetch({ status: 204 });

        const precog = useKinetixPrecognition({
            url: '/posts/1',
            method: 'put',
            getData: () => ({ title: 'x' }),
        });

        precog.validate('title');
        await flush();

        const [, init] = fetchMock.mock.calls[0];
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)._method).toBe('PUT');
    });

    it('maps a 422 to the field error and clears it on success', async () => {
        vi.useFakeTimers();
        const fetchMock = stubFetch({
            status: 422,
            json: () =>
                Promise.resolve({
                    errors: { title: ['The title is required.'] },
                }),
        });

        const precog = useKinetixPrecognition({
            url: '/posts',
            getData: () => ({ title: '' }),
        });

        precog.validate('title');
        await flush();
        expect(precog.errors.value.title).toBe('The title is required.');

        // Next round passes → the error is cleared.
        fetchMock.mockResolvedValue({ status: 204, json: () => ({}) });
        precog.validate('title');
        await flush();
        expect(precog.errors.value.title).toBeUndefined();
    });

    it('only surfaces the fields it asked about', async () => {
        vi.useFakeTimers();
        stubFetch({
            status: 422,
            json: () =>
                Promise.resolve({
                    errors: {
                        title: ['required'],
                        body: ['required'],
                    },
                }),
        });

        const precog = useKinetixPrecognition({
            url: '/posts',
            getData: () => ({ title: '', body: '' }),
        });

        precog.validate('title');
        await flush();

        expect(precog.errors.value.title).toBe('required');
        // `body` was not requested, so its error is not surfaced.
        expect(precog.errors.value.body).toBeUndefined();
    });

    it('cancels a pending validation when its owning scope is disposed', async () => {
        vi.useFakeTimers();
        const fetchMock = stubFetch({ status: 204 });

        const scope = effectScope();
        let precog!: ReturnType<typeof useKinetixPrecognition>;
        scope.run(() => {
            precog = useKinetixPrecognition({
                url: '/posts',
                getData: () => ({ title: 'x' }),
            });
        });

        precog.validate('title');
        scope.stop();
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('debounces repeated calls for the same field into one request', async () => {
        vi.useFakeTimers();
        const fetchMock = stubFetch({ status: 204 });

        const precog = useKinetixPrecognition({
            url: '/posts',
            getData: () => ({ title: 'x' }),
            timeout: 200,
        });

        precog.validate('title');
        precog.validate('title');
        precog.validate('title');
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
