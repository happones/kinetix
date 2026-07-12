import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();
const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixReportRuns } from '@/composables/useKinetixReportRuns';

describe('useKinetixReportRuns', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        state.props = {};
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('loads the table from the report-runs endpoint', async () => {
        fetchMock.mockResolvedValue({ records: [] });

        const { table, load } = useKinetixReportRuns();
        await load();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/report-runs');
        expect(table.value).toEqual({ records: [] });
    });

    it('sets failed when the fetch rejects', async () => {
        fetchMock.mockRejectedValue(new Error('network error'));

        const { failed, load } = useKinetixReportRuns();
        await load();

        expect(failed.value).toBe(true);
    });

    it('start() loads immediately and then polls on the configured interval', async () => {
        vi.useFakeTimers();
        state.props = { kinetix_reports_center: { enabled: true, poll: 1000 } };
        fetchMock.mockResolvedValue({ records: [] });

        const { start, stop } = useKinetixReportRuns();
        start();
        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1000);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        stop();
        await vi.advanceTimersByTimeAsync(2000);
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
