import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useKinetixPolling } from '@/composables/useKinetixPolling';

function setHidden(hidden: boolean): void {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    document.dispatchEvent(new Event('visibilitychange'));
}

describe('useKinetixPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        setHidden(false);
    });

    afterEach(() => {
        setHidden(false);
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('runs once on start, then every interval counted from the end of a run', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        expect(task).toHaveBeenCalledTimes(1);
        expect(polling.active.value).toBe(true);

        await vi.advanceTimersByTimeAsync(999);
        expect(task).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(task).toHaveBeenCalledTimes(2);

        polling.stop();
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(2);
        expect(polling.active.value).toBe(false);
    });

    it('immediate: false only arms the first countdown', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, {
            interval: 1000,
            immediate: false,
        });

        polling.start();
        expect(task).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(2);
        polling.stop();
    });

    it('a run that never settles counts as finished after `timeout`', async () => {
        const task = vi.fn(() => new Promise<void>(() => {}));
        const polling = useKinetixPolling(task, {
            interval: 500,
            timeout: 1000,
        });

        polling.start();
        await vi.advanceTimersByTimeAsync(1499);
        expect(task).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(task).toHaveBeenCalledTimes(2);
        polling.stop();
    });

    it('waits indefinitely for a run without a timeout', async () => {
        const task = vi.fn(() => new Promise<void>(() => {}));
        const polling = useKinetixPolling(task, { interval: 500 });

        polling.start();
        await vi.advanceTimersByTimeAsync(60_000);

        expect(task).toHaveBeenCalledTimes(1);
        polling.stop();
    });

    it('start() is idempotent', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        polling.start();
        await vi.advanceTimersByTimeAsync(1000);

        expect(task).toHaveBeenCalledTimes(2);
        polling.stop();
    });

    it('never overlaps a slow run: the next one waits for the previous to finish', async () => {
        let running = 0;
        let maxRunning = 0;
        const task = vi.fn(async () => {
            running++;
            maxRunning = Math.max(maxRunning, running);
            await new Promise((resolve) => setTimeout(resolve, 3000));
            running--;
        });
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        await vi.advanceTimersByTimeAsync(3000);
        expect(task).toHaveBeenCalledTimes(1);

        // The countdown starts when the slow run ends.
        await vi.advanceTimersByTimeAsync(999);
        expect(task).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(task).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(maxRunning).toBe(1);
        polling.stop();
    });

    it('refresh() joins an in-flight run instead of starting another', async () => {
        let resolveRun: () => void = () => {};
        const task = vi.fn(
            () =>
                new Promise<void>((resolve) => {
                    resolveRun = resolve;
                }),
        );
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        const joined = polling.refresh();
        expect(task).toHaveBeenCalledTimes(1);

        resolveRun();
        await joined;
        polling.stop();
    });

    it('refresh() works while stopped and never schedules', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        await polling.refresh();
        expect(task).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(1);
    });

    it('refresh() restarts the countdown', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        await vi.advanceTimersByTimeAsync(800);
        await polling.refresh();
        expect(task).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(999);
        expect(task).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(task).toHaveBeenCalledTimes(3);
        polling.stop();
    });

    it('keeps polling after a run throws or rejects', async () => {
        const task = vi
            .fn()
            .mockImplementationOnce(() => {
                throw new Error('sync');
            })
            .mockRejectedValueOnce(new Error('async'))
            .mockResolvedValue(undefined);
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        await vi.advanceTimersByTimeAsync(2000);

        expect(task).toHaveBeenCalledTimes(3);
        polling.stop();
    });

    it('pauses while the tab is hidden and refreshes once visible again', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(task).toHaveBeenCalledTimes(1);

        setHidden(true);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(task).toHaveBeenCalledTimes(1);

        setHidden(false);
        expect(task).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(3);
        polling.stop();
    });

    it('a run that ends while hidden does not schedule another', async () => {
        let resolveRun: () => void = () => {};
        const task = vi.fn(
            () =>
                new Promise<void>((resolve) => {
                    resolveRun = resolve;
                }),
        );
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        setHidden(true);
        resolveRun();
        await vi.advanceTimersByTimeAsync(10_000);

        expect(task).toHaveBeenCalledTimes(1);
        polling.stop();
    });

    it('keeps the timer while hidden when pauseWhenHidden is off', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, {
            interval: 1000,
            pauseWhenHidden: false,
            refreshOnVisible: false,
        });

        polling.start();
        setHidden(true);
        await vi.advanceTimersByTimeAsync(2000);

        expect(task).toHaveBeenCalledTimes(3);
        polling.stop();
    });

    it('re-reads an interval getter before every schedule', async () => {
        let interval = 1000;
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: () => interval });

        polling.start();
        await vi.advanceTimersByTimeAsync(0);
        // The first countdown is armed at 1000 ms; the next one reads 5000.
        interval = 5000;
        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(4999);
        expect(task).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1);
        expect(task).toHaveBeenCalledTimes(3);
        polling.stop();
    });

    it('an interval <= 0 runs once and never schedules', async () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 0 });

        const before = vi.getTimerCount();

        polling.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(vi.getTimerCount()).toBe(before);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(task).toHaveBeenCalledTimes(1);
        polling.stop();
    });

    it('stop() removes the visibility listener', () => {
        const add = vi.spyOn(document, 'addEventListener');
        const remove = vi.spyOn(document, 'removeEventListener');
        const polling = useKinetixPolling(vi.fn(), { interval: 1000 });

        polling.start();
        expect(add).toHaveBeenCalledWith(
            'visibilitychange',
            expect.any(Function),
        );

        polling.stop();
        expect(remove).toHaveBeenCalledWith(
            'visibilitychange',
            add.mock.calls.find(([type]) => type === 'visibilitychange')![1],
        );
    });

    it('stops when the owning scope is disposed', async () => {
        const task = vi.fn();
        const remove = vi.spyOn(document, 'removeEventListener');
        const scope = effectScope();
        const polling = scope.run(() =>
            useKinetixPolling(task, { interval: 1000 }),
        )!;

        polling.start();
        scope.stop();
        await vi.advanceTimersByTimeAsync(5000);

        expect(task).toHaveBeenCalledTimes(1);
        expect(polling.active.value).toBe(false);
        expect(remove).toHaveBeenCalledWith(
            'visibilitychange',
            expect.any(Function),
        );
    });

    it('does not react to visibility while stopped', () => {
        const task = vi.fn();
        const polling = useKinetixPolling(task, { interval: 1000 });

        polling.start();
        polling.stop();
        setHidden(true);
        setHidden(false);

        expect(task).toHaveBeenCalledTimes(1);
    });
});
