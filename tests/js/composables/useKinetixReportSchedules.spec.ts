import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixReportSchedules } from '@/composables/useKinetixReportSchedules';

describe('useKinetixReportSchedules', () => {
    beforeEach(() => {
        fetchMock.mockReset();
    });

    it('loads the table from the report-schedules endpoint', async () => {
        fetchMock.mockResolvedValue({ records: [] });

        const { table, load } = useKinetixReportSchedules();
        await load();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/report-schedules');
        expect(table.value).toEqual({ records: [] });
    });

    it('create() posts the payload then reloads the table', async () => {
        fetchMock.mockResolvedValueOnce({ id: 1 }); // POST response
        fetchMock.mockResolvedValueOnce({ records: [{ id: 1 }] }); // reload

        const { table, create } = useKinetixReportSchedules();
        await create({ report: 'token-abc', frequency: 'daily' });

        expect(fetchMock).toHaveBeenNthCalledWith(
            1,
            '/_kinetix/report-schedules',
            {
                method: 'POST',
                body: { report: 'token-abc', frequency: 'daily' },
            },
        );
        expect(fetchMock).toHaveBeenNthCalledWith(
            2,
            '/_kinetix/report-schedules',
        );
        expect(table.value).toEqual({ records: [{ id: 1 }] });
    });
});
