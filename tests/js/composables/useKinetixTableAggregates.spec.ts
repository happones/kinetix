import { describe, expect, it, vi, beforeEach } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import { useKinetixTableAggregates } from '@/composables/useKinetixTableAggregates';

describe('useKinetixTableAggregates', () => {
    beforeEach(() => fetchMock.mockReset());

    it('seeds from inline values and does not fetch when not deferred', async () => {
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

        await agg.load();
        expect(fetchMock).not.toHaveBeenCalled();
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

        // Empty until loaded.
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
    });
});
