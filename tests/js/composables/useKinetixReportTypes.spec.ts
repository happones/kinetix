import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixReportTypes } from '@/composables/useKinetixReportTypes';

describe('useKinetixReportTypes', () => {
    beforeEach(() => {
        fetchMock.mockReset();
    });

    it('loads the registered report types', async () => {
        fetchMock.mockResolvedValue([
            {
                token: 'tok1',
                label: 'Orders',
                description: null,
                format: 'csv',
            },
        ]);

        const { types, load } = useKinetixReportTypes();
        await load();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/report-types');
        expect(types.value).toHaveLength(1);
        expect(types.value[0].label).toBe('Orders');
    });

    it('defaults to an empty array when the endpoint returns null', async () => {
        fetchMock.mockResolvedValue(null);

        const { types, load } = useKinetixReportTypes();
        await load();

        expect(types.value).toEqual([]);
    });

    it('sets failed when the fetch rejects', async () => {
        fetchMock.mockRejectedValue(new Error('network error'));

        const { failed, load } = useKinetixReportTypes();
        await load();

        expect(failed.value).toBe(true);
    });

    it('launch() posts the token and parameters', async () => {
        fetchMock.mockResolvedValue({ status: 'queued', run_id: 5 });

        const { launch } = useKinetixReportTypes();
        const result = await launch('tok1', { foo: 'bar' });

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/report-runs/launch', {
            method: 'POST',
            body: { report: 'tok1', parameters: { foo: 'bar' } },
        });
        expect(result).toEqual({ status: 'queued', run_id: 5 });
    });
});
