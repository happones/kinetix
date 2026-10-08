import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ref } from 'vue';

const fetchMock = vi.fn();

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: (error: unknown) =>
        error instanceof DOMException && error.name === 'AbortError',
}));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import { useKinetixTableAggregates } from '@/composables/useKinetixTableAggregates';

const aborted = () => new DOMException('aborted', 'AbortError');

/** A fetch that resolves when told to, and rejects like the real one on abort. */
const deferredFetch = () => {
    let resolve!: (value: unknown) => void;

    fetchMock.mockImplementationOnce(
        (_url: string, opts: { signal: AbortSignal }) =>
            new Promise((res, rej) => {
                resolve = res;
                opts.signal.addEventListener('abort', () => rej(aborted()));
            }),
    );

    return (value: unknown) => resolve(value);
};

describe('useKinetixTableAggregates', () => {
    beforeEach(() => fetchMock.mockReset());

    it('reads inline values and does not fetch when not deferred', async () => {
        const agg = useKinetixTableAggregates({
            descriptor: () => null,
            initial: () => ({
                stats: [{ label: 'Total', value: '3' } as any],
                summaries: { price: [{ value: '10' } as any] },
                hasSummaries: true,
            }),
        });

        expect(agg.stats.value).toHaveLength(1);
        expect(agg.hasSummaries.value).toBe(true);
        expect(agg.loading.value).toBe(false);

        await agg.load();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('follows the inline values when the table prop changes', () => {
        const source = ref({
            stats: [{ label: 'Total', value: '3' } as any],
            summaries: { price: [{ value: '10' } as any] },
            hasSummaries: true,
        });
        const agg = useKinetixTableAggregates({
            descriptor: () => null,
            initial: () => source.value,
        });

        source.value = {
            stats: [{ label: 'Total', value: '1' } as any],
            summaries: { price: [{ value: '4' } as any] },
            hasSummaries: true,
        };

        expect(agg.stats.value[0].value).toBe('1');
        expect(agg.summaries.value.price[0].value).toBe('4');
    });

    it('fetches and applies the deferred aggregates', async () => {
        fetchMock.mockResolvedValue({
            stats: [{ label: 'Total', value: '42' }],
            summaries: { price: [{ value: '99' }] },
            hasSummaries: true,
        });

        const agg = useKinetixTableAggregates({
            descriptor: () => 'signed-token',
            initial: () => ({ stats: [], summaries: {}, hasSummaries: false }),
        });

        // The skeleton shows from the first paint.
        expect(agg.loading.value).toBe(true);
        expect(agg.stats.value).toEqual([]);
        expect(agg.hasSummaries.value).toBe(false);

        await agg.load();

        const [url, opts] = fetchMock.mock.calls[0] as [string, any];
        expect(url).toContain('/_kinetix/tables/aggregates');
        expect(opts.body.descriptor).toBe('signed-token');
        expect(agg.stats.value).toEqual([{ label: 'Total', value: '42' }]);
        expect(agg.summaries.value).toEqual({ price: [{ value: '99' }] });
        expect(agg.hasSummaries.value).toBe(true);
        expect(agg.loading.value).toBe(false);
        expect(agg.loaded.value).toBe(true);
    });

    it('a newer load aborts the one in flight, so stale totals never land', async () => {
        const agg = useKinetixTableAggregates({
            descriptor: () => 'signed-token',
            initial: () => ({ stats: [], summaries: {}, hasSummaries: false }),
        });

        const resolveFirst = deferredFetch();
        const first = agg.load();
        const resolveSecond = deferredFetch();
        const second = agg.load();

        resolveSecond({
            stats: [{ label: 'Total', value: 'filtered' }],
            summaries: {},
            hasSummaries: false,
        });
        await second;
        resolveFirst({
            stats: [{ label: 'Total', value: 'stale' }],
            summaries: {},
            hasSummaries: false,
        });
        await first;

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(agg.stats.value).toEqual([
            { label: 'Total', value: 'filtered' },
        ]);
        expect(agg.loading.value).toBe(false);
    });

    it('a failed fetch settles quietly and keeps what was shown', async () => {
        fetchMock.mockResolvedValueOnce({
            stats: [{ label: 'Total', value: '42' }],
            summaries: {},
            hasSummaries: false,
        });
        fetchMock.mockRejectedValueOnce(new Error('500'));

        const agg = useKinetixTableAggregates({
            descriptor: () => 'signed-token',
            initial: () => ({ stats: [], summaries: {}, hasSummaries: false }),
        });

        await agg.load();
        await expect(agg.load()).resolves.toBeUndefined();

        expect(agg.stats.value).toEqual([{ label: 'Total', value: '42' }]);
        expect(agg.loading.value).toBe(false);
    });
});
