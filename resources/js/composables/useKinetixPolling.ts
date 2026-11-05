import { getCurrentScope, onScopeDispose, readonly, ref } from 'vue';
import type { Ref } from 'vue';

export interface UseKinetixPollingOptions {
    /** ms from the END of one run to the next; a getter is re-read before every schedule. <= 0: no timer. */
    interval: number | (() => number);
    /** Clear the timer while the tab is hidden. Default true. */
    pauseWhenHidden?: boolean;
    /** Run once as soon as the tab becomes visible again (only while started). Default true. */
    refreshOnVisible?: boolean;
    /**
     * Run once when started. Default true; off, start() only arms the first
     * countdown (for data the page props already delivered).
     */
    immediate?: boolean;
    /**
     * ms after which a run that never settles counts as finished, so one lost
     * request can't stop the loop for good. `0` (default) waits indefinitely.
     */
    timeout?: number;
}

export interface UseKinetixPolling {
    /** Runs once now (unless `immediate: false`), then schedules; idempotent; no-op on the server. */
    start(): void;
    stop(): void;
    /** Runs now, joins an in-flight run, restarts the countdown; works while stopped. */
    refresh(): Promise<void>;
    readonly active: Readonly<Ref<boolean>>;
}

const noop = (): void => {};

/**
 * The one polling loop every Kinetix poll goes through: a chained timeout
 * counted from the END of the previous run (so a slow request never overlaps
 * the next one), paused while the tab is hidden and refreshed when it comes
 * back. A failing run never stops the schedule; the task handles its own
 * error state. Stops itself when the owning effect scope (component) is
 * disposed.
 */
export function useKinetixPolling(
    task: () => unknown | Promise<unknown>,
    options: UseKinetixPollingOptions,
): UseKinetixPolling {
    const pauseWhenHidden = options.pauseWhenHidden ?? true;
    const refreshOnVisible = options.refreshOnVisible ?? true;
    const immediate = options.immediate ?? true;
    const timeout = options.timeout ?? 0;

    const active = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let inFlight: Promise<void> | null = null;
    let listening = false;

    const isHidden = (): boolean =>
        typeof document !== 'undefined' && document.hidden === true;

    const intervalNow = (): number =>
        typeof options.interval === 'function'
            ? options.interval()
            : options.interval;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function schedule(): void {
        clearTimer();

        if (!active.value || (pauseWhenHidden && isHidden())) {
            return;
        }

        const delay = intervalNow();

        if (!(delay > 0)) {
            return;
        }

        timer = setTimeout(() => {
            timer = null;
            void run();
        }, delay);
    }

    function run(): Promise<void> {
        if (inFlight !== null) {
            return inFlight;
        }

        clearTimer();

        let result: unknown;

        try {
            result = task();
        } catch {
            result = undefined;
        }

        const settled = Promise.resolve(result).then(noop, noop);
        let runTimer: ReturnType<typeof setTimeout> | null = null;
        const guarded =
            timeout > 0
                ? Promise.race([
                      settled,
                      new Promise<void>((resolve) => {
                          runTimer = setTimeout(resolve, timeout);
                      }),
                  ])
                : settled;

        const current = guarded.then(() => {
            if (runTimer !== null) {
                clearTimeout(runTimer);
            }

            inFlight = null;
            schedule();
        });

        inFlight = current;

        return current;
    }

    function onVisibilityChange(): void {
        if (!active.value) {
            return;
        }

        if (isHidden()) {
            if (pauseWhenHidden) {
                clearTimer();
            }

            return;
        }

        if (refreshOnVisible) {
            void run();
        } else if (timer === null && inFlight === null) {
            schedule();
        }
    }

    function start(): void {
        if (
            active.value ||
            typeof window === 'undefined' ||
            typeof document === 'undefined'
        ) {
            return;
        }

        active.value = true;

        if (!listening && (pauseWhenHidden || refreshOnVisible)) {
            document.addEventListener('visibilitychange', onVisibilityChange);
            listening = true;
        }

        if (immediate) {
            void run();
        } else {
            schedule();
        }
    }

    function stop(): void {
        active.value = false;
        clearTimer();

        if (listening) {
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            listening = false;
        }
    }

    if (getCurrentScope()) {
        onScopeDispose(stop);
    }

    return { start, stop, refresh: run, active: readonly(active) };
}
