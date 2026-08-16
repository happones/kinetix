import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();
const pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/composables/useKinetixHttp', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/composables/useKinetixHttp')>()),
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixSpotlight } from '@/composables/useKinetixSpotlight';
import type { KinetixSpotlightGroup } from '@/types/kinetix';

/** A promise whose settling this test controls, to stage the race. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });

    return { promise, resolve };
}

const group = (label: string): KinetixSpotlightGroup =>
    ({ label, items: [] }) as KinetixSpotlightGroup;

describe('useKinetixSpotlight', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        pageProps.kinetix_config = { route_prefix: '_kinetix' };
    });

    it('reads the minimum query length from the shared config', () => {
        pageProps.kinetix_config = { spotlight: { min_chars: 3 } };
        expect(useKinetixSpotlight().minChars.value).toBe(3);
    });

    it('falls back to two characters when the host shares no config', () => {
        pageProps.kinetix_config = undefined;
        expect(useKinetixSpotlight().minChars.value).toBe(2);
    });

    it('queries the endpoint with the encoded term', async () => {
        fetchMock.mockResolvedValue({ groups: [group('People')] });

        const groups = await useKinetixSpotlight().search('a b&c');

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/_kinetix/spotlight?q=a%20b%26c',
        );
        expect(groups).toEqual([group('People')]);
    });

    it('aborts the request in flight when a newer one starts', () => {
        fetchMock.mockReturnValue(deferred().promise);

        const spotlight = useKinetixSpotlight();

        spotlight.search('emp');
        const first = fetchMock.mock.calls[0][1].signal as AbortSignal;

        expect(first.aborted).toBe(false);

        spotlight.search('empleado');

        expect(first.aborted).toBe(true);
    });

    it('discards a superseded search instead of painting it', async () => {
        const stale = deferred<{ groups: KinetixSpotlightGroup[] }>();
        const fresh = deferred<{ groups: KinetixSpotlightGroup[] }>();

        fetchMock
            .mockReturnValueOnce(stale.promise)
            .mockReturnValueOnce(fresh.promise);

        const spotlight = useKinetixSpotlight();
        const first = spotlight.search('emp');
        const second = spotlight.search('empleado');

        // The stale response lands LAST — the exact ordering that used to let
        // it overwrite results for a query the reader had already replaced.
        fresh.resolve({ groups: [group('People')] });
        stale.resolve({ groups: [group('Stale')] });

        expect(await second).toEqual([group('People')]);
        expect(await first).toBeNull();
    });

    it('treats an aborted request as superseded, not as a failure', async () => {
        fetchMock.mockRejectedValue(new DOMException('stop', 'AbortError'));

        await expect(useKinetixSpotlight().search('emp')).resolves.toBeNull();
    });

    it('still surfaces a real failure', async () => {
        fetchMock.mockRejectedValue(new Error('HTTP error! status: 429'));

        await expect(useKinetixSpotlight().search('emp')).rejects.toThrow(
            '429',
        );
    });

    it('leaves the spinner on while the newest request is still running', async () => {
        const stale = deferred<{ groups: KinetixSpotlightGroup[] }>();
        const fresh = deferred<{ groups: KinetixSpotlightGroup[] }>();

        fetchMock
            .mockReturnValueOnce(stale.promise)
            .mockReturnValueOnce(fresh.promise);

        const spotlight = useKinetixSpotlight();
        const first = spotlight.search('emp');
        const second = spotlight.search('empleado');

        stale.resolve({ groups: [] });
        await first;

        // A superseded request switching the spinner off would hide the search
        // that is actually still in flight.
        expect(spotlight.loading.value).toBe(true);

        fresh.resolve({ groups: [] });
        await second;

        expect(spotlight.loading.value).toBe(false);
    });
});
