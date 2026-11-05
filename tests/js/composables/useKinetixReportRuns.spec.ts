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

    it('a missing hasActive (older server) keeps the full cadence', async () => {
        vi.useFakeTimers();
        state.props = { kinetix_reports_center: { enabled: true, poll: 1000 } };
        fetchMock.mockResolvedValue({ records: [] });

        const { start, stop, hasActive } = useKinetixReportRuns();
        start();
        await vi.advanceTimersByTimeAsync(3000);

        expect(hasActive.value).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(4);
        stop();
    });

    it('slows to once a minute while nothing is pending or running', async () => {
        vi.useFakeTimers();
        state.props = { kinetix_reports_center: { enabled: true, poll: 1000 } };
        fetchMock.mockResolvedValue({ records: [], hasActive: false });

        const { start, stop, hasActive, table } = useKinetixReportRuns();
        start();
        await vi.advanceTimersByTimeAsync(0);

        expect(hasActive.value).toBe(false);
        expect(table.value).toEqual({ records: [] });

        await vi.advanceTimersByTimeAsync(59_999);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        stop();
    });

    it('kinetix:report-runs-changed refreshes at once and keeps full speed for two ticks', async () => {
        vi.useFakeTimers();
        state.props = { kinetix_reports_center: { enabled: true, poll: 1000 } };
        fetchMock.mockResolvedValue({ records: [], hasActive: false });

        const { start, stop } = useKinetixReportRuns();
        start();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        window.dispatchEvent(new CustomEvent('kinetix:report-runs-changed'));
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        // Two grace ticks at the full cadence, then back to idle.
        await vi.advanceTimersByTimeAsync(1000);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(1000);
        expect(fetchMock).toHaveBeenCalledTimes(4);
        await vi.advanceTimersByTimeAsync(59_999);
        expect(fetchMock).toHaveBeenCalledTimes(4);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledTimes(5);

        stop();
        window.dispatchEvent(new CustomEvent('kinetix:report-runs-changed'));
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('a poll <= 0 still disables polling', async () => {
        vi.useFakeTimers();
        state.props = { kinetix_reports_center: { enabled: true, poll: 0 } };
        fetchMock.mockResolvedValue({ records: [], hasActive: false });

        const { start, stop } = useKinetixReportRuns();
        start();
        await vi.advanceTimersByTimeAsync(120_000);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        stop();
    });

    it('keeps the same table object when the payload did not change', async () => {
        fetchMock.mockResolvedValue({ records: [{ id: 1 }], hasActive: true });

        const { table, load } = useKinetixReportRuns();
        await load();
        const first = table.value;

        fetchMock.mockResolvedValue({ records: [{ id: 1 }], hasActive: true });
        await load();
        expect(table.value).toBe(first);

        fetchMock.mockResolvedValue({ records: [{ id: 2 }], hasActive: true });
        await load();
        expect(table.value).not.toBe(first);
        expect(table.value).toEqual({ records: [{ id: 2 }] });
    });
});
